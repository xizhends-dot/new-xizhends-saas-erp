//! 导入/同步与店铺管理处理器。
//!
//! 本切片补 ERP 租户侧店铺管理与乐天 RMS 导入/同步入口。

use axum::{
    extract::{Extension, Path, State},
    response::{Html, IntoResponse, Redirect, Response},
    routing::{get, post},
    Form, Router,
};
use serde::{Deserialize, Serialize};
use tera::Context;

use crate::error::AppError;
use crate::integrations::rakuten_rms::UnwiredRakutenRmsProvider;
use crate::middleware::tenant::TenantContext;
use crate::models::platform::Platform;
use crate::models::user::{FeatureModule, Principal};
use crate::repository::store_repo::{
    self, NewStore, RakutenCredentialInput, StoreSummary, StoreUpdate,
};
use crate::services::import;
use crate::services::order_import_service::{self, ImportOperator, ImportReport};
use crate::services::platform_auth_service::{self, PlatformMenuItem};
use crate::services::rakuten_sync_service::{self, RakutenSyncReport};
use crate::state::AppState;

const STORES_TEMPLATE: &str = "tenant/stores.html";
const STORE_IMPORT_TEMPLATE: &str = "tenant/store_import.html";

const STORES_PATH: &str = "/stores";
const SETTINGS_STORES_PATH: &str = "/settings/stores";
const RAKUTEN_NEW_PATH: &str = "/stores/rakuten";
const SETTINGS_RAKUTEN_NEW_PATH: &str = "/settings/stores/rakuten";
const STORE_IMPORT_PATH: &str = "/stores/:store_id/import";
const SETTINGS_STORE_IMPORT_PATH: &str = "/settings/stores/:store_id/import";
const STORE_CREDENTIALS_PATH: &str = "/stores/:store_id/rakuten/credentials";
const SETTINGS_STORE_CREDENTIALS_PATH: &str = "/settings/stores/:store_id/rakuten/credentials";
const STORE_SYNC_PATH: &str = "/stores/:store_id/rakuten/sync";
const SETTINGS_STORE_SYNC_PATH: &str = "/settings/stores/:store_id/rakuten/sync";

#[derive(Debug, Deserialize)]
pub struct NewRakutenStoreForm {
    pub dpqz: String,
    pub dpquancheng: String,
    #[serde(default)]
    pub is_hidden: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct StoreEditForm {
    pub dpqz: String,
    pub dpquancheng: String,
    #[serde(default)]
    pub is_hidden: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct RakutenCredentialsForm {
    pub service_secret: String,
    pub license_key: String,
}

#[derive(Debug, Deserialize)]
pub struct ManualImportForm {
    pub csv_text: String,
    #[serde(default = "default_import_action")]
    pub action: String,
}

#[derive(Debug, Clone, Serialize)]
struct StoresView {
    tenant_name: String,
    tenant_id: i64,
    active_nav: String,
    active_platform: Option<String>,
    platform_menu: Vec<PlatformMenuItem>,
    purchase_count: i64,
    jpstock_count: i64,
    stores: Vec<StoreRowView>,
    flash: Option<String>,
    error: Option<String>,
}

#[derive(Debug, Clone, Serialize)]
struct StoreImportView {
    tenant_name: String,
    tenant_id: i64,
    active_nav: String,
    active_platform: Option<String>,
    platform_menu: Vec<PlatformMenuItem>,
    purchase_count: i64,
    jpstock_count: i64,
    store: StoreRowView,
    report: Option<RakutenSyncReport>,
    import_report: Option<ImportReport>,
    flash: Option<String>,
    error: Option<String>,
}

#[derive(Debug, Clone, Serialize)]
struct StoreRowView {
    id: i64,
    platform: String,
    platform_label: String,
    dpqz: String,
    dpquancheng: String,
    is_hidden: bool,
    has_rms_credentials: bool,
    masked_service_secret: String,
    masked_license_key: String,
    rms_credentials_updated_at: Option<String>,
    last_sync_at: Option<String>,
    last_sync_status: Option<String>,
    last_sync_message: Option<String>,
    created_at: String,
    updated_at: String,
}

pub fn routes() -> Router<AppState> {
    Router::new()
        .route(STORES_PATH, get(stores_page))
        .route(SETTINGS_STORES_PATH, get(stores_page))
        .route(RAKUTEN_NEW_PATH, post(create_rakuten_store))
        .route(SETTINGS_RAKUTEN_NEW_PATH, post(create_rakuten_store))
        .route("/stores/:store_id", post(update_store))
        .route("/settings/stores/:store_id", post(update_store))
        .route(
            STORE_IMPORT_PATH,
            get(store_import_page).post(import_orders_csv),
        )
        .route(
            SETTINGS_STORE_IMPORT_PATH,
            get(store_import_page).post(import_orders_csv),
        )
        .route(STORE_CREDENTIALS_PATH, post(save_rakuten_credentials))
        .route(
            SETTINGS_STORE_CREDENTIALS_PATH,
            post(save_rakuten_credentials),
        )
        .route(STORE_SYNC_PATH, post(sync_rakuten_orders))
        .route(SETTINGS_STORE_SYNC_PATH, post(sync_rakuten_orders))
}

async fn stores_page(
    State(state): State<AppState>,
    Extension(ctx): Extension<TenantContext>,
    Extension(principal): Extension<Principal>,
) -> Result<Response, AppError> {
    ensure_store_admin(&principal)?;
    let view = build_stores_view(&state, &ctx, None, None).await?;
    Ok(render_stores(state.tera(), &view))
}

async fn update_store(
    State(state): State<AppState>,
    Extension(ctx): Extension<TenantContext>,
    Extension(principal): Extension<Principal>,
    Path(store_id): Path<i64>,
    Form(form): Form<StoreEditForm>,
) -> Result<Response, AppError> {
    ensure_store_admin(&principal)?;
    let update = StoreUpdate {
        dpqz: form.dpqz.trim().to_string(),
        dpquancheng: form.dpquancheng.trim().to_string(),
        is_hidden: form.is_hidden.is_some(),
    };

    match store_repo::update_store(&ctx.pool, store_id, &update).await {
        Ok(true) => Ok(Redirect::to(SETTINGS_STORES_PATH).into_response()),
        Ok(false) => Err(AppError::NotFound),
        Err(AppError::Validation(msg)) => {
            let view = build_stores_view(&state, &ctx, None, Some(msg)).await?;
            Ok(render_stores(state.tera(), &view))
        }
        Err(err) => Err(err),
    }
}

async fn create_rakuten_store(
    State(state): State<AppState>,
    Extension(ctx): Extension<TenantContext>,
    Extension(principal): Extension<Principal>,
    Form(form): Form<NewRakutenStoreForm>,
) -> Result<Response, AppError> {
    ensure_store_admin(&principal)?;
    let input = NewStore {
        platform: "r".to_string(),
        dpqz: form.dpqz.trim().to_string(),
        dpquancheng: form.dpquancheng.trim().to_string(),
        is_hidden: form.is_hidden.is_some(),
    };

    match store_repo::create_store(&ctx.pool, &input).await {
        Ok(_) => Ok(Redirect::to(SETTINGS_STORES_PATH).into_response()),
        Err(AppError::Validation(msg)) => {
            let view = build_stores_view(&state, &ctx, None, Some(msg)).await?;
            Ok(render_stores(state.tera(), &view))
        }
        Err(err) => Err(err),
    }
}

async fn store_import_page(
    State(state): State<AppState>,
    Extension(ctx): Extension<TenantContext>,
    Extension(principal): Extension<Principal>,
    Path(store_id): Path<i64>,
) -> Result<Response, AppError> {
    ensure_store_admin(&principal)?;
    let view = build_import_view(&state, &ctx, store_id, None, None).await?;
    Ok(render_store_import(state.tera(), &view))
}

async fn save_rakuten_credentials(
    State(state): State<AppState>,
    Extension(ctx): Extension<TenantContext>,
    Extension(principal): Extension<Principal>,
    Path(store_id): Path<i64>,
    Form(form): Form<RakutenCredentialsForm>,
) -> Result<Response, AppError> {
    ensure_store_admin(&principal)?;
    let input = RakutenCredentialInput {
        service_secret: form.service_secret.trim().to_string(),
        license_key: form.license_key.trim().to_string(),
    };

    match store_repo::save_rakuten_credentials(&ctx.pool, store_id, &input).await {
        Ok(()) => {
            let view = build_import_view(
                &state,
                &ctx,
                store_id,
                Some("RMS 凭证已保存。".to_string()),
                None,
            )
            .await?;
            Ok(render_store_import(state.tera(), &view))
        }
        Err(AppError::Validation(msg)) => {
            let view = build_import_view(&state, &ctx, store_id, None, Some(msg)).await?;
            Ok(render_store_import(state.tera(), &view))
        }
        Err(err) => Err(err),
    }
}

async fn sync_rakuten_orders(
    State(state): State<AppState>,
    Extension(ctx): Extension<TenantContext>,
    Extension(principal): Extension<Principal>,
    Path(store_id): Path<i64>,
) -> Result<Response, AppError> {
    ensure_store_admin(&principal)?;
    let provider = UnwiredRakutenRmsProvider;
    let report = rakuten_sync_service::sync_rakuten_orders(&ctx.pool, store_id, &provider).await?;
    store_repo::record_sync_status(&ctx.pool, report.store_id, &report.status, &report.message)
        .await?;
    let view = build_import_view(&state, &ctx, store_id, None, None).await?;
    let view = StoreImportView {
        report: Some(report),
        ..view
    };
    Ok(render_store_import(state.tera(), &view))
}

async fn import_orders_csv(
    State(state): State<AppState>,
    Extension(ctx): Extension<TenantContext>,
    Extension(principal): Extension<Principal>,
    Path(store_id): Path<i64>,
    Form(form): Form<ManualImportForm>,
) -> Result<Response, AppError> {
    ensure_store_admin(&principal)?;
    let store = store_repo::get_store_summary(&ctx.pool, store_id)
        .await?
        .ok_or(AppError::NotFound)?;
    if store.is_hidden {
        return Err(AppError::Validation(
            "隐藏店铺不能导入订单，请先在店铺管理中取消隐藏。".to_string(),
        ));
    }

    let platform = Platform::from_code(&store.platform).ok_or_else(|| {
        AppError::Validation(format!("暂不支持平台代码 `{}` 的手动导入", store.platform))
    })?;
    let batch = import::parse_csv_import(platform, &form.csv_text);
    let dry_run = form.action.trim() == "preview";
    let import_report = if dry_run {
        order_import_service::dry_run_report(batch.preview, batch.records.len())
    } else {
        order_import_service::import_records(
            &ctx.pool,
            &store,
            batch.preview,
            batch.records,
            ImportOperator::Manual,
        )
        .await?
    };

    let flash = if import_report.dry_run {
        Some(format!(
            "预检完成：{} 行可导入，{} 个问题。",
            import_report.preview.importable_rows,
            import_report.preview.errors.len()
        ))
    } else {
        Some(format!(
            "导入完成：新增 {} 行，跳过 {} 行，失败 {} 行。",
            import_report.imported_rows, import_report.skipped_rows, import_report.failed_rows
        ))
    };

    let view = build_import_view(&state, &ctx, store_id, flash, None).await?;
    let view = StoreImportView {
        import_report: Some(import_report),
        ..view
    };
    Ok(render_store_import(state.tera(), &view))
}

fn ensure_store_admin(principal: &Principal) -> Result<(), AppError> {
    match principal {
        Principal::SuperAdmin | Principal::CompanyAdmin { .. } => Ok(()),
        Principal::Employee { .. } if principal.can_access(FeatureModule::SystemSettings) => Ok(()),
        _ => Err(AppError::Forbidden),
    }
}

async fn build_stores_view(
    state: &AppState,
    ctx: &TenantContext,
    flash: Option<String>,
    error: Option<String>,
) -> Result<StoresView, AppError> {
    let stores = store_repo::list_store_summaries(&ctx.pool)
        .await?
        .into_iter()
        .map(StoreRowView::from_summary)
        .collect();
    let (platform_menu, purchase_count, jpstock_count) = load_shell_context(state, ctx).await;

    Ok(StoresView {
        tenant_name: ctx.company_name.clone(),
        tenant_id: ctx.tenant_id,
        active_nav: "stores".to_string(),
        active_platform: None,
        platform_menu,
        purchase_count,
        jpstock_count,
        stores,
        flash,
        error,
    })
}

async fn build_import_view(
    state: &AppState,
    ctx: &TenantContext,
    store_id: i64,
    flash: Option<String>,
    error: Option<String>,
) -> Result<StoreImportView, AppError> {
    let store = store_repo::get_store_summary(&ctx.pool, store_id)
        .await?
        .ok_or(AppError::NotFound)?;
    let (platform_menu, purchase_count, jpstock_count) = load_shell_context(state, ctx).await;

    Ok(StoreImportView {
        tenant_name: ctx.company_name.clone(),
        tenant_id: ctx.tenant_id,
        active_nav: "stores".to_string(),
        active_platform: None,
        platform_menu,
        purchase_count,
        jpstock_count,
        store: StoreRowView::from_summary(store),
        report: None,
        import_report: None,
        flash,
        error,
    })
}

async fn load_shell_context(
    state: &AppState,
    ctx: &TenantContext,
) -> (Vec<PlatformMenuItem>, i64, i64) {
    let platform_menu = platform_auth_service::load_sidebar_menu(state.master_pool(), ctx.tenant_id)
        .await
        .unwrap_or_else(|e| {
            tracing::warn!(error = %e, tenant_id = ctx.tenant_id, "平台菜单加载失败，店铺页侧栏将显示基础入口");
            Vec::new()
        });

    let counts = load_queue_counts(&ctx.pool).await.unwrap_or_else(|e| {
        tracing::warn!(error = %e, tenant_id = ctx.tenant_id, "店铺页队列计数加载失败");
        (0, 0)
    });

    (platform_menu, counts.0, counts.1)
}

async fn load_queue_counts(pool: &sqlx::MySqlPool) -> Result<(i64, i64), AppError> {
    let (purchase_count, jpstock_count): (i64, i64) = sqlx::query_as(
        "SELECT \
            (SELECT CAST(COUNT(*) AS SIGNED) FROM `order_items` WHERE `source_type` = 'cn_purchase') AS purchase_count, \
            (SELECT CAST(COUNT(*) AS SIGNED) FROM `order_items` WHERE `source_type` = 'jp_stock') AS jpstock_count",
    )
    .fetch_one(pool)
    .await?;

    Ok((purchase_count, jpstock_count))
}

fn render_stores(tera: &tera::Tera, view: &StoresView) -> Response {
    match Context::from_serialize(view) {
        Ok(ctx) => match tera.render(STORES_TEMPLATE, &ctx) {
            Ok(html) => Html(html).into_response(),
            Err(e) => {
                tracing::warn!(error = %e, template = STORES_TEMPLATE, "店铺列表模板渲染失败");
                Html(render_stores_fallback(view)).into_response()
            }
        },
        Err(e) => {
            tracing::warn!(error = %e, "店铺列表上下文序列化失败");
            Html(render_stores_fallback(view)).into_response()
        }
    }
}

fn render_store_import(tera: &tera::Tera, view: &StoreImportView) -> Response {
    match Context::from_serialize(view) {
        Ok(ctx) => match tera.render(STORE_IMPORT_TEMPLATE, &ctx) {
            Ok(html) => Html(html).into_response(),
            Err(e) => {
                tracing::warn!(error = %e, template = STORE_IMPORT_TEMPLATE, "店铺导入模板渲染失败");
                Html(render_import_fallback(view)).into_response()
            }
        },
        Err(e) => {
            tracing::warn!(error = %e, "店铺导入上下文序列化失败");
            Html(render_import_fallback(view)).into_response()
        }
    }
}

fn render_stores_fallback(view: &StoresView) -> String {
    let mut rows = String::new();
    for store in &view.stores {
        rows.push_str(&format!(
            "<tr><td>{}</td><td>{}</td><td>{}</td><td>{}</td>\
             <td><a href=\"/stores/{}/import\">导入/同步</a></td></tr>",
            html_escape(&store.platform_label),
            html_escape(&store.dpqz),
            html_escape(&store.dpquancheng),
            if store.has_rms_credentials {
                "已保存"
            } else {
                "未保存"
            },
            store.id,
        ));
    }
    format!("<main><h1>店铺管理</h1><table><tbody>{rows}</tbody></table></main>")
}

fn render_import_fallback(view: &StoreImportView) -> String {
    let report = view
        .report
        .as_ref()
        .map(|r| html_escape(&r.message))
        .unwrap_or_default();
    format!(
        "<main><h1>{}</h1><p>乐天 RMS 需要开通 RMS WEB SERVICE Order API，并取得 serviceSecret/licenseKey。</p><p>{}</p></main>",
        html_escape(&view.store.dpquancheng),
        report
    )
}

fn html_escape(s: &str) -> String {
    s.replace('&', "&amp;")
        .replace('<', "&lt;")
        .replace('>', "&gt;")
        .replace('"', "&quot;")
        .replace('\'', "&#39;")
}

fn default_import_action() -> String {
    "import".to_string()
}

impl StoreRowView {
    fn from_summary(store: StoreSummary) -> StoreRowView {
        let platform_label = store.platform_label().to_string();
        let has_rms_credentials = store.has_rms_credentials();
        let masked_service_secret = mask_secret(store.rms_service_secret.as_deref());
        let masked_license_key = mask_secret(store.rms_license_key.as_deref());
        let rms_credentials_updated_at = store
            .rms_credentials_updated_at
            .map(|dt| dt.format("%Y-%m-%d %H:%M:%S").to_string());
        let last_sync_at = store
            .last_sync_at
            .map(|dt| dt.format("%Y-%m-%d %H:%M:%S").to_string());
        let created_at = store.created_at.format("%Y-%m-%d %H:%M:%S").to_string();
        let updated_at = store.updated_at.format("%Y-%m-%d %H:%M:%S").to_string();

        StoreRowView {
            id: store.id,
            platform: store.platform,
            platform_label,
            dpqz: store.dpqz,
            dpquancheng: store.dpquancheng,
            is_hidden: store.is_hidden,
            has_rms_credentials,
            masked_service_secret,
            masked_license_key,
            rms_credentials_updated_at,
            last_sync_at,
            last_sync_status: store.last_sync_status,
            last_sync_message: store.last_sync_message,
            created_at,
            updated_at,
        }
    }
}

fn mask_secret(value: Option<&str>) -> String {
    let Some(value) = value.map(str::trim).filter(|v| !v.is_empty()) else {
        return "未保存".to_string();
    };
    let len = value.chars().count();
    if len <= 8 {
        return "********".to_string();
    }
    let head: String = value.chars().take(4).collect();
    let tail: String = value
        .chars()
        .rev()
        .take(4)
        .collect::<Vec<_>>()
        .into_iter()
        .rev()
        .collect();
    format!("{head}****{tail}")
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn mask_secret_hides_short_values() {
        assert_eq!(mask_secret(None), "未保存");
        assert_eq!(mask_secret(Some("abc")), "********");
        assert_eq!(mask_secret(Some("abcd1234efgh")), "abcd****efgh");
    }
}
