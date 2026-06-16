//! 会话表（主库 `sessions`）数据访问（Task 5.2 / Requirements 2.2、2.6、2.7、2.9）。
//!
//! 本模块只访问**主库** `sessions` 表，承载三类主体（超管 / 公司管理员 / 员工）的服务端
//! 登录态与审计信息。所有查询统一用 SQLx 运行时 API（`query` / `query_as`，**不用**编译期
//! `query!` 宏），以与项目其余 repository 保持一致。
//!
//! 提供的能力：
//! - [`create_session`]：生成**高熵随机 token** 并插入一条会话记录（Requirements 2.2）。
//! - [`find_by_token`] / [`find_valid_by_token`]：按 token 查询会话（Requirements 2.6、2.7）。
//! - [`touch_last_seen`]：对**未过期**会话滑动续期 `last_seen_at`（Requirements 2.6）。
//! - [`revoke`]：按 token 吊销（删除）单条会话（Requirements 2.7）。
//! - [`revoke_all_for_principal`]：按主体吊销其全部会话（Requirements 2.9：停用租户/禁用员工）。
//! - [`Session::is_expired`]：纯逻辑的过期判定，便于无 DB 的单元测试。
//!
//! 关于 token：会话 token 必须高熵且不可预测。本模块用密码学安全随机源
//! （[`OsRng`]）生成 32 字节（256 bit）随机数据并十六进制编码为 64 字符字符串，
//! 远超暴力枚举可行性。`sessions.token` 列带 `UNIQUE` 约束，进一步保证全局唯一。

use sqlx::types::chrono::NaiveDateTime;
use sqlx::{FromRow, MySqlPool};

// 复用 argon2 已引入的 rand_core（password_hash 重导出），避免新增依赖。
// `RngCore` trait 提供 `fill_bytes`，`OsRng` 为操作系统提供的密码学安全随机源。
use argon2::password_hash::rand_core::{OsRng, RngCore};

use crate::error::AppError;

/// 随机 token 的原始字节数（256 bit 熵）。
const TOKEN_BYTES: usize = 32;

/// 主体类型（对应 `sessions.principal_kind`）。
///
/// 与 `design.md` 认证小节一致：`super_admin` / `company_admin` / `employee`。
/// 用枚举取代裸字符串，避免拼写漂移并便于穷举处理。
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum PrincipalKind {
    /// 超级管理员（主库 `admins.id`，`tenant_id` 为 NULL）。
    SuperAdmin,
    /// 公司管理员（租户 `users.id`）。
    CompanyAdmin,
    /// 普通员工（租户 `users.id`）。
    Employee,
}

impl PrincipalKind {
    /// 持久化用的字符串字面量。
    pub fn as_str(&self) -> &'static str {
        match self {
            PrincipalKind::SuperAdmin => "super_admin",
            PrincipalKind::CompanyAdmin => "company_admin",
            PrincipalKind::Employee => "employee",
        }
    }

    /// 从存储字符串解析；未知值返回 `None`。
    pub fn from_db_str(s: &str) -> Option<PrincipalKind> {
        match s {
            "super_admin" => Some(PrincipalKind::SuperAdmin),
            "company_admin" => Some(PrincipalKind::CompanyAdmin),
            "employee" => Some(PrincipalKind::Employee),
            _ => None,
        }
    }
}

/// 一条会话记录（与 `sessions` 表列一一对应）。
///
/// `created_at` / `last_seen_at` / `expires_at` 为 MySQL `DATETIME`，映射为
/// [`NaiveDateTime`]（无时区，按 UTC/服务器本地一致处理，与 `CURRENT_TIMESTAMP` 同源）。
#[derive(Debug, Clone, FromRow)]
pub struct Session {
    pub id: i64,
    pub principal_kind: String,
    pub principal_id: i64,
    pub tenant_id: Option<i64>,
    pub token: String,
    pub created_at: NaiveDateTime,
    pub last_seen_at: NaiveDateTime,
    pub expires_at: NaiveDateTime,
    pub ip: Option<String>,
    pub user_agent: Option<String>,
}

impl Session {
    /// 相对给定「当前时间」是否已过期。
    ///
    /// 语义：`now >= expires_at` 即过期。会话中间件（Task 5.5）据此对过期会话清 Cookie
    /// 并要求重新登录（Requirements 2.7）。「已吊销」表现为记录被删除、`find_by_token`
    /// 返回 `None`，因此吊销判定在查询层体现，不在本方法内。
    pub fn is_expired(&self, now: NaiveDateTime) -> bool {
        now >= self.expires_at
    }

    /// 解析主体类型枚举；存储值非法时返回 `None`。
    pub fn principal_kind_enum(&self) -> Option<PrincipalKind> {
        PrincipalKind::from_db_str(&self.principal_kind)
    }
}

/// 生成一个高熵随机会话 token（64 位十六进制字符，源自 32 字节 [`OsRng`] 随机数据）。
///
/// 采用操作系统密码学安全随机源；不同调用返回值在实践中各不相同且不可预测。
pub fn generate_token() -> String {
    let mut buf = [0u8; TOKEN_BYTES];
    OsRng.fill_bytes(&mut buf);
    to_hex(&buf)
}

/// 将字节切片编码为小写十六进制字符串。
fn to_hex(bytes: &[u8]) -> String {
    const HEX: &[u8; 16] = b"0123456789abcdef";
    let mut out = String::with_capacity(bytes.len() * 2);
    for &b in bytes {
        out.push(HEX[(b >> 4) as usize] as char);
        out.push(HEX[(b & 0x0f) as usize] as char);
    }
    out
}

/// 插入一条新会话并返回其高熵随机 token（Requirements 2.2）。
///
/// - `principal_kind` / `principal_id`：登录主体（超管→主库 `admins.id`；公司管理员/员工→租户
///   `users.id`）。
/// - `tenant_id`：员工/公司管理员所属租户；超管传 `None`。
/// - `expires_at`：会话过期时间（如「现在 + 7 天」，由调用方计算）。
/// - `ip` / `user_agent`：审计信息，可为 `None`。
///
/// `created_at` 与 `last_seen_at` 由表默认值 `CURRENT_TIMESTAMP` 写入。
/// token 由 [`generate_token`] 生成，列上的 `UNIQUE` 约束保证全局唯一。
///
/// # 后置条件
/// 成功时返回新会话的 token，可直接下发到安全 Cookie（Task 5.3）。
pub async fn create_session(
    master: &MySqlPool,
    principal_kind: PrincipalKind,
    principal_id: i64,
    tenant_id: Option<i64>,
    expires_at: NaiveDateTime,
    ip: Option<&str>,
    user_agent: Option<&str>,
) -> Result<String, AppError> {
    let token = generate_token();

    sqlx::query(
        "INSERT INTO `sessions` \
         (`principal_kind`, `principal_id`, `tenant_id`, `token`, `expires_at`, `ip`, `user_agent`) \
         VALUES (?, ?, ?, ?, ?, ?, ?)",
    )
    .bind(principal_kind.as_str())
    .bind(principal_id)
    .bind(tenant_id)
    .bind(&token)
    .bind(expires_at)
    .bind(ip)
    .bind(user_agent)
    .execute(master)
    .await?;

    Ok(token)
}

/// 按 token 查询会话（不论是否过期）（Requirements 2.6）。
///
/// 未找到（含已被吊销/删除）返回 `Ok(None)`。是否过期由调用方用
/// [`Session::is_expired`] 判定，以便区分「无此 token」与「已过期」两种情形。
pub async fn find_by_token(master: &MySqlPool, token: &str) -> Result<Option<Session>, AppError> {
    let session = sqlx::query_as::<_, Session>(
        "SELECT `id`, `principal_kind`, `principal_id`, `tenant_id`, `token`, \
         `created_at`, `last_seen_at`, `expires_at`, `ip`, `user_agent` \
         FROM `sessions` WHERE `token` = ?",
    )
    .bind(token)
    .fetch_optional(master)
    .await?;

    Ok(session)
}

/// 按 token 查询**仍然有效（未过期）**的会话（Requirements 2.6、2.7）。
///
/// 过期或不存在/已吊销一律返回 `Ok(None)`，等价于「需要重新登录」。
/// 过期判定在 SQL 内用 `expires_at > CURRENT_TIMESTAMP` 完成，与数据库时钟一致。
pub async fn find_valid_by_token(
    master: &MySqlPool,
    token: &str,
) -> Result<Option<Session>, AppError> {
    let session = sqlx::query_as::<_, Session>(
        "SELECT `id`, `principal_kind`, `principal_id`, `tenant_id`, `token`, \
         `created_at`, `last_seen_at`, `expires_at`, `ip`, `user_agent` \
         FROM `sessions` WHERE `token` = ? AND `expires_at` > CURRENT_TIMESTAMP",
    )
    .bind(token)
    .fetch_optional(master)
    .await?;

    Ok(session)
}

/// 对**未过期**会话滑动续期：刷新 `last_seen_at` 为当前时间（Requirements 2.6）。
///
/// 仅当 token 存在且尚未过期时才更新；已过期/不存在的会话不更新。
///
/// # 返回
/// `Ok(true)` 表示命中并续期了一条有效会话；`Ok(false)` 表示无有效会话可续期
/// （token 无效、已过期或已被吊销）。
pub async fn touch_last_seen(master: &MySqlPool, token: &str) -> Result<bool, AppError> {
    let result = sqlx::query(
        "UPDATE `sessions` SET `last_seen_at` = CURRENT_TIMESTAMP \
         WHERE `token` = ? AND `expires_at` > CURRENT_TIMESTAMP",
    )
    .bind(token)
    .execute(master)
    .await?;

    Ok(result.rows_affected() > 0)
}

/// 吊销（删除）单条会话（Requirements 2.7）。
///
/// 用于显式登出，或会话中间件发现 token 无效/过期时清理。
///
/// # 返回
/// `Ok(true)` 表示确有一条会话被删除；`Ok(false)` 表示该 token 本就不存在。
pub async fn revoke(master: &MySqlPool, token: &str) -> Result<bool, AppError> {
    let result = sqlx::query("DELETE FROM `sessions` WHERE `token` = ?")
        .bind(token)
        .execute(master)
        .await?;

    Ok(result.rows_affected() > 0)
}

/// 按主体吊销其**全部**会话（Requirements 2.9）。
///
/// 触发场景：超管停用某租户、或公司管理员禁用某员工时，须立即使该主体所有登录态失效。
///
/// # 返回
/// 被删除的会话条数。
pub async fn revoke_all_for_principal(
    master: &MySqlPool,
    principal_kind: PrincipalKind,
    principal_id: i64,
) -> Result<u64, AppError> {
    let result =
        sqlx::query("DELETE FROM `sessions` WHERE `principal_kind` = ? AND `principal_id` = ?")
            .bind(principal_kind.as_str())
            .bind(principal_id)
            .execute(master)
            .await?;

    Ok(result.rows_affected())
}

/// 按租户 + 主体吊销会话。
///
/// 租户库 `users.id` 是每个租户独立自增的，不能只按 `principal_id` 删除员工会话，否则不同
/// 租户中相同 id 的员工会被误踢下线。本函数用于租户侧员工禁用场景。
pub async fn revoke_all_for_tenant_principal(
    master: &MySqlPool,
    tenant_id: i64,
    principal_kind: PrincipalKind,
    principal_id: i64,
) -> Result<u64, AppError> {
    let result = sqlx::query(
        "DELETE FROM `sessions` \
         WHERE `tenant_id` = ? AND `principal_kind` = ? AND `principal_id` = ?",
    )
    .bind(tenant_id)
    .bind(principal_kind.as_str())
    .bind(principal_id)
    .execute(master)
    .await?;

    Ok(result.rows_affected())
}

#[cfg(test)]
mod tests {
    use super::*;
    use sqlx::types::chrono::NaiveDate;
    use std::collections::HashSet;

    fn dt(y: i32, mo: u32, d: u32, h: u32, mi: u32, s: u32) -> NaiveDateTime {
        NaiveDate::from_ymd_opt(y, mo, d)
            .unwrap()
            .and_hms_opt(h, mi, s)
            .unwrap()
    }

    #[test]
    fn token_has_expected_hex_length_and_charset() {
        let token = generate_token();
        // 32 字节 → 64 个十六进制字符。
        assert_eq!(token.len(), TOKEN_BYTES * 2);
        assert!(
            token
                .chars()
                .all(|c| c.is_ascii_hexdigit() && !c.is_ascii_uppercase()),
            "token 应为小写十六进制: {token}"
        );
    }

    #[test]
    fn tokens_are_unique_across_many_generations() {
        // 高熵随机源在大量生成下几乎不可能碰撞；此处用集合验证无重复。
        let n = 10_000;
        let mut seen = HashSet::with_capacity(n);
        for _ in 0..n {
            let token = generate_token();
            assert!(seen.insert(token), "生成的 token 出现重复，熵不足");
        }
        assert_eq!(seen.len(), n);
    }

    #[test]
    fn to_hex_encodes_known_bytes() {
        assert_eq!(to_hex(&[0x00, 0x0f, 0xff, 0xa5]), "000fffa5");
        assert_eq!(to_hex(&[]), "");
    }

    #[test]
    fn principal_kind_round_trips_through_db_string() {
        for k in [
            PrincipalKind::SuperAdmin,
            PrincipalKind::CompanyAdmin,
            PrincipalKind::Employee,
        ] {
            assert_eq!(PrincipalKind::from_db_str(k.as_str()), Some(k));
        }
        assert_eq!(PrincipalKind::from_db_str("unknown"), None);
    }

    #[test]
    fn is_expired_compares_against_expires_at() {
        let session = Session {
            id: 1,
            principal_kind: "employee".into(),
            principal_id: 42,
            tenant_id: Some(7),
            token: "deadbeef".into(),
            created_at: dt(2026, 1, 1, 0, 0, 0),
            last_seen_at: dt(2026, 1, 1, 0, 0, 0),
            expires_at: dt(2026, 1, 8, 0, 0, 0),
            ip: None,
            user_agent: None,
        };

        // 过期时间之前 → 未过期。
        assert!(!session.is_expired(dt(2026, 1, 7, 23, 59, 59)));
        // 恰好等于过期时间 → 视为过期（now >= expires_at）。
        assert!(session.is_expired(dt(2026, 1, 8, 0, 0, 0)));
        // 过期之后 → 过期。
        assert!(session.is_expired(dt(2026, 1, 9, 0, 0, 0)));
    }

    #[test]
    fn session_principal_kind_enum_parses_stored_value() {
        let mut session = Session {
            id: 1,
            principal_kind: "super_admin".into(),
            principal_id: 1,
            tenant_id: None,
            token: "t".into(),
            created_at: dt(2026, 1, 1, 0, 0, 0),
            last_seen_at: dt(2026, 1, 1, 0, 0, 0),
            expires_at: dt(2026, 1, 8, 0, 0, 0),
            ip: None,
            user_agent: None,
        };
        assert_eq!(
            session.principal_kind_enum(),
            Some(PrincipalKind::SuperAdmin)
        );

        session.principal_kind = "garbage".into();
        assert_eq!(session.principal_kind_enum(), None);
    }
}

// ============================================================================
// Task 5.6 集成测试（需真实 MySQL，默认 `#[ignore]`）。
// ----------------------------------------------------------------------------
// 本环境无实时 MySQL，故以下端到端会话流程标注 `#[ignore]`：仅在设置了主库 DSN 环境变量
// `XIZHENDS_TEST_MASTER_DSN`（指向已套用 `migrations/master` schema 的库）并以
// `cargo test -- --ignored` 运行时执行。它们文档化并验证 DB 往返语义：
//   - 建会话 → find_valid_by_token 命中（Req 2.2、2.6）。
//   - touch_last_seen 对有效会话续期、对无效会话不续期（Req 2.6）。
//   - revoke 后 find_valid_by_token 返回 None（登出/吊销被拒，Req 2.7）。
//   - 过期会话 find_valid_by_token 返回 None（过期被拒，Req 2.7）。
//   - revoke_all_for_principal 吊销某主体全部会话（停用租户/禁用员工，Req 2.9）。
// 纯逻辑部分（token 熵、is_expired、principal_kind 往返）已由上方无 DB 单元测试覆盖。
// ============================================================================
#[cfg(test)]
mod db_integration_tests {
    use super::*;
    use sqlx::types::chrono::NaiveDate;
    use sqlx::MySqlPool;

    /// 读取测试主库 DSN；未配置则跳过（这些测试默认 `#[ignore]`）。
    async fn master_pool() -> Option<MySqlPool> {
        let dsn = std::env::var("XIZHENDS_TEST_MASTER_DSN").ok()?;
        MySqlPool::connect(&dsn).await.ok()
    }

    fn future_dt() -> NaiveDateTime {
        NaiveDate::from_ymd_opt(2999, 1, 1)
            .unwrap()
            .and_hms_opt(0, 0, 0)
            .unwrap()
    }

    fn past_dt() -> NaiveDateTime {
        NaiveDate::from_ymd_opt(2000, 1, 1)
            .unwrap()
            .and_hms_opt(0, 0, 0)
            .unwrap()
    }

    #[tokio::test]
    #[ignore = "需真实 MySQL：设置 XIZHENDS_TEST_MASTER_DSN 并以 --ignored 运行"]
    async fn create_find_then_revoke_round_trip() {
        let Some(pool) = master_pool().await else {
            return;
        };

        // 建会话 → 可查到有效会话（Req 2.2、2.6）。
        let token = create_session(
            &pool,
            PrincipalKind::Employee,
            9_000_001,
            Some(424242),
            future_dt(),
            Some("127.0.0.1"),
            Some("integration-test"),
        )
        .await
        .expect("create_session");

        let found = find_valid_by_token(&pool, &token).await.expect("query");
        assert!(found.is_some(), "新建会话应可查到");

        // 续期命中有效会话。
        assert!(touch_last_seen(&pool, &token).await.expect("touch"));

        // 登出/吊销：revoke 后不再可查（Req 2.7）。
        assert!(revoke(&pool, &token).await.expect("revoke"));
        assert!(
            find_valid_by_token(&pool, &token)
                .await
                .expect("query")
                .is_none(),
            "吊销后会话应不可用"
        );
        // 再次续期无有效会话可续。
        assert!(!touch_last_seen(&pool, &token).await.expect("touch"));
    }

    #[tokio::test]
    #[ignore = "需真实 MySQL：设置 XIZHENDS_TEST_MASTER_DSN 并以 --ignored 运行"]
    async fn expired_session_is_rejected() {
        let Some(pool) = master_pool().await else {
            return;
        };

        // 过期会话（expires_at 在过去）→ find_valid_by_token 拒绝（Req 2.7）。
        let token = create_session(
            &pool,
            PrincipalKind::CompanyAdmin,
            9_000_002,
            Some(424242),
            past_dt(),
            None,
            None,
        )
        .await
        .expect("create_session");

        assert!(
            find_valid_by_token(&pool, &token)
                .await
                .expect("query")
                .is_none(),
            "过期会话不应作为有效会话返回"
        );
        // 但按 token 直查（不论过期）仍能找到记录，便于区分「过期」与「不存在」。
        assert!(find_by_token(&pool, &token).await.expect("query").is_some());

        // 清理。
        revoke(&pool, &token).await.expect("revoke");
    }

    #[tokio::test]
    #[ignore = "需真实 MySQL：设置 XIZHENDS_TEST_MASTER_DSN 并以 --ignored 运行"]
    async fn revoke_all_for_principal_kills_every_session() {
        let Some(pool) = master_pool().await else {
            return;
        };

        // 停用租户 / 禁用员工：吊销该主体全部会话（Req 2.9）。
        let principal_id = 9_000_003;
        let mut tokens = Vec::new();
        for _ in 0..3 {
            let t = create_session(
                &pool,
                PrincipalKind::Employee,
                principal_id,
                Some(424242),
                future_dt(),
                None,
                None,
            )
            .await
            .expect("create_session");
            tokens.push(t);
        }

        let removed = revoke_all_for_principal(&pool, PrincipalKind::Employee, principal_id)
            .await
            .expect("revoke_all");
        assert!(removed >= 3, "应吊销该主体全部会话，实际删除 {removed}");

        // 全部会话均不再有效。
        for t in &tokens {
            assert!(
                find_valid_by_token(&pool, t)
                    .await
                    .expect("query")
                    .is_none(),
                "主体被吊销后其会话应全部失效"
            );
        }
    }
}
