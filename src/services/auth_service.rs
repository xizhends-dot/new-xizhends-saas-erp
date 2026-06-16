//! 认证服务（Auth_Service）。
//!
//! 本文件目前只实现 **Argon2 密码哈希模块**（Task 5.1 / Requirements 2.1）：
//! - [`hash_password`]：使用 **Argon2id**（`Argon2::default()` 即 Argon2id，version 0x13）
//!   配合 [`OsRng`] 生成的随机盐，产出 PHC 字符串格式的密码哈希用于持久化。
//! - [`verify_password`]：用存储的 PHC 哈希校验明文口令，常数时间比较，校验失败/哈希损坏均返回
//!   `false`（fail-closed，登录安全优先）。
//!
//! > 说明：完整的三类主体登录流程（超管 / 公司管理员 / 员工）将在 Task 5.3 于本文件区域继续构建。
//! > 这里仅提供自包含、无状态的密码原语，供后续登录与重哈希逻辑复用。

use argon2::{
    password_hash::{rand_core::OsRng, PasswordHash, PasswordHasher, PasswordVerifier, SaltString},
    Argon2,
};

use crate::error::AppError;

/// 用 Argon2id 对明文口令生成密码哈希（PHC 字符串格式，含算法、参数与随机盐）。
///
/// - 算法：`Argon2::default()` 采用 **Argon2id** 变体与库内推荐参数。
/// - 盐：每次调用都用 [`OsRng`] 生成全新的随机盐（[`SaltString::generate`]），
///   因此对同一口令多次哈希会得到**不同**的结果。
///
/// # 后置条件
/// 返回的字符串可直接存入 `users.password_hash` / `admins.password_hash` 列，
/// 之后用 [`verify_password`] 校验。
///
/// # 错误
/// 仅在底层哈希在极端情况下失败时返回 [`AppError::Validation`]（实际几乎不可达）；
/// 失败细节只写日志，不外泄。
pub fn hash_password(password: &str) -> Result<String, AppError> {
    let salt = SaltString::generate(&mut OsRng);
    Argon2::default()
        .hash_password(password.as_bytes(), &salt)
        .map(|hash| hash.to_string())
        .map_err(|e| {
            tracing::error!(error = %e, "argon2 hash_password failed");
            AppError::Validation("密码处理失败".to_string())
        })
}

/// 校验明文口令是否与存储的 Argon2 哈希匹配。
///
/// - 入参 `stored_hash` 为 [`hash_password`] 产出的 PHC 字符串。
/// - 匹配返回 `true`，不匹配返回 `false`。
/// - **fail-closed**：当 `stored_hash` 无法解析（损坏 / 非 Argon2 格式）时，记录日志并返回 `false`，
///   绝不因数据异常而误放行。
pub fn verify_password(password: &str, stored_hash: &str) -> bool {
    match PasswordHash::new(stored_hash) {
        Ok(parsed) => Argon2::default()
            .verify_password(password.as_bytes(), &parsed)
            .is_ok(),
        Err(e) => {
            tracing::warn!(error = %e, "argon2 verify_password: malformed stored hash");
            false
        }
    }
}

// ============================================================================
// Task 5.3：认证服务与三类主体登录（Requirements 2.2、2.3、2.4、2.8）
// ----------------------------------------------------------------------------
// 本区段在上方密码哈希原语（Task 5.1）之上构建完整登录流程：
//   - 超管后台域（SuperAdmin）：在**主库** `admins` 表校验，建立 `SuperAdmin` 会话
//     （`tenant_id = None`）。
//   - 租户子域名：在该租户库 `users` 表校验，按 `is_company_admin` 区分
//     `CompanyAdmin` / `Employee` 会话（`tenant_id = Some(..)`）。
//   - 校验通过：调用 `session_repo::create_session` 在**主库** `sessions` 表插入一条
//     带高熵随机 token 的会话（Req 2.2），并构造带 HttpOnly + Secure + SameSite 的
//     安全 Cookie 值返回给调用方下发（Req 2.3）。
//   - 校验失败：对失败尝试进行计数限流（Req 2.4），由 [`LoginRateLimiter`] 实现。
//
// 注意：
//   * `sessions` 表位于**主库**，因此租户用户登录时同样把会话行写入主库，仅 `tenant_id`
//     置为该租户 id（与 `session_repo` 设计一致）。
//   * Cookie 的实际下发（写入响应头）由上层 handler 负责；本服务只产出 `Set-Cookie` 值。
//   * 「旧明文首登重哈希」（Req 2.5）由 **Task 5.4** 实现，下方租户登录的口令校验处
//     已留出清晰接缝（见 `LEGACY-PLAINTEXT-SEAM` 注释），当前对仅有旧明文、无 Argon2
//     哈希的账户 fail-closed（暂不放行），待 5.4 补全。
// ============================================================================

use std::collections::HashMap;
use std::sync::Mutex;
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

use sqlx::types::chrono::{DateTime, NaiveDateTime, Utc};
use sqlx::{FromRow, MySqlPool};

use crate::repository::session_repo::{self, PrincipalKind};

/// 会话 Cookie 名称。
pub const SESSION_COOKIE_NAME: &str = "xizhends_session";

/// 会话默认有效期（7 天），与 `design.md` 认证小节一致。
pub const SESSION_TTL: Duration = Duration::from_secs(7 * 24 * 60 * 60);

/// 限流默认阈值：连续失败次数达到该值后锁定。
const DEFAULT_MAX_FAILURES: u32 = 5;

/// 限流默认锁定时长：达到阈值后在此时间窗内拒绝继续尝试。
const DEFAULT_LOCKOUT: Duration = Duration::from_secs(15 * 60);

/// 一次成功登录的产出。
///
/// - `token`：写入 `sessions` 表的高熵随机会话 token。
/// - `set_cookie`：可直接放入响应 `Set-Cookie` 头的完整 Cookie 值
///   （含 HttpOnly + Secure + SameSite，见 [`build_session_cookie`]）。
/// - `principal_kind` / `principal_id` / `tenant_id`：本次登录建立的主体信息，
///   便于上层做跳转或审计。
#[derive(Debug, Clone)]
pub struct LoginOutcome {
    pub token: String,
    pub set_cookie: String,
    pub principal_kind: PrincipalKind,
    pub principal_id: i64,
    pub tenant_id: Option<i64>,
}

// ----------------------------------------------------------------------------
// 失败计数限流（Req 2.4）
// ----------------------------------------------------------------------------

/// 单个 key（用户名 / IP 维度）的失败计数状态。
#[derive(Debug, Clone)]
struct AttemptState {
    /// 自上次清零以来的连续失败次数。
    failures: u32,
    /// 锁定截止时刻；`Some(t)` 且 `now < t` 时拒绝继续尝试。
    locked_until: Option<Instant>,
}

/// 进程内登录失败计数限流器（Req 2.4）。
///
/// 设计与边界：
/// - **进程内、内存态**：用 `Mutex<HashMap<String, AttemptState>>` 维护「key → 失败状态」。
///   多实例部署下各实例独立计数（不共享），这对「减缓暴力破解」已足够；如需全局一致，
///   后续可换 Redis 等共享存储，接口保持不变。
/// - **key**：调用方按「用户名」或「用户名 + IP」等维度构造，本服务登录函数默认按
///   主体维度（超管 `sa:{username}`、租户 `t{tenant_id}:{username}`）计数。
/// - **算法**：每次失败 `failures += 1`；当 `failures >= max_failures` 时设置
///   `locked_until = now + lockout`。锁定期内 [`check_at`] 返回错误；锁定到期后自动解锁
///   并清零。成功登录 [`record_success_at`] 立即清除该 key 的状态。
/// - **可测试性**：核心方法接受显式 `now: Instant`，单元测试可注入合成时刻而无需真实等待；
///   生产侧用 [`check`] / [`record_failure`] / [`record_success`] 便捷封装（内部取
///   `Instant::now()`）。
#[derive(Debug)]
pub struct LoginRateLimiter {
    inner: Mutex<HashMap<String, AttemptState>>,
    max_failures: u32,
    lockout: Duration,
}

impl Default for LoginRateLimiter {
    fn default() -> Self {
        Self::new(DEFAULT_MAX_FAILURES, DEFAULT_LOCKOUT)
    }
}

impl LoginRateLimiter {
    /// 以指定阈值与锁定时长构造限流器。
    pub fn new(max_failures: u32, lockout: Duration) -> Self {
        Self {
            inner: Mutex::new(HashMap::new()),
            max_failures: max_failures.max(1),
            lockout,
        }
    }

    /// 校验某 key 当前是否被锁定（显式时钟版本，便于测试）。
    ///
    /// - 未锁定 / 无记录：返回 `Ok(())`。
    /// - 锁定期已过：自动解锁并清零，返回 `Ok(())`。
    /// - 仍在锁定期内：返回 [`AppError::Validation`]（携带可安全展示的提示文案）。
    pub fn check_at(&self, key: &str, now: Instant) -> Result<(), AppError> {
        let mut map = self.inner.lock().unwrap_or_else(|e| e.into_inner());
        if let Some(state) = map.get_mut(key) {
            if let Some(until) = state.locked_until {
                if now < until {
                    return Err(AppError::Validation(
                        "登录尝试过于频繁，请稍后再试".to_string(),
                    ));
                }
                // 锁定期已过：解锁并清零，允许重新尝试。
                state.failures = 0;
                state.locked_until = None;
            }
        }
        Ok(())
    }

    /// 记录一次失败（显式时钟版本）。达到阈值时进入锁定。
    pub fn record_failure_at(&self, key: &str, now: Instant) {
        let mut map = self.inner.lock().unwrap_or_else(|e| e.into_inner());
        let state = map.entry(key.to_string()).or_insert(AttemptState {
            failures: 0,
            locked_until: None,
        });
        state.failures = state.failures.saturating_add(1);
        if state.failures >= self.max_failures {
            state.locked_until = Some(now + self.lockout);
        }
    }

    /// 记录一次成功（显式时钟版本）：清除该 key 的失败状态。
    pub fn record_success_at(&self, key: &str, _now: Instant) {
        let mut map = self.inner.lock().unwrap_or_else(|e| e.into_inner());
        map.remove(key);
    }

    /// [`check_at`] 的生产封装（取当前时刻）。
    pub fn check(&self, key: &str) -> Result<(), AppError> {
        self.check_at(key, Instant::now())
    }

    /// [`record_failure_at`] 的生产封装（取当前时刻）。
    pub fn record_failure(&self, key: &str) {
        self.record_failure_at(key, Instant::now());
    }

    /// [`record_success_at`] 的生产封装（取当前时刻）。
    pub fn record_success(&self, key: &str) {
        self.record_success_at(key, Instant::now());
    }
}

// ----------------------------------------------------------------------------
// 安全 Cookie 构造（Req 2.3）
// ----------------------------------------------------------------------------

/// 构造会话 `Set-Cookie` 值，附带 `HttpOnly` + `Secure` + `SameSite=Lax` 等安全属性。
///
/// 属性说明：
/// - `HttpOnly`：禁止脚本读取，缓解 XSS 窃取会话。
/// - `Secure`：仅经 HTTPS 传输，缓解明文嗅探（Req 2.3 要求）。
/// - `SameSite=Lax`：缓解 CSRF，同时允许顶层导航（登录后跳转）携带 Cookie。
/// - `Path=/`：全站有效。
/// - `Max-Age`：相对有效期（秒），与会话 `expires_at` 对齐。
///
/// 返回的字符串形如：
/// `xizhends_session=<token>; Path=/; Max-Age=604800; HttpOnly; Secure; SameSite=Lax`
pub fn build_session_cookie(token: &str, max_age: Duration) -> String {
    format!(
        "{name}={token}; Path=/; Max-Age={max_age}; HttpOnly; Secure; SameSite=Lax",
        name = SESSION_COOKIE_NAME,
        token = token,
        max_age = max_age.as_secs(),
    )
}

/// 构造用于**清除**会话 Cookie 的 `Set-Cookie` 值（登出 / 失效场景）。
///
/// 以 `Max-Age=0` 立即过期，并保留相同的安全属性，供 Task 5.5 会话中间件在 token
/// 无效/过期/吊销时清 Cookie 使用。
pub fn build_expired_cookie() -> String {
    format!(
        "{name}=; Path=/; Max-Age=0; HttpOnly; Secure; SameSite=Lax",
        name = SESSION_COOKIE_NAME,
    )
}

// ----------------------------------------------------------------------------
// 三类主体登录（Req 2.2、2.8）
// ----------------------------------------------------------------------------

/// 主库 `admins` 查询投影。
#[derive(Debug, FromRow)]
struct AdminRow {
    id: i64,
    password_hash: String,
    status: String,
}

/// 租户库 `users` 查询投影。
#[derive(Debug, FromRow)]
struct TenantUserRow {
    id: i64,
    password_hash: Option<String>,
    /// 旧明文密码列；首登重哈希逻辑（Task 5.4 / Req 2.5）在缺少 Argon2 哈希时据此校验，
    /// 校验通过后即时重哈希并清空本列。
    legacy_password: Option<String>,
    is_company_admin: i8,
    is_active: i8,
}

/// 登录口令校验所选路径。
///
/// 把「该用哪条凭证校验」从带 DB 副作用的登录流程中抽离为纯函数 [`decide_verify_path`]，
/// 便于无需数据库即可单元测试首登重哈希的分支决策（Req 2.5）。
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub(crate) enum VerifyPath {
    /// 已有可用的 Argon2 哈希：走 [`verify_password`]。
    Argon2,
    /// 无 Argon2 哈希但存在旧明文：比对明文，通过后需即时重哈希（Req 2.5）。
    LegacyPlaintext,
    /// 既无哈希也无旧明文：无可用凭证，直接拒绝（fail-closed）。
    Reject,
}

/// 根据账户当前持有的凭证决定校验路径（纯函数，无副作用）。
///
/// 优先级：
/// 1. `password_hash` 非空 → [`VerifyPath::Argon2`]（已升级账户走标准哈希路径）。
/// 2. 否则 `legacy_password` 非空 → [`VerifyPath::LegacyPlaintext`]（旧明文首登，待重哈希）。
/// 3. 两者皆空 → [`VerifyPath::Reject`]。
///
/// 注意：一旦旧明文首登成功并重哈希，`password_hash` 即被写入、`legacy_password` 清空，
/// 后续登录将稳定落入 [`VerifyPath::Argon2`] 分支——旧明文从此失效。
pub(crate) fn decide_verify_path(
    password_hash: Option<&str>,
    legacy_password: Option<&str>,
) -> VerifyPath {
    match password_hash {
        Some(h) if !h.is_empty() => VerifyPath::Argon2,
        _ => match legacy_password {
            Some(p) if !p.is_empty() => VerifyPath::LegacyPlaintext,
            _ => VerifyPath::Reject,
        },
    }
}

/// 以**常数时间**比较提交口令与存储的旧明文密码。
///
/// 旧系统以明文存储口令，故首登时直接逐字节比较；为降低时序侧信道泄漏（口令长度/前缀），
/// 这里不短路、对全长做累积异或比较。长度不一致时同样返回 `false`。
pub(crate) fn verify_legacy_plaintext(submitted: &str, stored_plaintext: &str) -> bool {
    let a = submitted.as_bytes();
    let b = stored_plaintext.as_bytes();
    if a.len() != b.len() {
        return false;
    }
    let mut diff: u8 = 0;
    for (x, y) in a.iter().zip(b.iter()) {
        diff |= x ^ y;
    }
    diff == 0
}

/// 把「当前时刻 + ttl」换算为 `NaiveDateTime`（UTC），用作会话 `expires_at`。
///
/// 由于本工程的 `chrono` 关闭了 `clock` 特性（无 `Utc::now()`），这里改用
/// [`SystemTime`] 取 Unix 时间戳，再经 [`DateTime::from_timestamp`] 转换。
fn expires_at_from_now(ttl: Duration) -> NaiveDateTime {
    let unix_secs = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.as_secs())
        .unwrap_or(0) as i64;
    let target = unix_secs.saturating_add(ttl.as_secs() as i64);
    DateTime::<Utc>::from_timestamp(target, 0)
        .map(|dt| dt.naive_utc())
        // from_timestamp 仅在极端越界时返回 None；此处回退到时间戳 0 对应时刻。
        .unwrap_or_else(|| DateTime::<Utc>::from_timestamp(0, 0).unwrap().naive_utc())
}

/// 超级管理员登录（超管后台域）：在**主库** `admins` 表校验并建立 `SuperAdmin` 会话。
///
/// 流程（Req 2.2、2.4、2.8）：
/// 1. 失败计数限流前置校验（被锁定则直接拒绝）。
/// 2. 按 `username` 查 `admins`；要求账号存在且 `status = 'active'`。
/// 3. 用 [`verify_password`] 校验口令；失败则 `record_failure` 并返回
///    [`AppError::Unauthorized`]（不区分「账号不存在」与「口令错误」，避免账号枚举）。
/// 4. 成功：`record_success` 清零计数，在主库 `sessions` 插入 `SuperAdmin` 会话
///    （`tenant_id = None`），返回 token 与安全 Cookie。
pub async fn login_super_admin(
    master: &MySqlPool,
    limiter: &LoginRateLimiter,
    username: &str,
    password: &str,
    ip: Option<&str>,
    user_agent: Option<&str>,
) -> Result<LoginOutcome, AppError> {
    let key = format!("sa:{username}");
    limiter.check(&key)?;

    let admin: Option<AdminRow> = sqlx::query_as::<_, AdminRow>(
        "SELECT `id`, `password_hash`, `status` FROM `admins` WHERE `username` = ?",
    )
    .bind(username)
    .fetch_optional(master)
    .await?;

    // 账号必须存在且为启用状态，且口令校验通过。
    let verified = match &admin {
        Some(a) if a.status == "active" => verify_password(password, &a.password_hash),
        _ => false,
    };

    if !verified {
        limiter.record_failure(&key);
        return Err(AppError::Unauthorized);
    }

    let admin = admin.expect("verified implies admin row present");
    limiter.record_success(&key);

    let expires_at = expires_at_from_now(SESSION_TTL);
    let token = session_repo::create_session(
        master,
        PrincipalKind::SuperAdmin,
        admin.id,
        None,
        expires_at,
        ip,
        user_agent,
    )
    .await?;

    Ok(LoginOutcome {
        set_cookie: build_session_cookie(&token, SESSION_TTL),
        token,
        principal_kind: PrincipalKind::SuperAdmin,
        principal_id: admin.id,
        tenant_id: None,
    })
}

/// 租户用户登录（租户子域名）：在该租户库 `users` 表校验，按 `is_company_admin`
/// 区分 `CompanyAdmin` / `Employee`，并在**主库** `sessions` 建立会话。
///
/// 流程（Req 2.2、2.4、2.8）：
/// 1. 失败计数限流前置校验。
/// 2. 在 `tenant_pool`（该租户独立库）按 `username` 查 `users`；要求 `is_active = 1`。
/// 3. 校验口令；成功后按 `is_company_admin` 决定主体类型。
/// 4. 在主库 `sessions` 插入会话（`tenant_id = Some(tenant_id)`），返回 token 与安全 Cookie。
///
/// 参数 `master` 为主库连接池（写 `sessions`），`tenant_pool` 为该租户库连接池（读 `users`）。
pub async fn login_tenant_user(
    master: &MySqlPool,
    tenant_pool: &MySqlPool,
    limiter: &LoginRateLimiter,
    tenant_id: i64,
    username: &str,
    password: &str,
    ip: Option<&str>,
    user_agent: Option<&str>,
) -> Result<LoginOutcome, AppError> {
    let key = format!("t{tenant_id}:{username}");
    limiter.check(&key)?;

    let user: Option<TenantUserRow> = sqlx::query_as::<_, TenantUserRow>(
        "SELECT `id`, `password_hash`, `legacy_password`, `is_company_admin`, `is_active` \
         FROM `users` WHERE `username` = ?",
    )
    .bind(username)
    .fetch_optional(tenant_pool)
    .await?;

    // 校验：账号存在、启用、且口令通过。
    // `needs_rehash` 标记「本次走旧明文路径且校验通过」，用于在登录成功后即时重哈希（Req 2.5）。
    let mut needs_rehash = false;
    let verified = match &user {
        Some(u) if u.is_active != 0 => {
            // ── LEGACY-PLAINTEXT-SEAM（Task 5.4 已实现）──────────────────────
            // 优先用 Argon2 哈希校验；若账户尚无哈希但持有旧明文，则比对旧明文，
            // 通过后标记重哈希（见下方），实现「旧明文首登 → 即时 Argon2id 重哈希
            // 并使原明文失效」（Req 2.5）。
            match decide_verify_path(u.password_hash.as_deref(), u.legacy_password.as_deref()) {
                VerifyPath::Argon2 => {
                    // password_hash 非空已由 decide_verify_path 保证。
                    verify_password(password, u.password_hash.as_deref().unwrap_or_default())
                }
                VerifyPath::LegacyPlaintext => {
                    let ok = verify_legacy_plaintext(
                        password,
                        u.legacy_password.as_deref().unwrap_or_default(),
                    );
                    needs_rehash = ok;
                    ok
                }
                VerifyPath::Reject => false,
            }
        }
        _ => false,
    };

    if !verified {
        limiter.record_failure(&key);
        return Err(AppError::Unauthorized);
    }

    let user = user.expect("verified implies user row present");
    limiter.record_success(&key);

    // 旧明文首登重哈希（Req 2.5）：即时用 Argon2id 重哈希提交的口令写入 `password_hash`，
    // 并清空 `legacy_password`（置 NULL），使原明文从此失效——后续登录改走 Argon2 路径。
    // 在该租户库对 `users` 行执行运行时 UPDATE（租户库编译期不存在，统一用运行时查询）。
    if needs_rehash {
        let new_hash = hash_password(password)?;
        sqlx::query(
            "UPDATE `users` SET `password_hash` = ?, `legacy_password` = NULL WHERE `id` = ?",
        )
        .bind(&new_hash)
        .bind(user.id)
        .execute(tenant_pool)
        .await?;
    }

    // 按 is_company_admin 区分主体类型（Req 2.8）。
    let principal_kind = if user.is_company_admin != 0 {
        PrincipalKind::CompanyAdmin
    } else {
        PrincipalKind::Employee
    };

    let expires_at = expires_at_from_now(SESSION_TTL);
    let token = session_repo::create_session(
        master,
        principal_kind,
        user.id,
        Some(tenant_id),
        expires_at,
        ip,
        user_agent,
    )
    .await?;

    Ok(LoginOutcome {
        set_cookie: build_session_cookie(&token, SESSION_TTL),
        token,
        principal_kind,
        principal_id: user.id,
        tenant_id: Some(tenant_id),
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn hash_then_verify_round_trip_succeeds() {
        let password = "S3cure-口令-✓";
        let hash = hash_password(password).expect("hashing should succeed");
        assert!(verify_password(password, &hash));
    }

    #[test]
    fn wrong_password_fails_verification() {
        let hash = hash_password("correct horse battery staple").unwrap();
        assert!(!verify_password("Correct Horse Battery Staple", &hash));
        assert!(!verify_password("", &hash));
        assert!(!verify_password("totally-different", &hash));
    }

    #[test]
    fn hash_is_argon2id_phc_format() {
        let hash = hash_password("whatever").unwrap();
        // PHC 字符串以算法标识开头，Argon2::default() 为 Argon2id。
        assert!(
            hash.starts_with("$argon2id$"),
            "unexpected hash prefix: {hash}"
        );
    }

    #[test]
    fn same_password_produces_distinct_hashes_due_to_random_salt() {
        let password = "repeat-me";
        let h1 = hash_password(password).unwrap();
        let h2 = hash_password(password).unwrap();
        // 随机盐保证两次哈希不同，但都能校验通过。
        assert_ne!(h1, h2, "random salt should yield different hashes");
        assert!(verify_password(password, &h1));
        assert!(verify_password(password, &h2));
    }

    #[test]
    fn empty_password_round_trips() {
        let hash = hash_password("").unwrap();
        assert!(verify_password("", &hash));
        assert!(!verify_password("not-empty", &hash));
    }

    #[test]
    fn malformed_stored_hash_fails_closed() {
        // 损坏 / 非 PHC 格式的存储哈希必须判定为不匹配，而非报错放行。
        assert!(!verify_password("anything", "not-a-valid-phc-hash"));
        assert!(!verify_password("anything", ""));
        assert!(!verify_password("anything", "$argon2id$broken"));
    }
}

// ============================================================================
// Task 5.3 单元测试：Cookie 构造与限流器纯逻辑（无 DB 依赖）
// 数据库相关的登录路径（login_super_admin / login_tenant_user）依赖真实 MySQL，
// 由 Task 5.6 的集成测试覆盖；此处仅测试可纯函数验证的部分。
// ============================================================================
#[cfg(test)]
mod login_tests {
    use super::*;
    use std::time::{Duration, Instant};

    // ---- Cookie 构造 ----

    #[test]
    fn session_cookie_has_required_security_attributes() {
        let cookie = build_session_cookie("abc123", Duration::from_secs(604800));
        assert!(
            cookie.starts_with("xizhends_session=abc123"),
            "应以 name=token 开头: {cookie}"
        );
        assert!(cookie.contains("HttpOnly"), "缺少 HttpOnly: {cookie}");
        assert!(cookie.contains("Secure"), "缺少 Secure: {cookie}");
        assert!(cookie.contains("SameSite=Lax"), "缺少 SameSite: {cookie}");
        assert!(cookie.contains("Path=/"), "缺少 Path: {cookie}");
        assert!(
            cookie.contains("Max-Age=604800"),
            "Max-Age 不正确: {cookie}"
        );
    }

    #[test]
    fn session_cookie_max_age_reflects_ttl() {
        let cookie = build_session_cookie("t", Duration::from_secs(3600));
        assert!(cookie.contains("Max-Age=3600"));
    }

    #[test]
    fn expired_cookie_clears_value_and_zeroes_max_age() {
        let cookie = build_expired_cookie();
        assert!(
            cookie.starts_with("xizhends_session=;"),
            "应清空值: {cookie}"
        );
        assert!(cookie.contains("Max-Age=0"), "应立即过期: {cookie}");
        assert!(cookie.contains("HttpOnly"));
        assert!(cookie.contains("Secure"));
        assert!(cookie.contains("SameSite=Lax"));
    }

    // ---- 限流器 ----

    #[test]
    fn fresh_key_is_not_limited() {
        let limiter = LoginRateLimiter::new(3, Duration::from_secs(60));
        assert!(limiter.check_at("user", Instant::now()).is_ok());
    }

    #[test]
    fn failures_below_threshold_do_not_lock() {
        let limiter = LoginRateLimiter::new(3, Duration::from_secs(60));
        let now = Instant::now();
        limiter.record_failure_at("user", now);
        limiter.record_failure_at("user", now);
        // 仅 2 次 < 阈值 3 → 仍可尝试。
        assert!(limiter.check_at("user", now).is_ok());
    }

    #[test]
    fn reaching_threshold_locks_within_window() {
        let limiter = LoginRateLimiter::new(3, Duration::from_secs(60));
        let now = Instant::now();
        for _ in 0..3 {
            limiter.record_failure_at("user", now);
        }
        // 达到阈值 → 锁定期内拒绝。
        let err = limiter.check_at("user", now).unwrap_err();
        assert!(matches!(err, AppError::Validation(_)), "应为校验类错误");
    }

    #[test]
    fn lock_expires_after_window() {
        let limiter = LoginRateLimiter::new(3, Duration::from_secs(60));
        let now = Instant::now();
        for _ in 0..3 {
            limiter.record_failure_at("user", now);
        }
        // 锁定期内拒绝。
        assert!(limiter.check_at("user", now).is_err());
        // 超过锁定时长后自动解锁。
        let later = now + Duration::from_secs(61);
        assert!(
            limiter.check_at("user", later).is_ok(),
            "锁定到期应自动解锁"
        );
        // 解锁后计数清零，重新累计才会再次锁定。
        limiter.record_failure_at("user", later);
        assert!(
            limiter.check_at("user", later).is_ok(),
            "解锁后单次失败不应再次锁定"
        );
    }

    #[test]
    fn success_clears_failure_count() {
        let limiter = LoginRateLimiter::new(3, Duration::from_secs(60));
        let now = Instant::now();
        limiter.record_failure_at("user", now);
        limiter.record_failure_at("user", now);
        limiter.record_success_at("user", now);
        // 成功后状态清除：再次失败应从 0 重新计数，不会因历史失败而锁定。
        limiter.record_failure_at("user", now);
        assert!(
            limiter.check_at("user", now).is_ok(),
            "成功登录应清零失败计数"
        );
    }

    #[test]
    fn keys_are_isolated() {
        let limiter = LoginRateLimiter::new(2, Duration::from_secs(60));
        let now = Instant::now();
        limiter.record_failure_at("user-a", now);
        limiter.record_failure_at("user-a", now);
        // user-a 被锁定，但 user-b 不受影响。
        assert!(limiter.check_at("user-a", now).is_err());
        assert!(limiter.check_at("user-b", now).is_ok());
    }

    #[test]
    fn default_limiter_uses_sane_thresholds() {
        let limiter = LoginRateLimiter::default();
        let now = Instant::now();
        // 默认阈值为 5：前 4 次失败不锁定。
        for _ in 0..(DEFAULT_MAX_FAILURES - 1) {
            limiter.record_failure_at("user", now);
        }
        assert!(limiter.check_at("user", now).is_ok());
        // 第 5 次失败触发锁定。
        limiter.record_failure_at("user", now);
        assert!(limiter.check_at("user", now).is_err());
    }

    // ---- 旧明文首登重哈希：路径决策（Task 5.4 / Req 2.5）----

    #[test]
    fn verify_path_prefers_argon2_when_hash_present() {
        // 同时存在哈希与旧明文时，必须走 Argon2 路径（已升级账户不再碰旧明文）。
        assert_eq!(
            decide_verify_path(Some("$argon2id$abc"), Some("plaintext")),
            VerifyPath::Argon2
        );
        assert_eq!(
            decide_verify_path(Some("$argon2id$abc"), None),
            VerifyPath::Argon2
        );
    }

    #[test]
    fn verify_path_uses_legacy_when_only_plaintext() {
        // 无哈希（None 或空串）但有旧明文 → 走旧明文路径，待重哈希。
        assert_eq!(
            decide_verify_path(None, Some("plaintext")),
            VerifyPath::LegacyPlaintext
        );
        assert_eq!(
            decide_verify_path(Some(""), Some("plaintext")),
            VerifyPath::LegacyPlaintext
        );
    }

    #[test]
    fn verify_path_rejects_when_no_credentials() {
        // 既无哈希也无旧明文（或皆为空）→ 拒绝（fail-closed）。
        assert_eq!(decide_verify_path(None, None), VerifyPath::Reject);
        assert_eq!(decide_verify_path(Some(""), Some("")), VerifyPath::Reject);
        assert_eq!(decide_verify_path(None, Some("")), VerifyPath::Reject);
    }

    // ---- 旧明文首登重哈希：明文比较 ----

    #[test]
    fn legacy_plaintext_matches_exactly() {
        assert!(verify_legacy_plaintext("hunter2", "hunter2"));
        assert!(verify_legacy_plaintext("中文口令-✓", "中文口令-✓"));
        assert!(verify_legacy_plaintext("", ""));
    }

    #[test]
    fn legacy_plaintext_rejects_mismatch() {
        assert!(!verify_legacy_plaintext("hunter2", "Hunter2"));
        assert!(!verify_legacy_plaintext("hunter2", "hunter"));
        assert!(!verify_legacy_plaintext("hunter", "hunter2"));
        assert!(!verify_legacy_plaintext("", "x"));
        assert!(!verify_legacy_plaintext("x", ""));
    }

    #[test]
    fn rehash_invalidates_legacy_plaintext_path() {
        // 模拟首登重哈希后的账户状态变化：原本走旧明文路径，重哈希写入 password_hash
        // 并清空 legacy_password 后，必须改走 Argon2 路径——原明文不再被旧明文分支接受。
        let legacy = "old-plaintext";
        assert_eq!(
            decide_verify_path(None, Some(legacy)),
            VerifyPath::LegacyPlaintext
        );

        // 重哈希：password_hash <- Argon2(legacy)，legacy_password <- NULL。
        let new_hash = hash_password(legacy).unwrap();
        assert_eq!(
            decide_verify_path(Some(&new_hash), None),
            VerifyPath::Argon2
        );
        // 新哈希可校验原口令；旧明文分支已不可达。
        assert!(verify_password(legacy, &new_hash));
    }

    #[test]
    fn rehash_then_wrong_password_rejected_via_argon2() {
        // 首登重哈希后，账户改走 Argon2 路径：正确口令通过、错误口令被拒，
        // 旧明文直比分支彻底退出（Req 2.5「原明文失效」的安全语义）。
        let legacy = "legacy-secret-口令";
        let new_hash = hash_password(legacy).unwrap();

        // 重哈希后状态：仅有 Argon2 哈希，legacy 已清空 → 决策恒为 Argon2。
        assert_eq!(
            decide_verify_path(Some(&new_hash), None),
            VerifyPath::Argon2
        );

        // 正确口令仍可登录（密码本身没变，变的是存储/校验方式）。
        assert!(verify_password(legacy, &new_hash));
        // 错误口令被拒。
        assert!(!verify_password("legacy-secret-口令-x", &new_hash));
        assert!(!verify_password("", &new_hash));
    }

    /// 复刻 `login_tenant_user` 中纯逻辑的「选路 + 校验 + 是否需重哈希」决策，
    /// 不触碰数据库，便于断言首登重哈希前后的完整行为（Req 2.5）。
    fn simulate_verify(
        password_hash: Option<&str>,
        legacy_password: Option<&str>,
        submitted: &str,
    ) -> (bool, bool) {
        match decide_verify_path(password_hash, legacy_password) {
            VerifyPath::Argon2 => (
                verify_password(submitted, password_hash.unwrap_or_default()),
                false,
            ),
            VerifyPath::LegacyPlaintext => {
                let ok = verify_legacy_plaintext(submitted, legacy_password.unwrap_or_default());
                (ok, ok)
            }
            VerifyPath::Reject => (false, false),
        }
    }

    #[test]
    fn first_login_with_legacy_plaintext_authenticates_and_flags_rehash() {
        // 首登：仅有旧明文。提交正确明文 → 通过且标记需重哈希。
        let (ok, needs_rehash) = simulate_verify(None, Some("old-pw"), "old-pw");
        assert!(ok, "旧明文首登应通过");
        assert!(needs_rehash, "旧明文路径通过后应标记重哈希");

        // 提交错误明文 → 拒绝，且不重哈希。
        let (ok, needs_rehash) = simulate_verify(None, Some("old-pw"), "wrong");
        assert!(!ok);
        assert!(!needs_rehash);
    }

    #[test]
    fn after_rehash_legacy_plaintext_no_longer_authenticates() {
        // 重哈希后账户状态：password_hash <- Argon2(old-pw)，legacy_password <- NULL。
        let new_hash = hash_password("old-pw").unwrap();

        // 同一明文现经 Argon2 校验通过，且不再触发重哈希（已是哈希态）。
        let (ok, needs_rehash) = simulate_verify(Some(&new_hash), None, "old-pw");
        assert!(ok, "重哈希后正确口令仍可登录");
        assert!(!needs_rehash, "已是 Argon2 哈希，不应再次重哈希");

        // 关键：原本能走旧明文直比的路径已不存在——即便构造一个「错误的」旧明文残留，
        // 只要 password_hash 在场，决策恒为 Argon2，旧明文直比分支不可达（Req 2.5）。
        assert_eq!(
            decide_verify_path(Some(&new_hash), Some("old-pw")),
            VerifyPath::Argon2
        );
    }
}
