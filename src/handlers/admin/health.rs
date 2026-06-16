//! 超管后台「服务器健康」页面。
//!
//! `/healthz` 保持给机器探针使用的裸文本响应；本模块只负责超管后台内页，
//! 展示可运维的健康摘要，并避免输出 DSN、密钥、口令、请求来源 IP 等敏感信息。

use std::time::{SystemTime, UNIX_EPOCH};

use axum::{
    extract::State,
    http::HeaderMap,
    response::{Html, IntoResponse, Json, Response},
    routing::get,
    Router,
};
use serde::Serialize;
use tera::Context;

use crate::error::AppError;
use crate::state::AppState;

const HEALTH_TEMPLATE: &str = "admin/health.html";

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct HealthCard {
    pub name: String,
    pub status_label: String,
    pub status_class: String,
    pub headline: String,
    pub detail: String,
    pub signals: Vec<String>,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct HealthView {
    pub overall_label: String,
    pub overall_class: String,
    pub checked_at_unix: u64,
    pub cards: Vec<HealthCard>,
    pub body_class: String,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct HealthInputs {
    pub master_db_ok: bool,
    pub tenant_pool_count: usize,
    pub master_pool_size: u32,
    pub master_pool_idle: usize,
    pub max_conns_per_tenant: u32,
    pub tenant_pool_idle_ttl_secs: u64,
    pub scheduler_registered: bool,
    pub reverse_proxy_detected: bool,
    pub process_id: u32,
    pub listen_addr_present: bool,
}

pub fn routes() -> Router<AppState> {
    Router::new().route("/admin/health", get(health))
}

pub async fn health(
    State(state): State<AppState>,
    headers: HeaderMap,
) -> Result<Response, AppError> {
    let master_db_ok = sqlx::query("SELECT 1")
        .execute(state.master_pool())
        .await
        .map(|_| true)
        .unwrap_or_else(|e| {
            tracing::warn!(error = %e, "服务器健康页主库探测失败");
            false
        });

    let inputs = HealthInputs {
        master_db_ok,
        tenant_pool_count: state.pools().cached_tenant_count(),
        master_pool_size: state.master_pool().size(),
        master_pool_idle: state.master_pool().num_idle(),
        max_conns_per_tenant: state.config().max_conns_per_tenant,
        tenant_pool_idle_ttl_secs: state.config().tenant_pool_idle_ttl.as_secs(),
        scheduler_registered: true,
        reverse_proxy_detected: has_reverse_proxy_headers(&headers),
        process_id: std::process::id(),
        listen_addr_present: true,
    };

    let view = build_health_view(inputs, unix_now());
    Ok(render_health(state.tera(), &view))
}

pub fn build_health_view(inputs: HealthInputs, checked_at_unix: u64) -> HealthView {
    let cards = vec![
        status_card(
            "主库",
            inputs.master_db_ok,
            if inputs.master_db_ok {
                "可连接"
            } else {
                "不可连接"
            },
            "主库连接池用于租户档案、超管账号、平台授权与公告数据。",
            vec![
                format!("当前连接数：{}", inputs.master_pool_size),
                format!("空闲连接数：{}", inputs.master_pool_idle),
                "探测方式：SELECT 1".to_string(),
            ],
        ),
        status_card(
            "租户连接池",
            true,
            &format!("{} 个已缓存", inputs.tenant_pool_count),
            "租户库连接池按需创建，空闲后由维护任务回收。",
            vec![
                format!("单租户连接上限：{}", inputs.max_conns_per_tenant),
                format!("空闲回收阈值：{} 秒", inputs.tenant_pool_idle_ttl_secs),
                "敏感 DSN 不在页面展示".to_string(),
            ],
        ),
        status_card(
            "调度器",
            inputs.scheduler_registered,
            if inputs.scheduler_registered {
                "随应用启动"
            } else {
                "未注册"
            },
            "后台任务负责物流同步、订单监控、图片下载、归档与连接池维护。",
            vec![
                "任务锁：MySQL advisory lock".to_string(),
                "失败隔离：单租户失败不阻断其它租户".to_string(),
                "运行明细请查看服务日志".to_string(),
            ],
        ),
        status_card(
            "Nginx / 反代",
            true,
            if inputs.reverse_proxy_detected {
                "检测到转发头"
            } else {
                "当前请求未带转发头"
            },
            "页面只判断请求是否经过反向代理，不展示来源地址或完整头值。",
            vec![
                "识别头：X-Forwarded-*".to_string(),
                "健康探针：/healthz 保持裸文本 ok".to_string(),
                "后台入口：/admin/health".to_string(),
            ],
        ),
        status_card(
            "应用进程",
            inputs.listen_addr_present,
            &format!("PID {}", inputs.process_id),
            "Axum 应用进程正在处理当前后台请求。",
            vec![
                "运行时：tokio multi-thread".to_string(),
                "模板：Tera 后台布局".to_string(),
                "监听地址不输出具体绑定值".to_string(),
            ],
        ),
    ];

    let all_ok = cards.iter().all(|card| card.status_class == "ok");
    HealthView {
        overall_label: if all_ok {
            "全部正常".to_string()
        } else {
            "存在异常".to_string()
        },
        overall_class: if all_ok { "ok" } else { "stop" }.to_string(),
        checked_at_unix,
        cards,
        body_class: "admin-identity".to_string(),
    }
}

fn status_card(
    name: &str,
    ok: bool,
    headline: &str,
    detail: &str,
    signals: Vec<String>,
) -> HealthCard {
    HealthCard {
        name: name.to_string(),
        status_label: if ok { "正常" } else { "异常" }.to_string(),
        status_class: if ok { "ok" } else { "stop" }.to_string(),
        headline: headline.to_string(),
        detail: detail.to_string(),
        signals,
    }
}

fn has_reverse_proxy_headers(headers: &HeaderMap) -> bool {
    headers.contains_key("x-forwarded-for")
        || headers.contains_key("x-forwarded-host")
        || headers.contains_key("x-forwarded-proto")
        || headers.contains_key("x-real-ip")
}

fn render_health(tera: &tera::Tera, view: &HealthView) -> Response {
    match Context::from_serialize(view) {
        Ok(ctx) => match tera.render(HEALTH_TEMPLATE, &ctx) {
            Ok(html) => Html(html).into_response(),
            Err(e) => {
                tracing::warn!(error = %e, template = HEALTH_TEMPLATE, "健康页模板渲染失败，回退 JSON");
                Json(view).into_response()
            }
        },
        Err(e) => {
            tracing::warn!(error = %e, "健康页上下文序列化失败，回退 JSON");
            Json(view).into_response()
        }
    }
}

fn unix_now() -> u64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.as_secs())
        .unwrap_or(0)
}

#[cfg(test)]
mod tests {
    use super::*;
    use axum::http::{HeaderMap, HeaderValue, StatusCode};

    fn healthy_inputs() -> HealthInputs {
        HealthInputs {
            master_db_ok: true,
            tenant_pool_count: 3,
            master_pool_size: 2,
            master_pool_idle: 1,
            max_conns_per_tenant: 8,
            tenant_pool_idle_ttl_secs: 600,
            scheduler_registered: true,
            reverse_proxy_detected: true,
            process_id: 42,
            listen_addr_present: true,
        }
    }

    #[test]
    fn build_health_view_contains_required_system_blocks() {
        let view = build_health_view(healthy_inputs(), 123);
        let names: Vec<_> = view.cards.iter().map(|card| card.name.as_str()).collect();
        assert_eq!(
            names,
            ["主库", "租户连接池", "调度器", "Nginx / 反代", "应用进程"]
        );
        assert_eq!(view.overall_label, "全部正常");
        assert_eq!(view.overall_class, "ok");
        assert_eq!(view.checked_at_unix, 123);
    }

    #[test]
    fn build_health_view_marks_overall_unhealthy_when_master_db_fails() {
        let mut inputs = healthy_inputs();
        inputs.master_db_ok = false;
        let view = build_health_view(inputs, 123);
        assert_eq!(view.overall_label, "存在异常");
        assert_eq!(view.overall_class, "stop");
        assert_eq!(view.cards[0].status_class, "stop");
    }

    #[test]
    fn health_view_does_not_include_sensitive_config_values() {
        let view = build_health_view(healthy_inputs(), 123);
        let json = serde_json::to_string(&view).unwrap();
        assert!(!json.contains("mysql://"));
        assert!(!json.contains("password"));
        assert!(!json.contains("SESSION_SECRET"));
    }

    #[test]
    fn reverse_proxy_detection_uses_header_names_only() {
        let mut headers = HeaderMap::new();
        assert!(!has_reverse_proxy_headers(&headers));
        headers.insert(
            "x-forwarded-host",
            HeaderValue::from_static("saas.xizhends.com"),
        );
        assert!(has_reverse_proxy_headers(&headers));
    }

    #[test]
    fn render_health_falls_back_to_json_when_template_missing() {
        let tera = tera::Tera::default();
        let view = build_health_view(healthy_inputs(), 123);
        let resp = render_health(&tera, &view);
        assert_eq!(resp.status(), StatusCode::OK);
    }
}
