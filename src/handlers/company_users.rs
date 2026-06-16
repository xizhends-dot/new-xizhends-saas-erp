//! 租户侧员工与权限管理。

use axum::{
    extract::{Extension, Form, Path, State},
    response::{Html, IntoResponse, Json, Redirect, Response},
    routing::{get, post},
    Router,
};
use serde::{Deserialize, Serialize};
use tera::Context;

use crate::error::AppError;
use crate::middleware::tenant::TenantContext;
use crate::models::user::{default_permissions, FeatureModule, Principal, Role};
use crate::repository::session_repo::{self, PrincipalKind};
use crate::repository::{tenant_repo, user_repo};
use crate::services::platform_auth_service::{self, PlatformMenuItem};
use crate::state::AppState;

const USERS_TEMPLATE: &str = "tenant/users.html";

#[derive(Debug, Deserialize)]
pub struct CreateUserForm {
    pub username: String,
    pub password: String,
    pub role: String,
    #[serde(default)]
    pub permissions: String,
    #[serde(default)]
    pub all_stores: Option<String>,
    #[serde(default)]
    pub store_ids: String,
}

#[derive(Debug, Deserialize)]
pub struct UpdateUserForm {
    pub role: String,
    #[serde(default)]
    pub permissions: String,
    #[serde(default)]
    pub all_stores: Option<String>,
    #[serde(default)]
    pub store_ids: String,
}

#[derive(Debug, Deserialize)]
pub struct ResetPasswordForm {
    pub password: String,
}

#[derive(Debug, Clone, Serialize)]
struct PermissionOptionView {
    key: String,
    label: String,
    checked: bool,
    default_checked: bool,
}

#[derive(Debug, Clone, Serialize)]
struct RoleOptionView {
    value: String,
    label: String,
    hint: String,
    permissions: Vec<PermissionOptionView>,
}

#[derive(Debug, Clone, Serialize)]
struct UserRowView {
    id: i64,
    username: String,
    is_company_admin: bool,
    role: String,
    role_value: String,
    role_label: String,
    is_active: bool,
    scope_label: String,
    all_stores: bool,
    selected_store_ids: Vec<i64>,
    selected_store_ids_csv: String,
    selected_permissions_csv: String,
    permissions: Vec<PermissionOptionView>,
    created_at: sqlx::types::chrono::NaiveDateTime,
}

#[derive(Debug, Clone, Serialize)]
struct UsersView {
    tenant_name: String,
    tenant_id: i64,
    active_nav: String,
    active_platform: Option<String>,
    platform_menu: Vec<PlatformMenuItem>,
    users: Vec<UserRowView>,
    stores: Vec<user_repo::StoreScopeOption>,
    role_options: Vec<RoleOptionView>,
    permission_options: Vec<PermissionOptionView>,
    staff_count: usize,
}

pub fn routes() -> Router<AppState> {
    Router::new()
        .route("/settings/users", get(users_page).post(create_user))
        .route("/settings/users/:id", post(update_user))
        .route("/settings/users/:id/password", post(reset_user_password))
        .route("/settings/users/:id/disable", post(disable_user))
        .route("/settings/users/:id/enable", post(enable_user))
}

async fn users_page(
    State(state): State<AppState>,
    Extension(ctx): Extension<TenantContext>,
    Extension(principal): Extension<Principal>,
) -> Result<Response, AppError> {
    ensure_user_admin(&principal)?;

    let users = user_repo::list_users(&ctx.pool).await?;
    let stores = user_repo::list_store_scope_options(&ctx.pool).await?;
    let platform_menu = sidebar_menu(&state, &ctx).await;
    let staff_count = users.iter().filter(|user| user.is_active).count();
    let user_rows = users
        .into_iter()
        .map(|user| user_row_view(user, &stores))
        .collect::<Vec<_>>();
    let default_role = Role::ServiceStaff;

    let view = UsersView {
        tenant_name: ctx.company_name,
        tenant_id: ctx.tenant_id,
        active_nav: "users".to_string(),
        active_platform: None,
        platform_menu,
        users: user_rows,
        stores,
        role_options: role_options(),
        permission_options: permission_options_for(&default_permissions(default_role)),
        staff_count,
    };

    Ok(render_users(state.tera(), &view))
}

async fn create_user(
    State(state): State<AppState>,
    Extension(ctx): Extension<TenantContext>,
    Extension(principal): Extension<Principal>,
    Form(form): Form<CreateUserForm>,
) -> Result<Redirect, AppError> {
    ensure_user_admin(&principal)?;

    let role = parse_role_or_validation(&form.role)?;
    let new = user_repo::NewTenantUser {
        username: form.username.trim().to_string(),
        password: form.password,
        role,
        permissions: permissions_from_form(&form.permissions, role),
        store_scope: user_repo::parse_store_scope(form.all_stores.is_some(), &form.store_ids)?,
    };

    user_repo::create_user(&ctx.pool, &new).await?;
    refresh_staff_count(&state, &ctx).await?;

    Ok(Redirect::to("/settings/users"))
}

async fn update_user(
    Extension(ctx): Extension<TenantContext>,
    Extension(principal): Extension<Principal>,
    Path(id): Path<i64>,
    Form(form): Form<UpdateUserForm>,
) -> Result<Redirect, AppError> {
    ensure_user_admin(&principal)?;

    let role = parse_role_or_validation(&form.role)?;
    let update = user_repo::TenantUserUpdate {
        role,
        permissions: permissions_from_form(&form.permissions, role),
        store_scope: user_repo::parse_store_scope(form.all_stores.is_some(), &form.store_ids)?,
    };
    if !user_repo::update_user(&ctx.pool, id, &update).await? {
        return Err(AppError::NotFound);
    }

    Ok(Redirect::to("/settings/users"))
}

async fn reset_user_password(
    State(state): State<AppState>,
    Extension(ctx): Extension<TenantContext>,
    Extension(principal): Extension<Principal>,
    Path(id): Path<i64>,
    Form(form): Form<ResetPasswordForm>,
) -> Result<Redirect, AppError> {
    ensure_user_admin(&principal)?;

    if !user_repo::update_user_password(&ctx.pool, id, &form.password).await? {
        return Err(AppError::NotFound);
    }
    session_repo::revoke_all_for_tenant_principal(
        state.master_pool(),
        ctx.tenant_id,
        PrincipalKind::Employee,
        id,
    )
    .await?;
    session_repo::revoke_all_for_tenant_principal(
        state.master_pool(),
        ctx.tenant_id,
        PrincipalKind::CompanyAdmin,
        id,
    )
    .await?;

    Ok(Redirect::to("/settings/users"))
}

async fn disable_user(
    State(state): State<AppState>,
    Extension(ctx): Extension<TenantContext>,
    Extension(principal): Extension<Principal>,
    Path(id): Path<i64>,
) -> Result<Redirect, AppError> {
    ensure_user_admin(&principal)?;

    if !user_repo::set_user_active(&ctx.pool, id, false).await? {
        return Err(AppError::NotFound);
    }
    session_repo::revoke_all_for_tenant_principal(
        state.master_pool(),
        ctx.tenant_id,
        PrincipalKind::Employee,
        id,
    )
    .await?;
    session_repo::revoke_all_for_tenant_principal(
        state.master_pool(),
        ctx.tenant_id,
        PrincipalKind::CompanyAdmin,
        id,
    )
    .await?;
    refresh_staff_count(&state, &ctx).await?;

    Ok(Redirect::to("/settings/users"))
}

async fn enable_user(
    State(state): State<AppState>,
    Extension(ctx): Extension<TenantContext>,
    Extension(principal): Extension<Principal>,
    Path(id): Path<i64>,
) -> Result<Redirect, AppError> {
    ensure_user_admin(&principal)?;

    if !user_repo::set_user_active(&ctx.pool, id, true).await? {
        return Err(AppError::NotFound);
    }
    refresh_staff_count(&state, &ctx).await?;

    Ok(Redirect::to("/settings/users"))
}

fn ensure_user_admin(principal: &Principal) -> Result<(), AppError> {
    match principal {
        Principal::SuperAdmin | Principal::CompanyAdmin { .. } => Ok(()),
        Principal::Employee { .. } if principal.can_access(FeatureModule::SystemSettings) => Ok(()),
        _ => Err(AppError::Forbidden),
    }
}

async fn sidebar_menu(state: &AppState, ctx: &TenantContext) -> Vec<PlatformMenuItem> {
    platform_auth_service::load_sidebar_menu(state.master_pool(), ctx.tenant_id)
        .await
        .unwrap_or_else(|e| {
            tracing::warn!(error = %e, tenant_id = ctx.tenant_id, "平台菜单加载失败，员工管理侧栏将显示基础入口");
            Vec::new()
        })
}

async fn refresh_staff_count(state: &AppState, ctx: &TenantContext) -> Result<(), AppError> {
    let count = user_repo::count_active_users(&ctx.pool).await?;
    tenant_repo::update_staff_count(state.master_pool(), ctx.tenant_id, count).await?;
    Ok(())
}

fn parse_role_or_validation(value: &str) -> Result<Role, AppError> {
    user_repo::parse_role_value(value)
        .ok_or_else(|| AppError::Validation("请选择客服、采购或品检角色".to_string()))
}

fn permissions_from_form(csv: &str, role: Role) -> std::collections::HashMap<FeatureModule, bool> {
    if csv.trim().is_empty() {
        default_permissions(role)
    } else {
        user_repo::parse_enabled_permissions(csv)
    }
}

fn role_options() -> Vec<RoleOptionView> {
    [
        (
            Role::ServiceStaff,
            "客服账号",
            "处理订单确认、客户沟通和客服邮件。",
        ),
        (
            Role::Buyer,
            "采购账号",
            "处理国内采购、物流查询和采购统计。",
        ),
        (
            Role::ItemChecker,
            "品检账号",
            "默认权限最低，用于辅助检查。",
        ),
    ]
    .into_iter()
    .map(|(role, label, hint)| {
        let defaults = default_permissions(role);
        RoleOptionView {
            value: user_repo::role_form_value(role).to_string(),
            label: label.to_string(),
            hint: hint.to_string(),
            permissions: permission_options_for(&defaults),
        }
    })
    .collect()
}

fn permission_options_for(
    permissions: &std::collections::HashMap<FeatureModule, bool>,
) -> Vec<PermissionOptionView> {
    FeatureModule::ALL
        .into_iter()
        .map(|module| PermissionOptionView {
            key: user_repo::feature_module_key(module).to_string(),
            label: user_repo::feature_module_label(module).to_string(),
            checked: permissions.get(&module).copied().unwrap_or(false),
            default_checked: default_permissions(Role::ServiceStaff)
                .get(&module)
                .copied()
                .unwrap_or(false),
        })
        .collect()
}

fn user_row_view(
    user: user_repo::TenantUserSummary,
    stores: &[user_repo::StoreScopeOption],
) -> UserRowView {
    let role = user_repo::parse_role_value(&user.role).unwrap_or(Role::ItemChecker);
    let permissions = user
        .permissions
        .as_ref()
        .and_then(|json| permissions_from_json(&json.0))
        .unwrap_or_else(|| default_permissions(role));
    let selected_ids = parse_scope_ids(&user.dpqz);
    let all_stores = selected_ids.is_empty();
    let scope_label = if all_stores {
        "全部店铺".to_string()
    } else {
        selected_store_names(stores, &selected_ids).join("、")
    };

    UserRowView {
        id: user.id,
        username: user.username,
        is_company_admin: user.is_company_admin,
        role: user.role,
        role_value: user_repo::role_form_value(role).to_string(),
        role_label: user_repo::role_db_value(role).to_string(),
        is_active: user.is_active,
        scope_label,
        all_stores,
        selected_store_ids_csv: selected_ids
            .iter()
            .map(ToString::to_string)
            .collect::<Vec<_>>()
            .join(","),
        selected_store_ids: selected_ids,
        selected_permissions_csv: user_repo::selected_modules_csv(&permissions),
        permissions: permission_options_for(&permissions),
        created_at: user.created_at,
    }
}

fn permissions_from_json(
    value: &serde_json::Value,
) -> Option<std::collections::HashMap<FeatureModule, bool>> {
    let obj = value.as_object()?;
    let csv = obj
        .iter()
        .filter_map(|(key, value)| value.as_bool().filter(|b| *b).map(|_| key.as_str()))
        .collect::<Vec<_>>()
        .join(",");
    Some(user_repo::parse_enabled_permissions(&csv))
}

fn parse_scope_ids(dpqz: &str) -> Vec<i64> {
    dpqz.split(',')
        .map(str::trim)
        .filter_map(|s| s.parse::<i64>().ok())
        .filter(|id| *id > 0)
        .collect()
}

fn selected_store_names(stores: &[user_repo::StoreScopeOption], ids: &[i64]) -> Vec<String> {
    ids.iter()
        .filter_map(|id| {
            stores.iter().find(|store| store.id == *id).map(|store| {
                if store.dpquancheng.trim().is_empty() {
                    store.dpqz.clone()
                } else {
                    store.dpquancheng.clone()
                }
            })
        })
        .collect()
}

fn render_users(tera: &tera::Tera, view: &UsersView) -> Response {
    match Context::from_serialize(view) {
        Ok(ctx) => match tera.render(USERS_TEMPLATE, &ctx) {
            Ok(html) => Html(html).into_response(),
            Err(e) => {
                tracing::warn!(error = %e, template = USERS_TEMPLATE, "员工管理模板渲染失败，回退 JSON");
                Json(view).into_response()
            }
        },
        Err(e) => {
            tracing::warn!(error = %e, "员工管理上下文序列化失败，回退 JSON");
            Json(view).into_response()
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::models::user::{StoreScope, TenantId};

    #[test]
    fn ensure_user_admin_allows_company_admin_and_system_settings_employee() {
        assert!(ensure_user_admin(&Principal::CompanyAdmin {
            tenant_id: TenantId(1)
        })
        .is_ok());

        let mut overrides = std::collections::HashMap::new();
        overrides.insert(FeatureModule::SystemSettings, true);
        let employee = Principal::Employee {
            tenant_id: TenantId(1),
            user_id: 1,
            role: Role::ItemChecker,
            overrides,
            store_scope: StoreScope::All,
        };
        assert!(ensure_user_admin(&employee).is_ok());
    }

    #[test]
    fn ensure_user_admin_denies_plain_employee() {
        let employee = Principal::Employee {
            tenant_id: TenantId(1),
            user_id: 1,
            role: Role::ServiceStaff,
            overrides: std::collections::HashMap::new(),
            store_scope: StoreScope::All,
        };
        assert!(matches!(
            ensure_user_admin(&employee),
            Err(AppError::Forbidden)
        ));
    }

    #[test]
    fn parse_scope_ids_ignores_legacy_non_numeric_tokens() {
        assert_eq!(parse_scope_ids("1, abc, 2"), vec![1, 2]);
    }
}
