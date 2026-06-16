//! `User` 员工 / `Admin` 超管，以及权限类型（`FeatureModule`/`DataAction`/`StoreScope`/`Role`/`Principal`）。
//!
//! 权限模型（第五章 5.1–5.4）：忠实复刻 old `inc/permission_functions.php` 中的
//! `SYSTEM_PERMISSIONS`（14 个功能开关）与 `get_default_permissions`（按角色默认权限）。
//!
//! 本模块（Task 6.1）仅实现权限**类型**与 `default_permissions`。
//! `Principal` 的行为方法 `can_access` / `visible_stores` / `can_operate`
//! 分别由 Task 6.2 / 6.4 / 6.6 在本文件追加 `impl` 实现。

use std::collections::{HashMap, HashSet};

use serde::{Deserialize, Serialize};

use crate::error::AppError;
use crate::models::store::Store;

/// 租户标识。
///
/// 占位定义（newtype over `i64`）：租户模块（`models::tenant`）尚未落地，
/// 这里提供最小可编译类型，供 `Principal` 引用。后续可统一收敛到租户模块。
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, Serialize, Deserialize)]
pub struct TenantId(pub i64);

/// 14 个功能模块（忠实复刻 `SYSTEM_PERMISSIONS`）。
///
/// 各枚举值通过 `serde(rename = ...)` 对应 old `permissions` JSON 列里的键名，
/// 使 `HashMap<FeatureModule, bool>` 能直接从 `ph_user.permissions` 反序列化。
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, Serialize, Deserialize)]
pub enum FeatureModule {
    /// 系统设置 `system_settings`
    #[serde(rename = "system_settings")]
    SystemSettings,
    /// 订单增删日志 `order_log`
    #[serde(rename = "order_log")]
    OrderLog,
    /// 1688 物流查询日志 `1688_log`
    #[serde(rename = "1688_log")]
    Log1688,
    /// 国际物流查询日志 `jpshipinfo_log`
    #[serde(rename = "jpshipinfo_log")]
    JpshipinfoLog,
    /// ShowAPI 物流查询日志 `showapi_log`
    #[serde(rename = "showapi_log")]
    ShowapiLog,
    /// 性能分析 `performance_analysis`
    #[serde(rename = "performance_analysis")]
    PerformanceAnalysis,
    /// 西阵业绩统计 `performance_view`
    #[serde(rename = "performance_view")]
    PerformanceView,
    /// 出单商品统计 `product_statistics`
    #[serde(rename = "product_statistics")]
    ProductStatistics,
    /// 采购员业绩统计 `caigou_stats`
    #[serde(rename = "caigou_stats")]
    CaigouStats,
    /// 利润核算分析 `profit_analysis`
    #[serde(rename = "profit_analysis")]
    ProfitAnalysis,
    /// 采购状态统计 `caigou_status_stats`
    #[serde(rename = "caigou_status_stats")]
    CaigouStatusStats,
    /// 国际运费异常检测 `shipping_anomaly`
    #[serde(rename = "shipping_anomaly")]
    ShippingAnomaly,
    /// Wowma 批量同步订单 `wowma_batch_sync`
    #[serde(rename = "wowma_batch_sync")]
    WowmaBatchSync,
    /// 客服邮件中心 `kefu_mail`
    #[serde(rename = "kefu_mail")]
    KefuMail,
}

impl FeatureModule {
    /// 全部 14 个功能模块（声明顺序与 `SYSTEM_PERMISSIONS` 一致）。
    pub const ALL: [FeatureModule; 14] = [
        FeatureModule::SystemSettings,
        FeatureModule::OrderLog,
        FeatureModule::Log1688,
        FeatureModule::JpshipinfoLog,
        FeatureModule::ShowapiLog,
        FeatureModule::PerformanceAnalysis,
        FeatureModule::PerformanceView,
        FeatureModule::ProductStatistics,
        FeatureModule::CaigouStats,
        FeatureModule::ProfitAnalysis,
        FeatureModule::CaigouStatusStats,
        FeatureModule::ShippingAnomaly,
        FeatureModule::WowmaBatchSync,
        FeatureModule::KefuMail,
    ];
}

/// 数据操作维度（对应 old「查看 / 编辑 / 删除」）。
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, Serialize, Deserialize)]
pub enum DataAction {
    /// 查看
    View,
    /// 编辑
    Edit,
    /// 删除
    Delete,
}

/// 店铺范围。
#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub enum StoreScope {
    /// 全部店铺（仍受隐藏店铺过滤）。
    All,
    /// 限定的 `store_id` 集合。
    Restricted(Vec<i64>),
}

/// 角色（对应 old `ph_user.usertype`）。
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, Serialize, Deserialize)]
pub enum Role {
    /// 采购
    Buyer,
    /// 客服
    ServiceStaff,
    /// 品检
    ItemChecker,
}

/// 请求主体（鉴权后注入下游做权限判定）。
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Principal {
    /// 主库超管（权限全开、可跨租户运营）。
    SuperAdmin,
    /// 公司管理员（本租户内全部允许）。
    CompanyAdmin {
        /// 所属租户。
        tenant_id: TenantId,
    },
    /// 普通员工。
    Employee {
        /// 所属租户。
        tenant_id: TenantId,
        /// 租户库 `users.id`。
        user_id: i64,
        /// 角色。
        role: Role,
        /// `permissions` JSON 反序列化得到的显式开关；缺省项回退角色默认。
        overrides: HashMap<FeatureModule, bool>,
        /// 店铺范围。
        store_scope: StoreScope,
    },
}

/// 角色默认权限（忠实复刻 `get_default_permissions`）。
///
/// - `采购`（`Buyer`）：默认开大部分统计/日志类；`order_log`、`performance_analysis`、
///   `wowma_batch_sync` 默认关闭（`wowma_batch_sync` 需管理员手动授权）。
/// - `客服`（`ServiceStaff`）：默认只开 `kefu_mail`，其余全关。
/// - `品检`（`ItemChecker`）：几乎全关（含 `kefu_mail`）。
///
/// 注：old 的 `客服/品检` 分支未显式给出 `shipping_anomaly` / `wowma_batch_sync` 键，
/// PHP `isset` 检查时等价于 `false`，因此这里显式补齐为 `false`，保持语义一致。
pub fn default_permissions(role: Role) -> HashMap<FeatureModule, bool> {
    use FeatureModule::*;

    let entries: [(FeatureModule, bool); 14] = match role {
        Role::Buyer => [
            (SystemSettings, true),
            (OrderLog, false),
            (Log1688, true),
            (JpshipinfoLog, true),
            (ShowapiLog, true),
            (PerformanceAnalysis, false),
            (PerformanceView, true),
            (ProductStatistics, true),
            (CaigouStats, true),
            (ProfitAnalysis, true),
            (CaigouStatusStats, true),
            (ShippingAnomaly, true),
            (WowmaBatchSync, false),
            (KefuMail, true),
        ],
        Role::ServiceStaff => [
            (SystemSettings, false),
            (OrderLog, false),
            (Log1688, false),
            (JpshipinfoLog, false),
            (ShowapiLog, false),
            (PerformanceAnalysis, false),
            (PerformanceView, false),
            (ProductStatistics, false),
            (CaigouStats, false),
            (ProfitAnalysis, false),
            (CaigouStatusStats, false),
            (ShippingAnomaly, false),
            (WowmaBatchSync, false),
            // 客服默认可访问邮件中心
            (KefuMail, true),
        ],
        Role::ItemChecker => [
            (SystemSettings, false),
            (OrderLog, false),
            (Log1688, false),
            (JpshipinfoLog, false),
            (ShowapiLog, false),
            (PerformanceAnalysis, false),
            (PerformanceView, false),
            (ProductStatistics, false),
            (CaigouStats, false),
            (ProfitAnalysis, false),
            (CaigouStatusStats, false),
            (ShippingAnomaly, false),
            (WowmaBatchSync, false),
            // 品检默认不可访问邮件中心
            (KefuMail, false),
        ],
    };

    entries.into_iter().collect()
}

// ============================================================================
// Task 6.2: Principal::can_access（功能权限解析，见 design.md 5.4）
// 本 impl 块仅实现 can_access。visible_stores / can_operate 由 Task 6.4 / 6.6
// 在各自独立的 `impl Principal` 块中追加，互不干扰。
// ============================================================================
impl Principal {
    /// 是否拥有某功能模块权限。
    ///
    /// 后置条件（design.md 5.4）：
    /// - `SuperAdmin` 恒为 `true`（短路）。
    /// - `CompanyAdmin` 恒为 `true`（本租户全开）。
    /// - `Employee`：`overrides` 显式取值优先；未显式设置则回退角色默认
    ///   `default_permissions(role)`；二者皆缺省时为 `false`。
    pub fn can_access(&self, m: FeatureModule) -> bool {
        match self {
            Principal::SuperAdmin => true,
            Principal::CompanyAdmin { .. } => true,
            Principal::Employee {
                overrides, role, ..
            } => match overrides.get(&m) {
                Some(&allowed) => allowed,
                None => *default_permissions(*role).get(&m).unwrap_or(&false),
            },
        }
    }
}

// ============================================================================
// Task 6.4: Principal::visible_stores（店铺范围 + 隐藏店铺过滤，见 design.md 5.5）
// 独立 impl 块，复刻 old getPlatformShopList + 隐藏店铺逻辑。
// 不修改 Task 6.2 的 can_access 块。
// ============================================================================
impl Principal {
    /// 计算主体可见的店铺集合。
    ///
    /// 后置条件（design.md 5.5 / Property 10）：结果集恒等于
    /// 「主体店铺范围允许的店铺」与「非隐藏店铺」的交集。
    ///
    /// - 范围过滤：
    ///   - `SuperAdmin` / `CompanyAdmin`：放行全部店铺。
    ///   - `Employee` 且 `StoreScope::All`：放行全部店铺。
    ///   - `Employee` 且 `StoreScope::Restricted(ids)`：仅放行 `id ∈ ids` 的店铺。
    /// - 隐藏店铺过滤：`dpqz ∈ hidden` 的店铺无论范围如何都被剔除（全局生效）。
    ///
    /// 入参 `hidden` 为隐藏店铺缩写（`dpqz`）集合。
    pub fn visible_stores(&self, all: &[Store], hidden: &HashSet<String>) -> Vec<Store> {
        let scope_filter = |s: &Store| match self {
            Principal::SuperAdmin | Principal::CompanyAdmin { .. } => true,
            Principal::Employee { store_scope, .. } => match store_scope {
                StoreScope::All => true,
                StoreScope::Restricted(ids) => ids.contains(&s.id),
            },
        };
        all.iter()
            .filter(|s| scope_filter(s))
            .filter(|s| !hidden.contains(&s.dpqz)) // 隐藏店铺全局过滤
            .cloned()
            .collect()
    }
}

// ============================================================================
// Task 6.6: can_operate + 租户隔离守卫（见 design.md 5.3 / 5.6，Requirements 4.1 / 4.4）
//
// 独立 impl 块，不修改 Task 6.2 (can_access) / Task 6.4 (visible_stores)。
//
// 三件事：
//   1. `can_operate(store_id, action)`：店铺级数据操作判定（design 5.3 接口契约）。
//   2. `allowed_store_ids()`：返回可注入 `WHERE store_id IN (...)` 的店铺白名单
//      （`None` 表示不受限，service/repository 不追加店铺过滤）。
//   3. `ensure_same_tenant(row_tenant_id)`：租户隔离守卫，跨租户返回
//      `AppError::Forbidden`（403），超管绕过（design 5.6 / Property 3 / Req 4.1）。
// ============================================================================
impl Principal {
    /// 是否可对某店铺执行某数据操作（View / Edit / Delete）。
    ///
    /// 后置条件（design.md 5.2 权限解析优先级 / 5.3 接口契约 / Requirement 4.4–4.5）：
    /// - `SuperAdmin`：短路放行一切操作（Req 4.5）。
    /// - `CompanyAdmin`：本租户内全部允许，店铺范围 = All。
    /// - `Employee`：当且仅当 `store_id` 落在其 [`StoreScope`] 内才放行：
    ///   - `StoreScope::All` → 放行；
    ///   - `StoreScope::Restricted(ids)` → 仅当 `ids` 含 `store_id` 时放行。
    ///
    /// 关于 `action` 维度：当前 [`Principal::Employee`] 不携带「每店铺 × 每操作」
    /// 的细粒度数据操作开关（old 数据模型亦以店铺范围为数据访问边界），因此
    /// View/Edit/Delete 三种操作目前统一以**店铺范围成员资格**为准放行。
    /// 一旦后续在 `Employee` 上引入按 [`DataAction`] 的细粒度开关（例如
    /// `data_actions: HashSet<DataAction>` 或按店铺映射），即可在此处的
    /// `Employee` 分支叠加 `&& 拥有该 action 权限` 的判定，其余分支语义不变。
    pub fn can_operate(&self, store_id: i64, action: DataAction) -> bool {
        // action 当前不改变判定结果（见上文文档）；显式忽略以表明这是有意为之，
        // 同时保留签名稳定，便于后续接入细粒度数据操作权限。
        let _ = action;
        match self {
            Principal::SuperAdmin => true,
            Principal::CompanyAdmin { .. } => true,
            Principal::Employee { store_scope, .. } => match store_scope {
                StoreScope::All => true,
                StoreScope::Restricted(ids) => ids.contains(&store_id),
            },
        }
    }

    /// 返回供 service/repository 注入 `WHERE store_id IN (...)` 的店铺白名单。
    ///
    /// - 返回 `None`：主体不受店铺范围限制（`SuperAdmin` / `CompanyAdmin` /
    ///   `Employee` 且 `StoreScope::All`），调用方**不应**追加店铺过滤条件。
    /// - 返回 `Some(ids)`：主体受限（`Employee` 且 `StoreScope::Restricted`），
    ///   调用方**必须**注入 `WHERE store_id IN (ids)`；空集合意味着无任何可访问店铺。
    ///
    /// 注意：本白名单仅表达「店铺范围」，不含隐藏店铺过滤；隐藏店铺过滤由
    /// [`Principal::visible_stores`] 在展示层处理（design 5.5）。
    pub fn allowed_store_ids(&self) -> Option<Vec<i64>> {
        match self {
            Principal::SuperAdmin | Principal::CompanyAdmin { .. } => None,
            Principal::Employee { store_scope, .. } => match store_scope {
                StoreScope::All => None,
                StoreScope::Restricted(ids) => Some(ids.clone()),
            },
        }
    }

    /// 租户隔离守卫：校验命中行的 `tenant_id` 是否与主体会话租户一致。
    ///
    /// 后置条件（design.md 5.6 / Property 3 / Requirement 4.1）：
    /// `∀ row. accessed(row) ⟹ row.tenant_id == principal.tenant_id ∨ principal == SuperAdmin`。
    ///
    /// - `SuperAdmin`：跨租户运营，恒放行（`Ok(())`）。
    /// - `CompanyAdmin` / `Employee`：当且仅当 `row_tenant_id` 等于会话租户时放行；
    ///   否则返回 [`AppError::Forbidden`]（HTTP 403），杜绝跨租户数据访问。
    pub fn ensure_same_tenant(&self, row_tenant_id: TenantId) -> Result<(), AppError> {
        match self {
            Principal::SuperAdmin => Ok(()),
            Principal::CompanyAdmin { tenant_id } | Principal::Employee { tenant_id, .. } => {
                if *tenant_id == row_tenant_id {
                    Ok(())
                } else {
                    Err(AppError::Forbidden)
                }
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn default_permissions_cover_all_14_modules() {
        for role in [Role::Buyer, Role::ServiceStaff, Role::ItemChecker] {
            let perms = default_permissions(role);
            assert_eq!(perms.len(), 14, "role {role:?} 应覆盖全部 14 个功能模块");
            for m in FeatureModule::ALL {
                assert!(perms.contains_key(&m), "role {role:?} 缺少模块 {m:?}");
            }
        }
    }

    #[test]
    fn buyer_defaults_match_php() {
        let p = default_permissions(Role::Buyer);
        assert_eq!(p[&FeatureModule::SystemSettings], true);
        assert_eq!(p[&FeatureModule::OrderLog], false);
        assert_eq!(p[&FeatureModule::Log1688], true);
        assert_eq!(p[&FeatureModule::JpshipinfoLog], true);
        assert_eq!(p[&FeatureModule::ShowapiLog], true);
        assert_eq!(p[&FeatureModule::PerformanceAnalysis], false);
        assert_eq!(p[&FeatureModule::PerformanceView], true);
        assert_eq!(p[&FeatureModule::ProductStatistics], true);
        assert_eq!(p[&FeatureModule::CaigouStats], true);
        assert_eq!(p[&FeatureModule::ProfitAnalysis], true);
        assert_eq!(p[&FeatureModule::CaigouStatusStats], true);
        assert_eq!(p[&FeatureModule::ShippingAnomaly], true);
        assert_eq!(p[&FeatureModule::WowmaBatchSync], false);
        assert_eq!(p[&FeatureModule::KefuMail], true);
    }

    #[test]
    fn service_staff_only_kefu_mail_open() {
        let p = default_permissions(Role::ServiceStaff);
        assert_eq!(p[&FeatureModule::KefuMail], true);
        for m in FeatureModule::ALL {
            if m != FeatureModule::KefuMail {
                assert_eq!(p[&m], false, "客服默认应关闭 {m:?}");
            }
        }
    }

    #[test]
    fn item_checker_all_off() {
        let p = default_permissions(Role::ItemChecker);
        for m in FeatureModule::ALL {
            assert_eq!(p[&m], false, "品检默认应关闭 {m:?}");
        }
    }

    #[test]
    fn feature_module_serde_keys_match_php_json() {
        // 键名需与 old permissions JSON 保持一致，确保反序列化可用。
        assert_eq!(
            serde_json::to_string(&FeatureModule::Log1688).unwrap(),
            "\"1688_log\""
        );
        assert_eq!(
            serde_json::to_string(&FeatureModule::SystemSettings).unwrap(),
            "\"system_settings\""
        );
        let m: FeatureModule = serde_json::from_str("\"kefu_mail\"").unwrap();
        assert_eq!(m, FeatureModule::KefuMail);
    }
}

#[cfg(test)]
mod can_access_tests {
    use super::*;

    fn employee(role: Role, overrides: HashMap<FeatureModule, bool>) -> Principal {
        Principal::Employee {
            tenant_id: TenantId(1),
            user_id: 42,
            role,
            overrides,
            store_scope: StoreScope::All,
        }
    }

    #[test]
    fn super_admin_can_access_all_modules() {
        let p = Principal::SuperAdmin;
        for m in FeatureModule::ALL {
            assert!(p.can_access(m), "SuperAdmin 应放行 {m:?}");
        }
    }

    #[test]
    fn company_admin_can_access_all_modules() {
        let p = Principal::CompanyAdmin {
            tenant_id: TenantId(7),
        };
        for m in FeatureModule::ALL {
            assert!(p.can_access(m), "CompanyAdmin 应放行 {m:?}");
        }
    }

    #[test]
    fn employee_falls_back_to_role_defaults_when_no_overrides() {
        // 无任何显式覆盖时，逐模块等于角色默认值。
        for role in [Role::Buyer, Role::ServiceStaff, Role::ItemChecker] {
            let defaults = default_permissions(role);
            let p = employee(role, HashMap::new());
            for m in FeatureModule::ALL {
                assert_eq!(
                    p.can_access(m),
                    defaults[&m],
                    "role {role:?} 模块 {m:?} 应回退角色默认"
                );
            }
        }
    }

    #[test]
    fn employee_override_true_takes_precedence_over_default_false() {
        // 采购默认关闭 order_log；显式置 true 后应放行。
        assert_eq!(
            default_permissions(Role::Buyer)[&FeatureModule::OrderLog],
            false
        );
        let mut overrides = HashMap::new();
        overrides.insert(FeatureModule::OrderLog, true);
        let p = employee(Role::Buyer, overrides);
        assert!(
            p.can_access(FeatureModule::OrderLog),
            "显式 true 应覆盖默认 false"
        );
    }

    #[test]
    fn employee_override_false_takes_precedence_over_default_true() {
        // 采购默认开启 kefu_mail；显式置 false 后应拒绝。
        assert_eq!(
            default_permissions(Role::Buyer)[&FeatureModule::KefuMail],
            true
        );
        let mut overrides = HashMap::new();
        overrides.insert(FeatureModule::KefuMail, false);
        let p = employee(Role::Buyer, overrides);
        assert!(
            !p.can_access(FeatureModule::KefuMail),
            "显式 false 应覆盖默认 true"
        );
    }

    #[test]
    fn employee_unset_module_uses_default_not_override() {
        // 仅覆盖一个模块，其余模块仍按角色默认解析。
        let mut overrides = HashMap::new();
        overrides.insert(FeatureModule::OrderLog, true);
        let p = employee(Role::ServiceStaff, overrides);
        // 被覆盖模块：true
        assert!(p.can_access(FeatureModule::OrderLog));
        // 未覆盖模块：客服默认仅 kefu_mail 开放
        assert!(p.can_access(FeatureModule::KefuMail));
        assert!(!p.can_access(FeatureModule::SystemSettings));
        assert!(!p.can_access(FeatureModule::PerformanceView));
    }
}

#[cfg(test)]
mod visible_stores_tests {
    use super::*;

    fn store(id: i64, dpqz: &str) -> Store {
        Store {
            id,
            platform: "y".to_string(),
            dpqz: dpqz.to_string(),
            dpquancheng: format!("店铺{id}"),
            is_hidden: false,
        }
    }

    /// 三家店铺：缩写 a / b / c。
    fn fixture() -> Vec<Store> {
        vec![store(1, "a"), store(2, "b"), store(3, "c")]
    }

    fn hidden(items: &[&str]) -> HashSet<String> {
        items.iter().map(|s| s.to_string()).collect()
    }

    fn employee(store_scope: StoreScope) -> Principal {
        Principal::Employee {
            tenant_id: TenantId(1),
            user_id: 42,
            role: Role::Buyer,
            overrides: HashMap::new(),
            store_scope,
        }
    }

    #[test]
    fn super_admin_sees_all_non_hidden() {
        let all = fixture();
        let h = hidden(&["b"]);
        let visible = Principal::SuperAdmin.visible_stores(&all, &h);
        let ids: Vec<i64> = visible.iter().map(|s| s.id).collect();
        assert_eq!(ids, vec![1, 3], "SuperAdmin 应看到全部非隐藏店铺");
    }

    #[test]
    fn company_admin_sees_all_non_hidden() {
        let all = fixture();
        let h = hidden(&["c"]);
        let p = Principal::CompanyAdmin {
            tenant_id: TenantId(7),
        };
        let ids: Vec<i64> = p.visible_stores(&all, &h).iter().map(|s| s.id).collect();
        assert_eq!(ids, vec![1, 2], "CompanyAdmin 应看到全部非隐藏店铺");
    }

    #[test]
    fn employee_all_scope_sees_all_non_hidden() {
        let all = fixture();
        let h = hidden(&["a"]);
        let p = employee(StoreScope::All);
        let ids: Vec<i64> = p.visible_stores(&all, &h).iter().map(|s| s.id).collect();
        assert_eq!(ids, vec![2, 3], "All 范围员工应看到全部非隐藏店铺");
    }

    #[test]
    fn employee_restricted_scope_sees_only_allowed_non_hidden() {
        let all = fixture();
        let h = HashSet::new();
        // 仅允许 1、3
        let p = employee(StoreScope::Restricted(vec![1, 3]));
        let ids: Vec<i64> = p.visible_stores(&all, &h).iter().map(|s| s.id).collect();
        assert_eq!(ids, vec![1, 3], "Restricted 范围只看允许集合内店铺");
    }

    #[test]
    fn restricted_scope_intersect_hidden() {
        let all = fixture();
        // 允许 1、2，但 2 被隐藏 → 只剩 1
        let h = hidden(&["b"]);
        let p = employee(StoreScope::Restricted(vec![1, 2]));
        let ids: Vec<i64> = p.visible_stores(&all, &h).iter().map(|s| s.id).collect();
        assert_eq!(ids, vec![1], "结果应为范围 ∩ 非隐藏");
    }

    #[test]
    fn hidden_stores_always_excluded_for_any_principal() {
        let all = fixture();
        // 全部隐藏
        let h = hidden(&["a", "b", "c"]);
        let principals = [
            Principal::SuperAdmin,
            Principal::CompanyAdmin {
                tenant_id: TenantId(1),
            },
            employee(StoreScope::All),
            employee(StoreScope::Restricted(vec![1, 2, 3])),
        ];
        for p in principals {
            assert!(
                p.visible_stores(&all, &h).is_empty(),
                "隐藏店铺绝不出现在任何主体的可见集合中：{p:?}"
            );
        }
    }
}

#[cfg(test)]
mod can_operate_tests {
    use super::*;

    fn employee(store_scope: StoreScope) -> Principal {
        Principal::Employee {
            tenant_id: TenantId(1),
            user_id: 42,
            role: Role::Buyer,
            overrides: HashMap::new(),
            store_scope,
        }
    }

    const ACTIONS: [DataAction; 3] = [DataAction::View, DataAction::Edit, DataAction::Delete];

    #[test]
    fn super_admin_can_operate_any_store_any_action() {
        let p = Principal::SuperAdmin;
        for store_id in [1, 7, 999, i64::MAX] {
            for a in ACTIONS {
                assert!(
                    p.can_operate(store_id, a),
                    "SuperAdmin 应放行 store={store_id} action={a:?}"
                );
            }
        }
    }

    #[test]
    fn company_admin_can_operate_any_store_any_action() {
        let p = Principal::CompanyAdmin {
            tenant_id: TenantId(7),
        };
        for store_id in [1, 7, 999] {
            for a in ACTIONS {
                assert!(
                    p.can_operate(store_id, a),
                    "CompanyAdmin 应放行 store={store_id} action={a:?}"
                );
            }
        }
    }

    #[test]
    fn employee_all_scope_can_operate_any_store() {
        let p = employee(StoreScope::All);
        for store_id in [1, 2, 3, 1000] {
            for a in ACTIONS {
                assert!(
                    p.can_operate(store_id, a),
                    "All 范围员工应放行 store={store_id}"
                );
            }
        }
    }

    #[test]
    fn employee_restricted_scope_only_in_scope_stores() {
        let p = employee(StoreScope::Restricted(vec![1, 3]));
        for a in ACTIONS {
            assert!(p.can_operate(1, a), "范围内店铺 1 应放行");
            assert!(p.can_operate(3, a), "范围内店铺 3 应放行");
            assert!(!p.can_operate(2, a), "范围外店铺 2 应拒绝");
            assert!(!p.can_operate(99, a), "范围外店铺 99 应拒绝");
        }
    }

    #[test]
    fn employee_empty_restricted_scope_denies_all() {
        let p = employee(StoreScope::Restricted(vec![]));
        for a in ACTIONS {
            assert!(!p.can_operate(1, a), "空范围应拒绝一切店铺");
        }
    }
}

#[cfg(test)]
mod allowed_store_ids_tests {
    use super::*;

    fn employee(store_scope: StoreScope) -> Principal {
        Principal::Employee {
            tenant_id: TenantId(1),
            user_id: 42,
            role: Role::ServiceStaff,
            overrides: HashMap::new(),
            store_scope,
        }
    }

    #[test]
    fn super_admin_is_unrestricted() {
        assert_eq!(Principal::SuperAdmin.allowed_store_ids(), None);
    }

    #[test]
    fn company_admin_is_unrestricted() {
        let p = Principal::CompanyAdmin {
            tenant_id: TenantId(2),
        };
        assert_eq!(p.allowed_store_ids(), None);
    }

    #[test]
    fn employee_all_scope_is_unrestricted() {
        assert_eq!(employee(StoreScope::All).allowed_store_ids(), None);
    }

    #[test]
    fn employee_restricted_scope_returns_whitelist() {
        let p = employee(StoreScope::Restricted(vec![4, 5, 6]));
        assert_eq!(p.allowed_store_ids(), Some(vec![4, 5, 6]));
    }

    #[test]
    fn employee_empty_restricted_scope_returns_empty_whitelist() {
        // Some(空) 表示「受限且无任何可访问店铺」，区别于 None（不受限）。
        let p = employee(StoreScope::Restricted(vec![]));
        assert_eq!(p.allowed_store_ids(), Some(vec![]));
    }
}

#[cfg(test)]
mod ensure_same_tenant_tests {
    use super::*;

    fn employee(tenant: i64) -> Principal {
        Principal::Employee {
            tenant_id: TenantId(tenant),
            user_id: 42,
            role: Role::Buyer,
            overrides: HashMap::new(),
            store_scope: StoreScope::All,
        }
    }

    #[test]
    fn super_admin_bypasses_tenant_isolation() {
        let p = Principal::SuperAdmin;
        // 任意租户行均放行（跨租户运营）。
        assert!(p.ensure_same_tenant(TenantId(1)).is_ok());
        assert!(p.ensure_same_tenant(TenantId(999)).is_ok());
    }

    #[test]
    fn company_admin_same_tenant_ok() {
        let p = Principal::CompanyAdmin {
            tenant_id: TenantId(7),
        };
        assert!(p.ensure_same_tenant(TenantId(7)).is_ok());
    }

    #[test]
    fn company_admin_cross_tenant_forbidden() {
        let p = Principal::CompanyAdmin {
            tenant_id: TenantId(7),
        };
        let err = p.ensure_same_tenant(TenantId(8)).unwrap_err();
        assert!(
            matches!(err, AppError::Forbidden),
            "跨租户应返回 Forbidden(403)"
        );
    }

    #[test]
    fn employee_same_tenant_ok() {
        let p = employee(3);
        assert!(p.ensure_same_tenant(TenantId(3)).is_ok());
    }

    #[test]
    fn employee_cross_tenant_forbidden() {
        let p = employee(3);
        let err = p.ensure_same_tenant(TenantId(4)).unwrap_err();
        assert!(
            matches!(err, AppError::Forbidden),
            "跨租户应返回 Forbidden(403)"
        );
    }

    #[test]
    fn forbidden_maps_to_http_403() {
        use axum::http::StatusCode;
        let p = employee(3);
        let err = p.ensure_same_tenant(TenantId(99)).unwrap_err();
        assert_eq!(err.status_code(), StatusCode::FORBIDDEN);
    }
}

// ============================================================================
// Task 6.3: can_access 属性测试（Property 6：权限解析回退，见 design.md 5.4）
//
// **Property 6: 权限解析回退**
// **Validates: Requirements 4.2**
//
// 形式化：can_access(emp, m) == emp.overrides.get(m).unwrap_or(default_permissions(emp.role)[m])；
// 且 SuperAdmin / CompanyAdmin 对一切 m 恒为 true。
//
// 使用 proptest（dev-dependency）跨随机 (role, module, 可选 override) 输入验证该不变式。
// 仅新增测试模块，不改动任何 production impl 块。
// ============================================================================
#[cfg(test)]
mod prop_can_access_tests {
    use super::*;
    use proptest::prelude::*;

    /// 生成员工三种角色之一。
    fn role_strategy() -> impl Strategy<Value = Role> {
        prop_oneof![
            Just(Role::Buyer),
            Just(Role::ServiceStaff),
            Just(Role::ItemChecker),
        ]
    }

    /// 在 14 个功能模块中等概率选取一个。
    fn module_strategy() -> impl Strategy<Value = FeatureModule> {
        (0usize..FeatureModule::ALL.len()).prop_map(|i| FeatureModule::ALL[i])
    }

    proptest! {
        /// Property 6（Employee 分支）：对任意 (role, module, 可选 override)，
        /// can_access 等于「override 优先，否则角色默认」。
        ///
        /// **Validates: Requirements 4.2**
        #[test]
        fn employee_can_access_resolves_override_then_role_default(
            role in role_strategy(),
            m in module_strategy(),
            maybe_override in proptest::option::of(any::<bool>()),
        ) {
            let mut overrides = HashMap::new();
            if let Some(v) = maybe_override {
                overrides.insert(m, v);
            }
            let emp = Principal::Employee {
                tenant_id: TenantId(1),
                user_id: 42,
                role,
                overrides,
                store_scope: StoreScope::All,
            };

            // 期望值：override 显式优先，否则回退角色默认。
            let expected = match maybe_override {
                Some(v) => v,
                None => default_permissions(role)[&m],
            };
            prop_assert_eq!(emp.can_access(m), expected);
        }

        /// Property 6（管理员分支）：SuperAdmin / CompanyAdmin 对任意模块恒为 true。
        ///
        /// **Validates: Requirements 4.2**
        #[test]
        fn admins_can_access_every_module(m in module_strategy(), tenant in any::<i64>()) {
            let super_admin = Principal::SuperAdmin;
            let company_admin = Principal::CompanyAdmin { tenant_id: TenantId(tenant) };
            prop_assert!(super_admin.can_access(m));
            prop_assert!(company_admin.can_access(m));
        }
    }
}

// ============================================================================
// Task 6.5: visible_stores 属性测试（Property 10：店铺可见性 = 范围 ∩ 非隐藏，
// 见 design.md 5.5 / `## Correctness Properties` Property 10）
//
// **Property 10: 店铺可见性 = 范围 ∩ 非隐藏**
// **Validates: Requirements 4.3**
//
// 形式化：visible_stores(principal, all, hidden) 的结果集恒等于
//   {范围允许的店铺} ∩ {dpqz ∉ hidden 的非隐藏店铺}；
// 隐藏店铺（dpqz ∈ hidden）绝不出现在任何主体的可见集合中。
//
// 使用 proptest（dev-dependency）跨随机 (principal, stores, hidden) 输入验证该不变式。
// 仅新增测试模块，不改动任何 production impl 块。
// ============================================================================
#[cfg(test)]
mod prop_visible_stores_tests {
    use super::*;
    use proptest::prelude::*;

    /// 单个店铺生成器。
    ///
    /// - `id` 取小范围 `0..6`，使 `Restricted(ids)` 范围与店铺 id 有意义地重叠；
    /// - `dpqz` 取小字母表 `[a-d]`（单字符），制造店铺间 dpqz 冲突与隐藏命中；
    /// - `is_hidden` 任意：该字段不参与 `visible_stores`（过滤仅看 `hidden` 集合 ∩ `dpqz`），
    ///   随机取值用于佐证它不影响结果。
    fn store_strategy() -> impl Strategy<Value = Store> {
        (0i64..6, "[a-d]", any::<bool>()).prop_map(|(id, dpqz, is_hidden)| Store {
            id,
            platform: "y".to_string(),
            dpqz,
            dpquancheng: format!("店铺{id}"),
            is_hidden,
        })
    }

    /// 0..8 家店铺的列表。
    fn stores_strategy() -> impl Strategy<Value = Vec<Store>> {
        prop::collection::vec(store_strategy(), 0..8)
    }

    /// 隐藏 dpqz 集合：与店铺 dpqz 同字母表 `[a-d]`，保证可能相交。
    fn hidden_strategy() -> impl Strategy<Value = std::collections::HashSet<String>> {
        prop::collection::hash_set("[a-d]", 0..5)
    }

    /// 主体生成器：SuperAdmin / CompanyAdmin / Employee(All) / Employee(Restricted)。
    /// role 不影响 `visible_stores`，固定为 Buyer。
    fn principal_strategy() -> impl Strategy<Value = Principal> {
        prop_oneof![
            Just(Principal::SuperAdmin),
            any::<i64>().prop_map(|t| Principal::CompanyAdmin {
                tenant_id: TenantId(t),
            }),
            Just(Principal::Employee {
                tenant_id: TenantId(1),
                user_id: 1,
                role: Role::Buyer,
                overrides: HashMap::new(),
                store_scope: StoreScope::All,
            }),
            prop::collection::vec(0i64..6, 0..6).prop_map(|ids| Principal::Employee {
                tenant_id: TenantId(1),
                user_id: 1,
                role: Role::Buyer,
                overrides: HashMap::new(),
                store_scope: StoreScope::Restricted(ids),
            }),
        ]
    }

    proptest! {
        /// Property 10：对任意 (principal, stores, hidden)，
        /// `visible_stores` 的结果恒等于「范围允许 ∩ 非隐藏」独立计算的结果。
        ///
        /// **Validates: Requirements 4.3**
        #[test]
        fn visible_stores_equals_scope_intersect_non_hidden(
            principal in principal_strategy(),
            all in stores_strategy(),
            hidden in hidden_strategy(),
        ) {
            let result = principal.visible_stores(&all, &hidden);

            // 独立复算：范围允许判定（不引用被测实现的内部细节）。
            let scope_allows = |s: &Store| match &principal {
                Principal::SuperAdmin | Principal::CompanyAdmin { .. } => true,
                Principal::Employee { store_scope, .. } => match store_scope {
                    StoreScope::All => true,
                    StoreScope::Restricted(ids) => ids.contains(&s.id),
                },
            };
            // 期望集合 = 范围允许 ∩ 非隐藏（保序、不丢、不重）。
            let expected: Vec<Store> = all
                .iter()
                .filter(|s| scope_allows(s) && !hidden.contains(&s.dpqz))
                .cloned()
                .collect();

            // (1) 结果恒等于 范围 ∩ 非隐藏。
            prop_assert_eq!(&result, &expected);

            // (2) 隐藏 dpqz 店铺绝不出现在结果中。
            for s in &result {
                prop_assert!(
                    !hidden.contains(&s.dpqz),
                    "隐藏店铺 dpqz={:?} 不应出现在可见集合中",
                    s.dpqz
                );
            }

            // (3) 每个结果都满足主体店铺范围。
            for s in &result {
                prop_assert!(scope_allows(s), "结果店铺 id={} 超出主体范围", s.id);
            }
        }
    }
}

// ============================================================================
// Task 6.7: ensure_same_tenant 属性测试（Property 3：租户数据隔离，
// 见 design.md `## Correctness Properties` Property 3 / 5.6）
//
// **Property 3: 租户数据隔离**
// **Validates: Requirements 4.1**
//
// 形式化：∀ row. accessed(row) ⟹ row.tenant_id == principal.tenant_id ∨ principal == SuperAdmin。
//
// 等价的可执行不变式（针对守卫 `ensure_same_tenant`）：
//   - 非超管主体（CompanyAdmin / Employee），会话租户 T：
//       ensure_same_tenant(row_tenant) 当且仅当 row_tenant == T 时返回 Ok，
//       否则返回 AppError::Forbidden（HTTP 403），杜绝跨租户访问。
//   - SuperAdmin：跨租户运营，对任意 row_tenant 恒返回 Ok。
//
// 使用 proptest（dev-dependency）跨随机 (主体类型, 会话租户, 命中行租户) 输入验证该不变式。
// 仅新增测试模块，不改动任何 production impl 块。
// ============================================================================
#[cfg(test)]
mod prop_tenant_isolation_tests {
    use super::*;
    use proptest::prelude::*;

    /// 非超管主体的类型选择（CompanyAdmin / Employee）。
    /// 二者在租户隔离判定上共享同一语义（会话 `tenant_id` 与命中行比对）。
    #[derive(Debug, Clone, Copy)]
    enum NonSuperKind {
        CompanyAdmin,
        Employee,
    }

    /// 用给定会话租户构造一个非超管主体。
    fn make_non_super(kind: NonSuperKind, session_tenant: i64) -> Principal {
        match kind {
            NonSuperKind::CompanyAdmin => Principal::CompanyAdmin {
                tenant_id: TenantId(session_tenant),
            },
            NonSuperKind::Employee => Principal::Employee {
                tenant_id: TenantId(session_tenant),
                user_id: 42,
                role: Role::Buyer,
                overrides: HashMap::new(),
                store_scope: StoreScope::All,
            },
        }
    }

    fn non_super_kind_strategy() -> impl Strategy<Value = NonSuperKind> {
        prop_oneof![
            Just(NonSuperKind::CompanyAdmin),
            Just(NonSuperKind::Employee)
        ]
    }

    /// 租户 id 取小范围，使会话租户与命中行租户既能相等也能不等。
    fn tenant_id_strategy() -> impl Strategy<Value = i64> {
        0i64..5
    }

    proptest! {
        /// Property 3（非超管分支）：对任意 (非超管主体, 会话租户 T, 命中行租户),
        /// `ensure_same_tenant` 当且仅当 `row_tenant == T` 时为 `Ok`，否则为
        /// `AppError::Forbidden`（403）。
        ///
        /// **Validates: Requirements 4.1**
        #[test]
        fn non_super_ok_iff_same_tenant(
            kind in non_super_kind_strategy(),
            session_tenant in tenant_id_strategy(),
            row_tenant in tenant_id_strategy(),
        ) {
            let principal = make_non_super(kind, session_tenant);
            let result = principal.ensure_same_tenant(TenantId(row_tenant));

            if row_tenant == session_tenant {
                prop_assert!(
                    result.is_ok(),
                    "同租户应放行：session={session_tenant} row={row_tenant} kind={kind:?}"
                );
            } else {
                match result {
                    Err(AppError::Forbidden) => {}
                    other => prop_assert!(
                        false,
                        "跨租户必须返回 Forbidden(403)：session={session_tenant} row={row_tenant} kind={kind:?} got={other:?}"
                    ),
                }
            }
        }

        /// Property 3（超管分支）：SuperAdmin 跨租户运营，对任意命中行租户恒为 `Ok`。
        ///
        /// **Validates: Requirements 4.1**
        #[test]
        fn super_admin_always_ok(row_tenant in any::<i64>()) {
            prop_assert!(
                Principal::SuperAdmin
                    .ensure_same_tenant(TenantId(row_tenant))
                    .is_ok(),
                "SuperAdmin 应对任意租户行放行：row={row_tenant}"
            );
        }
    }
}
