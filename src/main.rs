//! Application entrypoint.

mod config;
mod db;
mod error;
mod handlers;
mod integrations;
mod jobs;
mod middleware;
mod models;
mod repository;
mod services;
mod state;

use std::sync::Arc;
use std::time::Duration;

use async_trait::async_trait;
use axum::extract::Request;
use axum::http::HeaderMap;
use axum::middleware as axum_middleware;
use axum::middleware::Next;
use axum::response::{IntoResponse, Redirect, Response};
use axum::routing::get;
use axum::{Extension, Router};
use sqlx::mysql::MySqlPoolOptions;
use tera::Tera;

use crate::config::AppConfig;
use crate::error::AppError;
use crate::handlers::mail::SharedMailGateway;
use crate::integrations::{
    Carrier, CarrierTracker, LogisticsTrace, MailAccount, MailFolder, MailGateway, ProductInfo,
    PurchaseProvider, ReplyResult, SyncReport, TrackResult,
};
use crate::jobs::cleanup_old_images::DirStore;
use crate::jobs::daily_maintain::DailyMaintainJob;
use crate::jobs::scheduler::{
    Job, JobContext, JobScheduler, MasterTenantProvider, MysqlAdvisoryLock,
};
use crate::jobs::zhutu_downloader::{FetchedImage, ImageCandidate, ImageFetcher, ImageStore};
use crate::models::user::{FeatureModule, Principal};
use crate::state::AppState;

const TEMPLATE_GLOB: &str = "src/templates/**/*.html";
const ORDER_MONITOR_CURSOR_DIR: &str = "runtime/order_monitor";
pub(crate) const SAAS_ADMIN_HOST: &str = "saas.xizhends.com";

#[tokio::main]
async fn main() -> anyhow::Result<()> {
    let config = AppConfig::from_env()?;
    let listen_addr = config.listen_addr;

    let state = build_state(config).await?;
    let app = build_router(state.clone());

    let scheduler = build_scheduler(&state);
    let scheduler_job_count = scheduler.job_count();
    let _scheduler_handles = scheduler.spawn();
    tracing::info!(jobs = scheduler_job_count, "job scheduler started");

    let listener = tokio::net::TcpListener::bind(listen_addr).await?;
    tracing::info!(addr = %listen_addr, "axum server listening");
    axum::serve(listener, app).await?;

    Ok(())
}

async fn build_state(config: AppConfig) -> Result<AppState, AppError> {
    let master = MySqlPoolOptions::new()
        .max_connections(config.max_conns_per_tenant)
        .connect(&config.master_db_dsn)
        .await
        .map_err(|e| {
            tracing::error!(error = %e, "failed to build master database pool");
            AppError::PoolBuildFailed
        })?;

    db::migrate::run_master_migrations(&master).await?;

    let tera = match Tera::new(TEMPLATE_GLOB) {
        Ok(tera) => tera,
        Err(e) => {
            tracing::warn!(error = %e, glob = TEMPLATE_GLOB, "failed to load templates; using empty Tera");
            Tera::default()
        }
    };

    Ok(AppState::from_parts(master, tera, config))
}

fn build_router(state: AppState) -> Router {
    let tenant_routes = build_tenant_routes()
        .layer(axum_middleware::from_fn_with_state(
            state.clone(),
            middleware::session::require_session,
        ))
        .layer(axum_middleware::from_fn_with_state(
            state.clone(),
            middleware::tenant::tenant_middleware,
        ));

    let admin_routes = build_admin_routes()
        .layer(axum_middleware::from_fn(require_super_admin))
        .layer(axum_middleware::from_fn_with_state(
            state.clone(),
            middleware::session::require_session,
        ));

    Router::new()
        .route("/", get(root_dispatch))
        .route("/healthz", get(healthz))
        .merge(handlers::auth::routes())
        .merge(admin_routes)
        .merge(tenant_routes)
        .fallback(not_found)
        .with_state(state)
}

fn build_tenant_routes() -> Router<AppState> {
    let mail_gateway: SharedMailGateway = Arc::new(UnavailableMailGateway);
    let mail_routes = handlers::mail::routes()
        .layer(Extension(mail_gateway))
        .layer(axum_middleware::from_fn(
            middleware::permission::require_feature(FeatureModule::KefuMail),
        ));

    Router::new()
        .merge(handlers::dashboard::routes())
        .merge(handlers::order_list::routes())
        .merge(handlers::order_save::routes())
        .merge(handlers::jp_shipment::routes())
        .merge(handlers::search::routes())
        .merge(handlers::company_users::routes())
        .merge(handlers::import_export::routes())
        .merge(mail_routes)
    // TODO(18.1): Map the remaining tenant routes to concrete FeatureModule
    // guards once each handler declares its required module.
}

fn build_admin_routes() -> Router<AppState> {
    Router::new()
        .merge(handlers::admin::overview::routes())
        .merge(handlers::admin::health::routes())
        .merge(handlers::admin::databases::routes())
        .merge(handlers::admin::audit_logs::routes())
        .merge(handlers::admin::billing::routes())
        .merge(handlers::admin::tenants::routes())
        .merge(handlers::admin::platform_auth::routes())
        .merge(handlers::admin::announcements::routes())
}

fn build_scheduler(state: &AppState) -> JobScheduler {
    let provider = Arc::new(MasterTenantProvider::from_state(state));
    let lock = Arc::new(MysqlAdvisoryLock::new(state.master_pool().clone()));
    let carrier_tracker: Arc<dyn CarrierTracker> = Arc::new(UnavailableCarrierTracker);
    let image_fetcher: Arc<dyn ImageFetcher> = Arc::new(UnavailableImageFetcher);
    let image_store: Arc<dyn ImageStore> = Arc::new(NoopImageStore);
    let dir_store: Arc<dyn DirStore> = Arc::new(NoopDirStore);

    JobScheduler::new(provider, lock)
        .register(Arc::new(
            jobs::update_1688_logistics::Update1688LogisticsJob::new(UnavailablePurchaseProvider),
        ))
        .register(Arc::new(
            jobs::update_jpship_logistics::UpdateJpshipLogisticsJob::new(carrier_tracker),
        ))
        .register(Arc::new(jobs::order_monitor::OrderMonitorJob::new(
            ORDER_MONITOR_CURSOR_DIR,
        )))
        .register(Arc::new(jobs::zhutu_downloader::ZhutuDownloaderJob::new(
            image_fetcher,
            image_store,
        )))
        .register(Arc::new(jobs::order_archive::OrderArchiveJob::new()))
        .register(Arc::new(MailSyncJob))
        .register(Arc::new(
            jobs::caigou_status_stats::CaigouStatusStatsJob::new(),
        ))
        .register(Arc::new(
            jobs::cleanup_old_images::CleanupOldImagesJob::new(dir_store),
        ))
        .register(Arc::new(DailyMaintainJob::new(state.pools().clone())))
}

async fn require_super_admin(req: Request, next: Next) -> Response {
    let is_htmx = req.headers().get("HX-Request").is_some();
    if matches!(
        req.extensions().get::<Principal>(),
        Some(Principal::SuperAdmin)
    ) {
        next.run(req).await
    } else {
        AppError::Forbidden.into_response_with(is_htmx)
    }
}

async fn healthz() -> &'static str {
    "ok"
}

async fn root_dispatch(headers: HeaderMap) -> Redirect {
    if is_saas_admin_host(header_host(&headers).as_deref()) {
        Redirect::to("/admin/overview")
    } else {
        Redirect::to("/dashboard")
    }
}

pub(crate) fn header_host(headers: &HeaderMap) -> Option<String> {
    headers
        .get("x-forwarded-host")
        .or_else(|| headers.get("host"))
        .and_then(|value| value.to_str().ok())
        .and_then(|raw| raw.split(',').next())
        .map(str::trim)
        .filter(|host| !host.is_empty())
        .map(ToOwned::to_owned)
}

pub(crate) fn is_saas_admin_host(host: Option<&str>) -> bool {
    let Some(host) = host else {
        return false;
    };

    let host = host
        .split(':')
        .next()
        .unwrap_or(host)
        .trim()
        .trim_end_matches('.')
        .to_ascii_lowercase();

    host == SAAS_ADMIN_HOST
}

async fn not_found() -> Response {
    AppError::NotFound.into_response()
}

fn unavailable(provider: &str, detail: &str) -> AppError {
    AppError::ExternalApi {
        provider: provider.to_string(),
        detail: detail.to_string(),
    }
}

#[derive(Clone, Copy)]
struct UnavailablePurchaseProvider;

#[async_trait]
impl PurchaseProvider for UnavailablePurchaseProvider {
    async fn query_logistics(&self, _order_no: &str) -> Result<LogisticsTrace, AppError> {
        Err(unavailable("1688", "purchase provider is not wired"))
    }

    async fn fetch_product(&self, _item_id: &str) -> Result<ProductInfo, AppError> {
        Err(unavailable("1688", "purchase provider is not wired"))
    }
}

struct UnavailableCarrierTracker;

#[async_trait]
impl CarrierTracker for UnavailableCarrierTracker {
    fn detect_carrier(&self, _ship_number: &str) -> Option<Carrier> {
        None
    }

    async fn track(&self, _carrier: Carrier, _ship_number: &str) -> Result<TrackResult, AppError> {
        Err(unavailable("jpship", "carrier tracker is not wired"))
    }
}

struct UnavailableMailGateway;

#[async_trait]
impl MailGateway for UnavailableMailGateway {
    async fn list_folders(&self, _account: &MailAccount) -> Result<Vec<MailFolder>, AppError> {
        Err(unavailable("mail", "mail gateway is not wired"))
    }

    async fn sync_folder(&self, _folder: &MailFolder, _limit: u32) -> Result<SyncReport, AppError> {
        Err(unavailable("mail", "mail gateway is not wired"))
    }

    async fn load_body(&self, _msg_id: i64) -> Result<String, AppError> {
        Err(unavailable("mail", "mail gateway is not wired"))
    }

    async fn reply(&self, _msg_id: i64, _body: &str) -> Result<ReplyResult, AppError> {
        Err(unavailable("mail", "mail gateway is not wired"))
    }
}

struct UnavailableImageFetcher;

#[async_trait]
impl ImageFetcher for UnavailableImageFetcher {
    async fn fetch(&self, _candidate: &ImageCandidate) -> Result<Option<FetchedImage>, AppError> {
        Ok(None)
    }
}

struct NoopImageStore;

#[async_trait]
impl ImageStore for NoopImageStore {
    async fn save(&self, _relative_path: &str, _bytes: &[u8]) -> Result<(), AppError> {
        Ok(())
    }
}

struct NoopDirStore;

#[async_trait]
impl DirStore for NoopDirStore {
    async fn list_subdirs(&self, _root: &str) -> Result<Vec<String>, AppError> {
        Ok(Vec::new())
    }

    async fn delete_subdir(&self, _root: &str, _name: &str) -> Result<(), AppError> {
        Ok(())
    }
}

struct MailSyncJob;

#[async_trait]
impl Job for MailSyncJob {
    fn name(&self) -> &'static str {
        "mail_sync"
    }

    fn interval(&self) -> Duration {
        Duration::from_secs(10 * 60)
    }

    async fn run_for_tenant(&self, ctx: &JobContext) -> Result<(), AppError> {
        // TODO(18.2): Replace this minimal registry entry once jobs::mail_sync
        // exposes a real Job implementation wired to MailGateway credentials.
        tracing::debug!(tenant_id = ctx.tenant_id, "mail_sync job is not wired");
        Ok(())
    }
}

#[cfg(test)]
mod root_dispatch_tests {
    use super::*;
    use axum::http::HeaderValue;

    #[test]
    fn saas_admin_host_matches_exact_domain_case_insensitive() {
        assert!(is_saas_admin_host(Some("saas.xizhends.com")));
        assert!(is_saas_admin_host(Some("SAAS.XIZHENDS.COM")));
        assert!(is_saas_admin_host(Some("saas.xizhends.com.")));
        assert!(is_saas_admin_host(Some("saas.xizhends.com:80")));
    }

    #[test]
    fn tenant_hosts_do_not_match_saas_admin() {
        assert!(!is_saas_admin_host(Some("erp.xizhends.com")));
        assert!(!is_saas_admin_host(Some("xizhends.com")));
        assert!(!is_saas_admin_host(None));
    }

    #[test]
    fn forwarded_host_takes_precedence_for_root_dispatch() {
        let mut headers = HeaderMap::new();
        headers.insert("host", HeaderValue::from_static("127.0.0.1"));
        headers.insert(
            "x-forwarded-host",
            HeaderValue::from_static("saas.xizhends.com, proxy.internal"),
        );

        assert_eq!(header_host(&headers).as_deref(), Some("saas.xizhends.com"));
    }
}
