//! 超管后台「操作日志」页面草案。
//!
//! 当前主库尚无全局 `operation_logs` 表，本页只基于主库已有 `sessions` 与 `tenants`
//! 生成会话审计视图：登录主体、所属租户、会话创建、最近访问、过期时间、IP 与 UA。
//! 安全约束：查询显式排除 `sessions.token`，页面也不展示或派生任何 token 信息。

use axum::{
    extract::{Query, State},
    response::{Html, IntoResponse, Json, Response},
    routing::get,
    Router,
};
use serde::{Deserialize, Serialize};
use sqlx::types::chrono::NaiveDateTime;
use sqlx::FromRow;
use tera::Context;

use crate::error::AppError;
use crate::state::AppState;

const AUDIT_LOGS_TEMPLATE: &str = "admin/audit_logs.html";
const DEFAULT_LIMIT: usize = 50;
const MAX_LIMIT: usize = 100;
const SOURCE_ROW_LIMIT: i64 = 500;

#[derive(Debug, Clone, Default, Deserialize, Serialize)]
pub struct AuditLogQuery {
    #[serde(default)]
    pub tenant_id: Option<i64>,
    #[serde(default)]
    pub principal_kind: Option<String>,
    #[serde(default)]
    pub status: Option<String>,
    #[serde(default)]
    pub q: Option<String>,
    #[serde(default)]
    pub limit: Option<usize>,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct AuditLogFilters {
    pub tenant_id: Option<i64>,
    pub principal_kind: String,
    pub status: String,
    pub q: String,
    pub limit: usize,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct AuditLogEntry {
    pub session_id: i64,
    pub principal_kind: String,
    pub principal_label: String,
    pub principal_id: i64,
    pub tenant_id: Option<i64>,
    pub tenant_label: String,
    pub tenant_status: String,
    pub event_label: String,
    pub status_label: String,
    pub status_class: String,
    pub created_at: NaiveDateTime,
    pub last_seen_at: NaiveDateTime,
    pub expires_at: NaiveDateTime,
    pub ip: String,
    pub user_agent: String,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct AuditLogsView {
    pub filters: AuditLogFilters,
    pub logs: Vec<AuditLogEntry>,
    pub total_source_rows: usize,
    pub visible_rows: usize,
    pub active_sessions: usize,
    pub expired_sessions: usize,
    pub tenant_options: Vec<TenantOption>,
    pub body_class: String,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct TenantOption {
    pub id: i64,
    pub label: String,
}

#[derive(Debug, FromRow)]
struct AuditLogRow {
    session_id: i64,
    principal_kind: String,
    principal_id: i64,
    tenant_id: Option<i64>,
    created_at: NaiveDateTime,
    last_seen_at: NaiveDateTime,
    expires_at: NaiveDateTime,
    is_expired: i64,
    ip: Option<String>,
    user_agent: Option<String>,
    company_name: Option<String>,
    subdomain: Option<String>,
    tenant_status: Option<String>,
}

#[derive(Debug, FromRow)]
struct TenantOptionRow {
    id: i64,
    company_name: String,
    subdomain: String,
}

pub fn routes() -> Router<AppState> {
    Router::new().route("/admin/audit-logs", get(audit_logs))
}

pub async fn audit_logs(
    State(state): State<AppState>,
    Query(query): Query<AuditLogQuery>,
) -> Result<Response, AppError> {
    let filters = normalize_filters(query);
    let rows = fetch_session_audit_rows(state.master_pool()).await?;
    let total_source_rows = rows.len();
    let tenant_options = fetch_tenant_options(state.master_pool()).await?;

    let (active_sessions, expired_sessions) = session_totals(&rows);
    let logs = rows
        .into_iter()
        .map(entry_from_row)
        .filter(|entry| matches_filters(entry, &filters))
        .take(filters.limit)
        .collect::<Vec<_>>();

    let view = AuditLogsView {
        visible_rows: logs.len(),
        logs,
        filters,
        total_source_rows,
        active_sessions,
        expired_sessions,
        tenant_options,
        body_class: "admin-identity".to_string(),
    };

    Ok(render_audit_logs(state.tera(), &view))
}

fn normalize_filters(query: AuditLogQuery) -> AuditLogFilters {
    let principal_kind = match query
        .principal_kind
        .unwrap_or_default()
        .trim()
        .to_ascii_lowercase()
        .as_str()
    {
        "super_admin" => "super_admin",
        "company_admin" => "company_admin",
        "employee" => "employee",
        _ => "",
    }
    .to_string();

    let status = match query
        .status
        .unwrap_or_default()
        .trim()
        .to_ascii_lowercase()
        .as_str()
    {
        "active" => "active",
        "expired" => "expired",
        _ => "",
    }
    .to_string();

    AuditLogFilters {
        tenant_id: query.tenant_id.filter(|id| *id > 0),
        principal_kind,
        status,
        q: query.q.unwrap_or_default().trim().to_string(),
        limit: query.limit.unwrap_or(DEFAULT_LIMIT).clamp(1, MAX_LIMIT),
    }
}

async fn fetch_session_audit_rows(master: &sqlx::MySqlPool) -> Result<Vec<AuditLogRow>, AppError> {
    let rows = sqlx::query_as::<_, AuditLogRow>(
        "SELECT \
            s.`id` AS `session_id`, \
            s.`principal_kind`, \
            s.`principal_id`, \
            s.`tenant_id`, \
            s.`created_at`, \
            s.`last_seen_at`, \
            s.`expires_at`, \
            CASE WHEN s.`expires_at` <= CURRENT_TIMESTAMP THEN 1 ELSE 0 END AS `is_expired`, \
            s.`ip`, \
            s.`user_agent`, \
            t.`company_name`, \
            t.`subdomain`, \
            t.`status` AS `tenant_status` \
         FROM `sessions` s \
         LEFT JOIN `tenants` t ON t.`id` = s.`tenant_id` \
         ORDER BY s.`last_seen_at` DESC, s.`id` DESC \
         LIMIT ?",
    )
    .bind(SOURCE_ROW_LIMIT)
    .fetch_all(master)
    .await?;

    Ok(rows)
}

async fn fetch_tenant_options(master: &sqlx::MySqlPool) -> Result<Vec<TenantOption>, AppError> {
    let rows = sqlx::query_as::<_, TenantOptionRow>(
        "SELECT `id`, `company_name`, `subdomain` FROM `tenants` \
         ORDER BY `company_name` ASC, `id` ASC",
    )
    .fetch_all(master)
    .await?;

    Ok(rows
        .into_iter()
        .map(|row| TenantOption {
            id: row.id,
            label: format!("{} / {}", row.company_name, row.subdomain),
        })
        .collect())
}

fn session_totals(rows: &[AuditLogRow]) -> (usize, usize) {
    rows.iter().fold((0, 0), |(active, expired), row| {
        if row.is_expired == 0 {
            (active + 1, expired)
        } else {
            (active, expired + 1)
        }
    })
}

fn entry_from_row(row: AuditLogRow) -> AuditLogEntry {
    let tenant_label = match (&row.company_name, &row.subdomain) {
        (Some(company), Some(subdomain)) => format!("{company} / {subdomain}"),
        (Some(company), None) => company.clone(),
        (None, Some(subdomain)) => subdomain.clone(),
        (None, None) => "平台级".to_string(),
    };

    let (status_label, status_class, event_label) = if row.is_expired == 0 {
        ("有效", "ok", "会话活动")
    } else {
        ("已过期", "stop", "会话过期")
    };

    AuditLogEntry {
        session_id: row.session_id,
        principal_label: principal_label(&row.principal_kind),
        principal_kind: row.principal_kind,
        principal_id: row.principal_id,
        tenant_id: row.tenant_id,
        tenant_label,
        tenant_status: row.tenant_status.unwrap_or_else(|| "-".to_string()),
        event_label: event_label.to_string(),
        status_label: status_label.to_string(),
        status_class: status_class.to_string(),
        created_at: row.created_at,
        last_seen_at: row.last_seen_at,
        expires_at: row.expires_at,
        ip: row.ip.unwrap_or_else(|| "-".to_string()),
        user_agent: row.user_agent.unwrap_or_else(|| "-".to_string()),
    }
}

fn principal_label(kind: &str) -> String {
    match kind {
        "super_admin" => "超管".to_string(),
        "company_admin" => "公司管理员".to_string(),
        "employee" => "员工".to_string(),
        _ => kind.to_string(),
    }
}

fn matches_filters(entry: &AuditLogEntry, filters: &AuditLogFilters) -> bool {
    if let Some(tenant_id) = filters.tenant_id {
        if entry.tenant_id != Some(tenant_id) {
            return false;
        }
    }

    if !filters.principal_kind.is_empty() && entry.principal_kind != filters.principal_kind {
        return false;
    }

    if filters.status == "active" && entry.status_class != "ok" {
        return false;
    }
    if filters.status == "expired" && entry.status_class != "stop" {
        return false;
    }

    if filters.q.is_empty() {
        return true;
    }

    let q = filters.q.to_lowercase();
    entry.session_id.to_string().contains(&q)
        || entry.principal_id.to_string().contains(&q)
        || entry.principal_kind.to_lowercase().contains(&q)
        || entry.principal_label.to_lowercase().contains(&q)
        || entry.tenant_label.to_lowercase().contains(&q)
        || entry.ip.to_lowercase().contains(&q)
        || entry.user_agent.to_lowercase().contains(&q)
}

fn render_audit_logs(tera: &tera::Tera, view: &AuditLogsView) -> Response {
    match Context::from_serialize(view) {
        Ok(ctx) => match tera.render(AUDIT_LOGS_TEMPLATE, &ctx) {
            Ok(html) => Html(html).into_response(),
            Err(e) => {
                tracing::warn!(error = %e, template = AUDIT_LOGS_TEMPLATE, "操作日志模板渲染失败，回退 JSON");
                Json(view).into_response()
            }
        },
        Err(e) => {
            tracing::warn!(error = %e, "操作日志上下文序列化失败，回退 JSON");
            Json(view).into_response()
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use sqlx::types::chrono::NaiveDate;

    fn dt() -> NaiveDateTime {
        NaiveDate::from_ymd_opt(2026, 1, 1)
            .unwrap()
            .and_hms_opt(0, 0, 0)
            .unwrap()
    }

    fn row(is_expired: i64) -> AuditLogRow {
        AuditLogRow {
            session_id: 9,
            principal_kind: "employee".to_string(),
            principal_id: 12,
            tenant_id: Some(3),
            created_at: dt(),
            last_seen_at: dt(),
            expires_at: dt(),
            is_expired,
            ip: Some("127.0.0.1".to_string()),
            user_agent: Some("test-agent".to_string()),
            company_name: Some("西阵".to_string()),
            subdomain: Some("xizhen".to_string()),
            tenant_status: Some("active".to_string()),
        }
    }

    #[test]
    fn normalize_filters_clamps_limit_and_rejects_unknown_values() {
        let filters = normalize_filters(AuditLogQuery {
            principal_kind: Some("unknown".to_string()),
            status: Some("bad".to_string()),
            limit: Some(500),
            ..AuditLogQuery::default()
        });

        assert_eq!(filters.principal_kind, "");
        assert_eq!(filters.status, "");
        assert_eq!(filters.limit, MAX_LIMIT);
    }

    #[test]
    fn entry_uses_session_status_without_token() {
        let entry = entry_from_row(row(1));

        assert_eq!(entry.event_label, "会话过期");
        assert_eq!(entry.status_class, "stop");
        assert_eq!(entry.tenant_label, "西阵 / xizhen");
    }

    #[test]
    fn filters_match_tenant_principal_status_and_query() {
        let entry = entry_from_row(row(0));
        let filters = AuditLogFilters {
            tenant_id: Some(3),
            principal_kind: "employee".to_string(),
            status: "active".to_string(),
            q: "test-agent".to_string(),
            limit: 50,
        };

        assert!(matches_filters(&entry, &filters));
    }
}
