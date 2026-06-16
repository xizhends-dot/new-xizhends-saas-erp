//! 租户数据访问（仅访问主库），含 `load_active_dsn`（Task 3.1 / Requirements 1.3、1.6）。
//!
//! 本模块只读主库 `tenants` 表，是连接池管理器解析租户 DSN 的唯一入口；
//! 租户库本身由 [`crate::db::pool::TenantPoolManager`] 持有，不在此处访问。

use serde::Serialize;
use sqlx::types::chrono::NaiveDateTime;
use sqlx::{FromRow, MySqlPool};

use crate::db::pool::{TenantDsn, TenantId};
use crate::error::AppError;

/// 从主库读取某租户的「启用态」连接信息。
///
/// 语义（设计 4.4 慢路径）：
/// - 租户不存在 → `Ok(None)`（调用方据此判 [`AppError::TenantUnavailable`]）。
/// - 租户存在但 `status != 'active'`（如 `suspended`）→ `Ok(None)`，
///   等价于「不可用」，从而触发其连接池失效与拒绝进入（Requirements 1.6）。
/// - 租户启用 → 解密并解析 `db_dsn_enc` 为 [`TenantDsn`] 后 `Ok(Some(..))`。
/// - DSN 解密/解析失败 → `Err(AppError::PoolBuildFailed)`（不外泄底层细节）。
/// - 主库查询错误 → `Err(AppError::Db(..))`。
///
/// 仅访问主库 `master`，绝不触达租户库。
pub async fn load_active_dsn(
    master: &MySqlPool,
    tenant_id: TenantId,
) -> Result<Option<TenantDsn>, AppError> {
    let row: Option<(String, String)> =
        sqlx::query_as("SELECT `db_dsn_enc`, `status` FROM `tenants` WHERE `id` = ?")
            .bind(tenant_id)
            .fetch_optional(master)
            .await?;

    let Some((dsn_enc, status)) = row else {
        // 租户不存在。
        return Ok(None);
    };

    // 状态校验：仅 active 视为可用；其余（如 suspended）一律按不可用处理。
    if status != "active" {
        return Ok(None);
    }

    let dsn = TenantDsn::from_encrypted(&dsn_enc)?;
    Ok(Some(dsn))
}

/// 由请求 Host（子域名 / 自定义域名）解析「启用态」租户（Task 3.3 / Requirements 1.1、1.2）。
///
/// 返回值：命中启用租户时 `Ok(Some((tenant_id, company_name)))`；
/// 无任何启用租户匹配该 Host 时 `Ok(None)`（调用方据此判 [`AppError::TenantUnavailable`]）。
///
/// 匹配规则（见设计 3.8「按 `subdomain` 解析」与 4.6）：
/// - 先按**完整 Host**匹配 `tenants.subdomain`（支持租户登记其自定义域名整串）；
/// - 再按 Host 的**首段标签**匹配（支持 `companya.orders.example.com` 这类子域名形态）；
/// - 仅 `status = 'active'` 的租户视为可用；`subdomain` 唯一，故每个候选至多命中一条。
///
/// 完整 Host 优先，保证「自定义域名」与「子域名」并存时结果确定。
/// 仅访问主库 `master`，绝不触达租户库。
pub async fn resolve_tenant_by_host(
    master: &MySqlPool,
    host: &str,
) -> Result<Option<(TenantId, String)>, AppError> {
    for candidate in host_candidates(host) {
        let row: Option<(TenantId, String)> = sqlx::query_as(
            "SELECT `id`, `company_name` FROM `tenants` \
             WHERE `subdomain` = ? AND `status` = 'active' LIMIT 1",
        )
        .bind(&candidate)
        .fetch_optional(master)
        .await?;

        if row.is_some() {
            return Ok(row);
        }
    }
    Ok(None)
}

/// 由 Host 推导用于匹配 `tenants.subdomain` 的候选键（按优先级排序）。
///
/// 处理步骤：剥离端口（`:8080`）、去除 FQDN 尾点、统一小写。
/// 产出 `[完整host, 首段标签]`（首段标签存在且不等于完整 host 时才追加）。
/// Host 为空时返回空向量（调用方据此判定不可用）。
pub fn host_candidates(host: &str) -> Vec<String> {
    // 去端口：取首个 ':' 之前部分（域名主机不含 ':'，IPv6 字面量不在支持范围内）。
    let host = host.split(':').next().unwrap_or(host).trim();
    // 去 FQDN 尾点，统一小写。
    let host = host.trim_end_matches('.').trim();
    if host.is_empty() {
        return Vec::new();
    }
    let host = host.to_ascii_lowercase();

    let mut candidates = vec![host.clone()];
    if let Some((label, _rest)) = host.split_once('.') {
        if !label.is_empty() && label != host {
            candidates.push(label.to_string());
        }
    }
    candidates
}

#[cfg(test)]
mod tests {
    use super::host_candidates;

    #[test]
    fn full_host_then_first_label() {
        assert_eq!(
            host_candidates("companya.orders.example.com"),
            vec![
                "companya.orders.example.com".to_string(),
                "companya".to_string()
            ]
        );
    }

    #[test]
    fn strips_port_and_lowercases() {
        assert_eq!(
            host_candidates("CompanyA.Example.com:8080"),
            vec!["companya.example.com".to_string(), "companya".to_string()]
        );
    }

    #[test]
    fn strips_trailing_fqdn_dot() {
        assert_eq!(
            host_candidates("shop.example.com."),
            vec!["shop.example.com".to_string(), "shop".to_string()]
        );
    }

    #[test]
    fn single_label_has_no_duplicate_candidate() {
        assert_eq!(host_candidates("localhost"), vec!["localhost".to_string()]);
    }

    #[test]
    fn custom_domain_full_host_is_first() {
        // 自定义域名：完整 Host 优先，确保可登记整串自定义域名。
        let c = host_candidates("orders.companya.co.jp");
        assert_eq!(c[0], "orders.companya.co.jp");
        assert_eq!(c[1], "orders");
    }

    #[test]
    fn empty_or_port_only_host_yields_nothing() {
        assert!(host_candidates("").is_empty());
        assert!(host_candidates("   ").is_empty());
        assert!(host_candidates(":8080").is_empty());
    }
}

// ───────────────────────────────────────────────────────────────────────────
// 超管后台「租户管理」CRUD（Task 11.1 / Requirements 10.2、7.4）
//
// 本节为超管后台租户管理提供主库 `tenants` 表的增删改查与状态切换，所有查询统一用
// SQLx 运行时 API（`query` / `query_as`，不使用编译期 `query!` 宏），与本仓其余 repository
// 风格一致。仅访问主库 `master`，绝不触达租户库。
//
// 设计依据：design.md 3.8（`tenants` 列：公司名/子域名/加密 DSN/套餐/状态/员工数/创建时间）与
// 7.6 超管后台「租户管理」视图（列：公司/子域名/数据库/套餐/平台授权标签/员工数/状态/创建时间）。
// 安全约束：`db_dsn_enc` 含口令，列表展示**绝不回显口令**——仅以 `host:port/database` 形态
// 呈现「数据库」列（见 [`db_label_from_enc`]）。
// ───────────────────────────────────────────────────────────────────────────

/// 合法套餐取值（design.md 3.8：`basic` / `pro` / `ent`）。
pub const VALID_PLANS: [&str; 3] = ["basic", "pro", "ent"];

/// 合法租户状态取值（design.md 3.8：`active` / `suspended`）。
pub const VALID_STATUSES: [&str; 2] = ["active", "suspended"];

/// 校验套餐取值合法（非法 → [`AppError::Validation`]，文案可安全回显）。
pub fn validate_plan(plan: &str) -> Result<(), AppError> {
    if VALID_PLANS.contains(&plan) {
        Ok(())
    } else {
        Err(AppError::Validation(format!("未知套餐：{plan}")))
    }
}

/// 校验状态取值合法（仅允许 `active` / `suspended`）。
pub fn validate_status(status: &str) -> Result<(), AppError> {
    if VALID_STATUSES.contains(&status) {
        Ok(())
    } else {
        Err(AppError::Validation(format!("未知状态：{status}")))
    }
}

/// 由加密 DSN 推导「数据库」列展示文案：`host:port/database`。
///
/// **安全**：仅取 `host` / `port` / `database` 三段拼装，**绝不包含用户名或口令**，
/// 因此可安全展示在超管列表中。无法解析（解密失败 / 格式非法）时回退为 `—`，
/// 既不外泄底层原因也不暴露原始密文。
pub fn db_label_from_enc(enc: &str) -> String {
    match TenantDsn::from_encrypted(enc) {
        Ok(dsn) => format!("{}:{}/{}", dsn.host, dsn.port, dsn.database),
        Err(_) => "—".to_string(),
    }
}

/// 租户列表行（超管后台「租户管理」视图，design.md 7.6）。
///
/// 字段对应列：公司名 / 子域名 / 数据库（脱敏）/ 套餐 / 平台授权标签 / 员工数 / 状态 / 创建时间。
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct TenantSummary {
    pub id: TenantId,
    /// 公司名（`tenants.company_name`）。
    pub company_name: String,
    /// 公司简称（`tenants.company_short_name`）。
    pub company_short_name: Option<String>,
    /// 联系人姓名（`tenants.contact_name`）。
    pub contact_name: Option<String>,
    /// 联系人电话（`tenants.contact_phone`）。
    pub contact_phone: Option<String>,
    /// 联系人邮箱（`tenants.contact_email`）。
    pub contact_email: Option<String>,
    /// 联系人微信（`tenants.contact_wechat`）。
    pub contact_wechat: Option<String>,
    /// 联系地址（`tenants.address`）。
    pub address: Option<String>,
    /// 租户备注（`tenants.remark`）。
    pub remark: Option<String>,
    /// 子域名（`tenants.subdomain`，唯一）。
    pub subdomain: String,
    /// 「数据库」列的脱敏展示（`host:port/database`，无口令）；见 [`db_label_from_enc`]。
    pub db_label: String,
    /// 套餐（`tenants.plan`）。
    pub plan: String,
    /// 平台授权标签：该租户**已开通**（`tenant_platform.enabled=1`）的平台代码，升序去重。
    pub authorized_platforms: Vec<String>,
    /// 员工数（`tenants.staff_count` 缓存值）。
    pub staff_count: i32,
    /// 状态（`active` / `suspended`）。
    pub status: String,
    /// 创建时间（`tenants.created_at`）。
    pub created_at: NaiveDateTime,
}

/// 新建租户入参（`status` 固定 `active`、`staff_count` 固定 0，由本层写入）。
#[derive(Debug, Clone)]
pub struct NewTenant {
    pub company_name: String,
    pub company_short_name: Option<String>,
    pub contact_name: Option<String>,
    pub contact_phone: Option<String>,
    pub contact_email: Option<String>,
    pub contact_wechat: Option<String>,
    pub address: Option<String>,
    pub remark: Option<String>,
    pub subdomain: String,
    /// 加密后的独立库 DSN（密文按原样落库）。
    pub db_dsn_enc: String,
    pub plan: String,
}

/// 编辑租户入参。`db_dsn_enc` 为 `None` 时保留原 DSN 不变（避免误清空连接信息）。
#[derive(Debug, Clone)]
pub struct TenantUpdate {
    pub company_name: String,
    pub company_short_name: Option<String>,
    pub contact_name: Option<String>,
    pub contact_phone: Option<String>,
    pub contact_email: Option<String>,
    pub contact_wechat: Option<String>,
    pub address: Option<String>,
    pub remark: Option<String>,
    pub subdomain: String,
    /// `Some` → 更新为新密文；`None` → 保留既有 DSN。
    pub db_dsn_enc: Option<String>,
    pub plan: String,
}

/// `tenants` 表行投影（列与表一一对应）。
#[derive(Debug, FromRow)]
struct TenantRow {
    id: TenantId,
    company_name: String,
    company_short_name: Option<String>,
    contact_name: Option<String>,
    contact_phone: Option<String>,
    contact_email: Option<String>,
    contact_wechat: Option<String>,
    address: Option<String>,
    remark: Option<String>,
    subdomain: String,
    db_dsn_enc: String,
    plan: String,
    status: String,
    staff_count: i32,
    created_at: NaiveDateTime,
}

/// 将 `tenants` 行与「已开通平台」对应关系组装为列表视图行（**纯函数**，便于无 DB 单测）。
///
/// - `rows`：`tenants` 表行（保持调用方给定顺序，通常按 `created_at` 倒序）。
/// - `enabled_platforms`：`(tenant_id, platform_code)` 对，仅含已开通项；本函数据此聚合标签。
///
/// 每个租户的平台标签升序去重；DSN 经 [`db_label_from_enc`] 脱敏为「数据库」列文案。
fn assemble_summaries(
    rows: Vec<TenantRow>,
    enabled_platforms: Vec<(TenantId, String)>,
) -> Vec<TenantSummary> {
    use std::collections::HashMap;

    let mut by_tenant: HashMap<TenantId, Vec<String>> = HashMap::new();
    for (tid, code) in enabled_platforms {
        by_tenant.entry(tid).or_default().push(code);
    }
    for codes in by_tenant.values_mut() {
        codes.sort();
        codes.dedup();
    }

    rows.into_iter()
        .map(|r| TenantSummary {
            db_label: db_label_from_enc(&r.db_dsn_enc),
            authorized_platforms: by_tenant.remove(&r.id).unwrap_or_default(),
            id: r.id,
            company_name: r.company_name,
            company_short_name: r.company_short_name,
            contact_name: r.contact_name,
            contact_phone: r.contact_phone,
            contact_email: r.contact_email,
            contact_wechat: r.contact_wechat,
            address: r.address,
            remark: r.remark,
            subdomain: r.subdomain,
            plan: r.plan,
            staff_count: r.staff_count,
            status: r.status,
            created_at: r.created_at,
        })
        .collect()
}

/// 读取全部租户的列表视图（Requirements 10.2）。
///
/// 取数后在内存组装平台授权标签：两条主库查询（租户全集 + 已开通授权全集），
/// 再由 [`assemble_summaries`] 聚合，避免 N+1。按 `created_at` 倒序、同序再按 `id` 倒序。
/// 仅访问主库 `master`。
pub async fn list_tenants(master: &MySqlPool) -> Result<Vec<TenantSummary>, AppError> {
    let rows: Vec<TenantRow> = sqlx::query_as::<_, TenantRow>(
        "SELECT `id`, `company_name`, `company_short_name`, `contact_name`, `contact_phone`, \
                `contact_email`, `contact_wechat`, `address`, `remark`, `subdomain`, \
                `db_dsn_enc`, `plan`, `status`, `staff_count`, `created_at` \
         FROM `tenants` ORDER BY `created_at` DESC, `id` DESC",
    )
    .fetch_all(master)
    .await?;

    let enabled_platforms: Vec<(TenantId, String)> = sqlx::query_as(
        "SELECT `tenant_id`, `platform_code` FROM `tenant_platform` \
         WHERE `enabled` = 1 ORDER BY `tenant_id` ASC, `platform_code` ASC",
    )
    .fetch_all(master)
    .await?;

    Ok(assemble_summaries(rows, enabled_platforms))
}

/// 按 ID 读取单个租户的后台编辑视图数据。
///
/// 仍只返回脱敏后的 `db_label`，不会把 `db_dsn_enc` 暴露给模板。
pub async fn get_tenant(
    master: &MySqlPool,
    tenant_id: TenantId,
) -> Result<Option<TenantSummary>, AppError> {
    let row: Option<TenantRow> = sqlx::query_as::<_, TenantRow>(
        "SELECT `id`, `company_name`, `company_short_name`, `contact_name`, `contact_phone`, \
                `contact_email`, `contact_wechat`, `address`, `remark`, `subdomain`, \
                `db_dsn_enc`, `plan`, `status`, `staff_count`, `created_at` \
         FROM `tenants` WHERE `id` = ? LIMIT 1",
    )
    .bind(tenant_id)
    .fetch_optional(master)
    .await?;

    let Some(row) = row else {
        return Ok(None);
    };

    let enabled_platforms: Vec<(TenantId, String)> = sqlx::query_as(
        "SELECT `tenant_id`, `platform_code` FROM `tenant_platform` \
         WHERE `tenant_id` = ? AND `enabled` = 1 ORDER BY `platform_code` ASC",
    )
    .bind(tenant_id)
    .fetch_all(master)
    .await?;

    Ok(assemble_summaries(vec![row], enabled_platforms).pop())
}

/// 新建租户：写主库 `tenants`（Requirements 7.4）。
///
/// `status` 固定写入 `active`、`staff_count` 固定 0（员工数为缓存，随员工管理更新）。
/// 套餐非法 → [`AppError::Validation`]；`subdomain` 唯一键冲突等 → [`AppError::Db`]。
/// 返回新建租户的自增主键。
pub async fn create_tenant(master: &MySqlPool, new: &NewTenant) -> Result<TenantId, AppError> {
    validate_plan(&new.plan)?;

    let result = sqlx::query(
        "INSERT INTO `tenants` \
         (`company_name`, `company_short_name`, `contact_name`, `contact_phone`, \
          `contact_email`, `contact_wechat`, `address`, `remark`, `subdomain`, \
          `db_dsn_enc`, `plan`, `status`, `staff_count`) \
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', 0)",
    )
    .bind(&new.company_name)
    .bind(&new.company_short_name)
    .bind(&new.contact_name)
    .bind(&new.contact_phone)
    .bind(&new.contact_email)
    .bind(&new.contact_wechat)
    .bind(&new.address)
    .bind(&new.remark)
    .bind(&new.subdomain)
    .bind(&new.db_dsn_enc)
    .bind(&new.plan)
    .execute(master)
    .await?;

    Ok(result.last_insert_id() as TenantId)
}

/// 编辑租户基础信息：公司名 / 子域名 / 套餐，以及（可选）DSN（Requirements 7.4）。
///
/// `update.db_dsn_enc` 为 `None` 时不触碰 `db_dsn_enc` 列，保留既有连接信息。
/// **不**在此修改 `status`（状态切换走 [`set_tenant_status`]，以便配套失效连接池）。
/// 套餐非法 → [`AppError::Validation`]。返回是否命中并更新了某行（`false` 表示该 id 不存在）。
pub async fn update_tenant(
    master: &MySqlPool,
    id: TenantId,
    update: &TenantUpdate,
) -> Result<bool, AppError> {
    validate_plan(&update.plan)?;

    let result = match &update.db_dsn_enc {
        Some(dsn) => {
            sqlx::query(
                "UPDATE `tenants` SET `company_name` = ?, `company_short_name` = ?, \
                 `contact_name` = ?, `contact_phone` = ?, `contact_email` = ?, \
                 `contact_wechat` = ?, `address` = ?, `remark` = ?, `subdomain` = ?, \
                 `db_dsn_enc` = ?, `plan` = ? WHERE `id` = ?",
            )
            .bind(&update.company_name)
            .bind(&update.company_short_name)
            .bind(&update.contact_name)
            .bind(&update.contact_phone)
            .bind(&update.contact_email)
            .bind(&update.contact_wechat)
            .bind(&update.address)
            .bind(&update.remark)
            .bind(&update.subdomain)
            .bind(dsn)
            .bind(&update.plan)
            .bind(id)
            .execute(master)
            .await?
        }
        None => {
            sqlx::query(
                "UPDATE `tenants` SET `company_name` = ?, `company_short_name` = ?, \
                 `contact_name` = ?, `contact_phone` = ?, `contact_email` = ?, \
                 `contact_wechat` = ?, `address` = ?, `remark` = ?, `subdomain` = ?, \
                 `plan` = ? WHERE `id` = ?",
            )
            .bind(&update.company_name)
            .bind(&update.company_short_name)
            .bind(&update.contact_name)
            .bind(&update.contact_phone)
            .bind(&update.contact_email)
            .bind(&update.contact_wechat)
            .bind(&update.address)
            .bind(&update.remark)
            .bind(&update.subdomain)
            .bind(&update.plan)
            .bind(id)
            .execute(master)
            .await?
        }
    };

    Ok(result.rows_affected() > 0)
}

/// 切换租户状态（停用 `suspended` / 启用 `active`），写主库 `tenants`（Requirements 7.4）。
///
/// 仅校验并写入状态列，**不**负责连接池失效——失效连接池由调用方（handler）在停用成功后
/// 调用 [`crate::db::pool::TenantPoolManager::invalidate`] 完成（见 Requirements 7.4 与设计 4.2）。
/// 状态非法 → [`AppError::Validation`]。返回是否命中某行（`false` 表示该 id 不存在）。
pub async fn set_tenant_status(
    master: &MySqlPool,
    id: TenantId,
    status: &str,
) -> Result<bool, AppError> {
    validate_status(status)?;

    let result = sqlx::query("UPDATE `tenants` SET `status` = ? WHERE `id` = ?")
        .bind(status)
        .bind(id)
        .execute(master)
        .await?;

    Ok(result.rows_affected() > 0)
}

/// 刷新租户员工数缓存（`tenants.staff_count`）。
///
/// 员工实际记录位于租户库，主库只保存用于超管列表和计费估算的缓存值。调用方在员工
/// 新建、启用、禁用后统计租户库启用账号数，再调用本函数写回主库。
pub async fn update_staff_count(
    master: &MySqlPool,
    id: TenantId,
    staff_count: i64,
) -> Result<bool, AppError> {
    let count = i32::try_from(staff_count.max(0))
        .map_err(|_| AppError::Validation("员工数量超出系统支持范围".to_string()))?;
    let result = sqlx::query("UPDATE `tenants` SET `staff_count` = ? WHERE `id` = ?")
        .bind(count)
        .bind(id)
        .execute(master)
        .await?;

    Ok(result.rows_affected() > 0)
}

#[cfg(test)]
mod admin_tests {
    use super::*;
    use sqlx::types::chrono::NaiveDate;

    fn dt(y: i32, mo: u32, d: u32) -> NaiveDateTime {
        NaiveDate::from_ymd_opt(y, mo, d)
            .unwrap()
            .and_hms_opt(0, 0, 0)
            .unwrap()
    }

    fn row(id: TenantId, dsn: &str) -> TenantRow {
        TenantRow {
            id,
            company_name: format!("公司{id}"),
            company_short_name: Some(format!("short{id}")),
            contact_name: Some(format!("contact{id}")),
            contact_phone: Some(format!("phone{id}")),
            contact_email: Some(format!("tenant{id}@example.com")),
            contact_wechat: Some(format!("wechat{id}")),
            address: Some(format!("address{id}")),
            remark: Some(format!("remark{id}")),
            subdomain: format!("sub{id}"),
            db_dsn_enc: dsn.to_string(),
            plan: "basic".into(),
            status: "active".into(),
            staff_count: id as i32,
            created_at: dt(2026, 1, 1),
        }
    }

    #[test]
    fn validate_plan_accepts_known_and_rejects_unknown() {
        for p in VALID_PLANS {
            assert!(validate_plan(p).is_ok());
        }
        let err = validate_plan("gold").unwrap_err();
        assert!(matches!(err, AppError::Validation(_)));
        // 文案可安全回显且包含非法取值，便于前端提示。
        assert!(err.client_message().contains("gold"));
    }

    #[test]
    fn validate_status_only_active_or_suspended() {
        assert!(validate_status("active").is_ok());
        assert!(validate_status("suspended").is_ok());
        for bad in ["", "deleted", "Active", "ACTIVE"] {
            assert!(matches!(validate_status(bad), Err(AppError::Validation(_))));
        }
    }

    #[test]
    fn db_label_masks_credentials() {
        // 含口令的 DSN：标签仅暴露 host:port/database，绝不含用户名或口令。
        let label = db_label_from_enc("mysql://alice:s3cr3t@db.example.com:3307/tenant_42");
        assert_eq!(label, "db.example.com:3307/tenant_42");
        assert!(!label.contains("alice"));
        assert!(!label.contains("s3cr3t"));
    }

    #[test]
    fn db_label_defaults_port_and_handles_invalid() {
        assert_eq!(
            db_label_from_enc("mysql://u:p@localhost/mydb"),
            "localhost:3306/mydb"
        );
        // 无法解析的密文回退为占位，不外泄原始内容。
        assert_eq!(db_label_from_enc("not-a-dsn"), "—");
    }

    #[test]
    fn assemble_summaries_groups_and_sorts_platform_tags() {
        let rows = vec![
            row(1, "mysql://u:p@h1:3306/db1"),
            row(2, "mysql://u:p@h2:3306/db2"),
        ];
        // 故意乱序 + 含重复，验证升序去重。
        let platforms = vec![
            (1, "r".to_string()),
            (1, "y".to_string()),
            (1, "y".to_string()),
            (2, "m".to_string()),
        ];

        let summaries = assemble_summaries(rows, platforms);
        assert_eq!(summaries.len(), 2);

        let t1 = &summaries[0];
        assert_eq!(t1.id, 1);
        assert_eq!(
            t1.authorized_platforms,
            vec!["r".to_string(), "y".to_string()]
        );
        assert_eq!(t1.db_label, "h1:3306/db1");
        assert_eq!(t1.staff_count, 1);

        let t2 = &summaries[1];
        assert_eq!(t2.id, 2);
        assert_eq!(t2.authorized_platforms, vec!["m".to_string()]);
    }

    #[test]
    fn assemble_summaries_tenant_without_platforms_has_empty_tags() {
        let rows = vec![row(7, "mysql://u:p@h:3306/d")];
        let summaries = assemble_summaries(rows, Vec::new());
        assert_eq!(summaries.len(), 1);
        assert!(summaries[0].authorized_platforms.is_empty());
    }

    #[test]
    fn assemble_summaries_preserves_profile_fields() {
        let summaries = assemble_summaries(vec![row(9, "mysql://u:p@h:3306/d")], Vec::new());
        let t = &summaries[0];

        assert_eq!(t.company_short_name.as_deref(), Some("short9"));
        assert_eq!(t.contact_name.as_deref(), Some("contact9"));
        assert_eq!(t.contact_phone.as_deref(), Some("phone9"));
        assert_eq!(t.contact_email.as_deref(), Some("tenant9@example.com"));
        assert_eq!(t.contact_wechat.as_deref(), Some("wechat9"));
        assert_eq!(t.address.as_deref(), Some("address9"));
        assert_eq!(t.remark.as_deref(), Some("remark9"));
    }

    #[test]
    fn assemble_summaries_preserves_input_row_order() {
        // 输入顺序（通常 created_at DESC）应被保留，不被平台聚合打乱。
        let rows = vec![
            row(3, "mysql://u:p@h:3306/d3"),
            row(1, "mysql://u:p@h:3306/d1"),
            row(2, "mysql://u:p@h:3306/d2"),
        ];
        let summaries = assemble_summaries(rows, Vec::new());
        let ids: Vec<TenantId> = summaries.iter().map(|s| s.id).collect();
        assert_eq!(ids, vec![3, 1, 2]);
    }
}

// ───────────────────────────────────────────────────────────────────────────
// 超管后台「概览」聚合查询（Task 11.5 / Requirements 10.1、10.4）
//
// 本节为超管后台「概览」视图提供主库聚合统计与「最近租户列表」，所有查询统一用 SQLx 运行时
// API（`query` / `query_as`，不使用编译期 `query!` 宏），与本仓其余 repository 风格一致。
// 仅访问主库 `master`，绝不触达租户库。
//
// 设计依据：design.md 7.6 超管后台「概览」视图——
//   租户数 / 活跃租户 / 员工总数 / 平台授权数；最近租户列表；系统状态（运行时另行装配）。
// 数据来源：`tenants`（总数 / 活跃数 / 员工数缓存合计）+ `tenant_platform`（已开通授权计数）。
// ───────────────────────────────────────────────────────────────────────────

/// 超管「概览」聚合统计（design.md 7.6「概览」卡片，Requirements 10.1）。
///
/// 全部为基于主库的整型计数，可安全直接展示：
/// - `total_tenants`：租户总数（`tenants` 全表行数）。
/// - `active_tenants`：活跃租户数（`status = 'active'`）。
/// - `total_staff`：员工总数（各租户 `staff_count` 缓存值之和）。
/// - `platform_authorizations`：平台授权数（`tenant_platform.enabled = 1` 的行数）。
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize)]
pub struct OverviewStats {
    pub total_tenants: i64,
    pub active_tenants: i64,
    pub total_staff: i64,
    pub platform_authorizations: i64,
}

/// 「最近租户列表」行（概览视图，design.md 7.6）。
///
/// 较 [`TenantSummary`] 更轻量：概览只需展示基础档案，不聚合平台授权标签、不暴露 DSN。
#[derive(Debug, Clone, PartialEq, Eq, Serialize, FromRow)]
pub struct RecentTenant {
    pub id: TenantId,
    pub company_name: String,
    pub subdomain: String,
    pub plan: String,
    pub status: String,
    pub staff_count: i32,
    pub created_at: NaiveDateTime,
}

/// 读取概览聚合统计（Requirements 10.1）。
///
/// 单条查询用标量子查询一次性取齐四项计数，避免多次往返：
/// - `total_staff` 以 `CAST(COALESCE(SUM(...),0) AS SIGNED)` 归一为有符号整数，
///   既容忍空表（合计为 0），又规避 MySQL `SUM(INT)` 返回 DECIMAL 的解码歧义。
/// 仅访问主库 `master`。
pub async fn overview_stats(master: &MySqlPool) -> Result<OverviewStats, AppError> {
    let (total_tenants, active_tenants, total_staff, platform_authorizations): (i64, i64, i64, i64) =
        sqlx::query_as(
            "SELECT \
               (SELECT COUNT(*) FROM `tenants`) AS total_tenants, \
               (SELECT COUNT(*) FROM `tenants` WHERE `status` = 'active') AS active_tenants, \
               (SELECT CAST(COALESCE(SUM(`staff_count`), 0) AS SIGNED) FROM `tenants`) AS total_staff, \
               (SELECT COUNT(*) FROM `tenant_platform` WHERE `enabled` = 1) AS platform_authorizations",
        )
        .fetch_one(master)
        .await?;

    Ok(OverviewStats {
        total_tenants,
        active_tenants,
        total_staff,
        platform_authorizations,
    })
}

/// 读取「最近租户列表」（Requirements 10.1）。
///
/// 按 `created_at` 倒序、同序再按 `id` 倒序取前 `limit` 条；`limit <= 0` 时归一为 0（返回空列表），
/// 避免把非正数传入 `LIMIT`。仅访问主库 `master`。
pub async fn recent_tenants(master: &MySqlPool, limit: i64) -> Result<Vec<RecentTenant>, AppError> {
    let limit = limit.max(0);

    let rows: Vec<RecentTenant> = sqlx::query_as::<_, RecentTenant>(
        "SELECT `id`, `company_name`, `subdomain`, `plan`, `status`, `staff_count`, `created_at` \
         FROM `tenants` ORDER BY `created_at` DESC, `id` DESC LIMIT ?",
    )
    .bind(limit)
    .fetch_all(master)
    .await?;

    Ok(rows)
}

#[cfg(test)]
mod overview_tests {
    use super::*;

    #[test]
    fn overview_stats_serializes_all_counts() {
        let stats = OverviewStats {
            total_tenants: 12,
            active_tenants: 9,
            total_staff: 134,
            platform_authorizations: 41,
        };
        let json = serde_json::to_value(stats).unwrap();
        assert_eq!(json["total_tenants"], 12);
        assert_eq!(json["active_tenants"], 9);
        assert_eq!(json["total_staff"], 134);
        assert_eq!(json["platform_authorizations"], 41);
    }

    #[test]
    fn recent_tenant_serializes_without_dsn() {
        let t = RecentTenant {
            id: 7,
            company_name: "西阵".into(),
            subdomain: "xizhen".into(),
            plan: "pro".into(),
            status: "active".into(),
            staff_count: 5,
            created_at: NaiveDateTime::default(),
        };
        let json = serde_json::to_value(&t).unwrap();
        assert_eq!(json["id"], 7);
        assert_eq!(json["company_name"], "西阵");
        assert_eq!(json["subdomain"], "xizhen");
        // 概览的最近租户列表不含 DSN/数据库等敏感字段。
        assert!(json.get("db_dsn_enc").is_none());
        assert!(json.get("db_label").is_none());
    }
}
