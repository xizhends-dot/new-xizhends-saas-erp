//! 系统公告数据访问（仅访问主库 `announcements`，Task 11.4 / Requirements 10.3）。
//!
//! 超管后台「系统公告」：发布全局 / 指定租户公告（标题 / 类型 / 可见范围 / 内容），
//! 并提供「已发布列表」（最新在前）。设计依据：design.md 超管后台「系统公告」与
//! 主库迁移 0006（`announcements` 列：id / title / kind / scope / tenant_id / content / created_at）。
//!
//! 风格约束（与本仓其余 repository 一致）：统一使用 SQLx 运行时 API
//! （`query` / `query_as`，**不**使用编译期 `query!` 宏）。仅访问主库 `master`，绝不触达租户库。

use serde::Serialize;
use sqlx::types::chrono::NaiveDateTime;
use sqlx::{FromRow, MySqlPool};

use crate::error::AppError;

/// 合法可见范围取值（迁移 0006：`global` 全局 / `tenant` 指定租户）。
pub const VALID_SCOPES: [&str; 2] = ["global", "tenant"];

/// 系统公告行（已发布列表视图，对应 `announcements` 表列）。
#[derive(Debug, Clone, PartialEq, Eq, Serialize, FromRow)]
pub struct Announcement {
    pub id: i64,
    /// 标题（`announcements.title`）。
    pub title: String,
    /// 类型（`announcements.kind`，如 info / warning / maintenance）。
    pub kind: String,
    /// 可见范围（`announcements.scope`，`global` / `tenant`）。
    pub scope: String,
    /// `scope=tenant` 时指定的租户；`global` 时为 `None`。
    pub tenant_id: Option<i64>,
    /// 公告内容（`announcements.content`）。
    pub content: String,
    /// 创建时间（`announcements.created_at`）。
    pub created_at: NaiveDateTime,
}

/// 校验「可见范围 + 租户」组合的合法性（**纯函数**，便于无 DB 单测）。
///
/// 规则（Requirements 10.3 / 迁移 0006）：
/// - `scope` 必须为 `global` 或 `tenant`，否则 [`AppError::Validation`]。
/// - `scope = "tenant"` 时 `tenant_id` 必须为 `Some`（缺失 → [`AppError::Validation`]）。
/// - `scope = "global"` 时不要求 `tenant_id`（落库时由调用方强制写入 `None`）。
///
/// 错误文案来自入参校验，可安全回显给客户端。
pub fn validate_scope(scope: &str, tenant_id: Option<i64>) -> Result<(), AppError> {
    if !VALID_SCOPES.contains(&scope) {
        return Err(AppError::Validation(format!("未知可见范围：{scope}")));
    }
    if scope == "tenant" && tenant_id.is_none() {
        return Err(AppError::Validation(
            "指定租户公告必须提供租户 ID".to_string(),
        ));
    }
    Ok(())
}

/// 发布公告：写主库 `announcements`（Requirements 10.3）。
///
/// 落库前先经 [`validate_scope`] 校验可见范围与租户组合。为保证不变量
/// 「`scope=global` ⇒ `tenant_id IS NULL`」，本函数对 `global` 范围**强制**写入 `NULL`
/// （忽略调用方传入的 `tenant_id`）；`tenant` 范围写入校验通过的 `Some(tenant_id)`。
///
/// 返回新建公告的自增主键。`tenant_id` 外键不存在等 → [`AppError::Db`]。
pub async fn create_announcement(
    master: &MySqlPool,
    title: &str,
    kind: &str,
    scope: &str,
    tenant_id: Option<i64>,
    content: &str,
) -> Result<i64, AppError> {
    validate_scope(scope, tenant_id)?;

    // 不变量：global 强制 NULL；tenant 用校验通过的租户 ID。
    let effective_tenant_id = if scope == "global" { None } else { tenant_id };

    let result = sqlx::query(
        "INSERT INTO `announcements` (`title`, `kind`, `scope`, `tenant_id`, `content`) \
         VALUES (?, ?, ?, ?, ?)",
    )
    .bind(title)
    .bind(kind)
    .bind(scope)
    .bind(effective_tenant_id)
    .bind(content)
    .execute(master)
    .await?;

    Ok(result.last_insert_id() as i64)
}

/// 读取已发布公告列表（Requirements 10.3）。
///
/// 按 `created_at` 倒序、同序再按 `id` 倒序，确保「最新在前」且顺序确定。仅访问主库 `master`。
pub async fn list_announcements(master: &MySqlPool) -> Result<Vec<Announcement>, AppError> {
    let rows: Vec<Announcement> = sqlx::query_as::<_, Announcement>(
        "SELECT `id`, `title`, `kind`, `scope`, `tenant_id`, `content`, `created_at` \
         FROM `announcements` ORDER BY `created_at` DESC, `id` DESC",
    )
    .fetch_all(master)
    .await?;

    Ok(rows)
}

#[cfg(test)]
mod tests {
    use super::*;
    use sqlx::types::chrono::NaiveDate;

    #[test]
    fn validate_scope_accepts_global_without_tenant() {
        assert!(validate_scope("global", None).is_ok());
        // global 即便携带 tenant_id 也视为合法（落库时会被强制忽略为 NULL）。
        assert!(validate_scope("global", Some(7)).is_ok());
    }

    #[test]
    fn validate_scope_accepts_tenant_with_tenant_id() {
        assert!(validate_scope("tenant", Some(42)).is_ok());
    }

    #[test]
    fn validate_scope_rejects_tenant_without_tenant_id() {
        let err = validate_scope("tenant", None).unwrap_err();
        assert!(matches!(err, AppError::Validation(_)));
        assert!(err.client_message().contains("租户"));
    }

    #[test]
    fn validate_scope_rejects_unknown_scope() {
        for bad in ["", "Global", "GLOBAL", "all", "world"] {
            let err = validate_scope(bad, None).unwrap_err();
            assert!(matches!(err, AppError::Validation(_)));
        }
        // 非法范围即便带 tenant_id 也应拒绝。
        assert!(matches!(
            validate_scope("all", Some(1)),
            Err(AppError::Validation(_))
        ));
    }

    fn dt(y: i32, mo: u32, d: u32) -> NaiveDateTime {
        NaiveDate::from_ymd_opt(y, mo, d)
            .unwrap()
            .and_hms_opt(0, 0, 0)
            .unwrap()
    }

    #[test]
    fn announcement_serializes_global_with_null_tenant() {
        let a = Announcement {
            id: 1,
            title: "系统维护通知".into(),
            kind: "maintenance".into(),
            scope: "global".into(),
            tenant_id: None,
            content: "今晚 02:00 维护".into(),
            created_at: dt(2026, 1, 1),
        };
        let json = serde_json::to_value(&a).unwrap();
        assert_eq!(json["id"], 1);
        assert_eq!(json["title"], "系统维护通知");
        assert_eq!(json["kind"], "maintenance");
        assert_eq!(json["scope"], "global");
        assert!(json["tenant_id"].is_null());
        assert_eq!(json["content"], "今晚 02:00 维护");
    }

    #[test]
    fn announcement_serializes_tenant_scope_with_tenant_id() {
        let a = Announcement {
            id: 9,
            title: "专属公告".into(),
            kind: "info".into(),
            scope: "tenant".into(),
            tenant_id: Some(42),
            content: "贵司专属变更".into(),
            created_at: dt(2026, 2, 2),
        };
        let json = serde_json::to_value(&a).unwrap();
        assert_eq!(json["scope"], "tenant");
        assert_eq!(json["tenant_id"], 42);
    }
}
