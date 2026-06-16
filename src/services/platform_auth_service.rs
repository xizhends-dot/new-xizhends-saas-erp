//! 平台授权服务（Platform_Auth_Service）（Task 11.2 / Requirements 7.1、7.2、7.3）。
//!
//! 本服务依据**主库**三张表（见设计 3.8）驱动租户侧栏平台菜单与超管后台「平台授权」：
//! - `platforms`：平台目录全集与排序（6 个平台 y/r/w/m/q/yp）——菜单的**目录与顺序**来源（Req 7.2）。
//! - `tenant_platform`：每租户每平台一条 `(enabled, locked)`——菜单的**显隐/锁定**来源（Req 7.1）。
//! - `tenants.status`：`suspended` 时整租户全部平台项不可用（Req 7.1）。
//!
//! 渲染规则（设计 3.8「渲染规则」与 Correctness Property 7 的形式化）：
//! - `enabled=false` ⟹ [`PlatformMenuState::Hidden`]（不出现）；
//! - `enabled=true ∧ locked=true` ⟹ [`PlatformMenuState::Locked`]（灰显锁定、不可进入）；
//! - `enabled=true ∧ locked=false` ⟹ [`PlatformMenuState::Normal`]（正常可点）；
//! - 叠加：`tenants.status='suspended'` ⟹ 已开通项一律降级为 [`PlatformMenuState::Locked`]
//!   （整租户不可用）；未开通项仍为 [`PlatformMenuState::Hidden`]（不出现的项不因停用而出现）。
//!
//! 设计取舍：把「三态判定」抽离为**纯函数** [`compute_menu_state`]，与数据库 I/O 解耦，
//! 既便于无 DB 单元测试（本文件）与属性测试（Task 11.3 / Property 7），又让 DB 加载函数
//! 只负责取数与映射。所有主库查询统一用运行时 `query_as` + 显式 SQL，保持与租户库一致的风格。

use serde::{Deserialize, Serialize};
use sqlx::{FromRow, MySqlPool};

use crate::error::AppError;
use crate::models::platform::Platform;

/// 侧栏单个平台项的渲染三态（设计 3.8 / Property 7）。
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
pub enum PlatformMenuState {
    /// 不显示（`enabled=false`）。
    Hidden,
    /// 显示但灰显锁定、不可进入（`enabled=true ∧ locked=true`，或租户已停用且该项已开通）。
    Locked,
    /// 正常可点（`enabled=true ∧ locked=false` 且租户未停用）。
    Normal,
}

/// 侧栏菜单的单个平台项：目录信息（来自 `platforms`）+ 计算所得渲染状态。
#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct PlatformMenuItem {
    /// 平台代码（`y/r/w/m/q/yp`），与 `platforms.code` 一致。
    pub code: String,
    /// 平台展示名称（来自 `platforms.name`）。
    pub name: String,
    /// 侧栏排序（来自 `platforms.sort_order`）。
    pub sort_order: i32,
    /// 该平台对该租户的渲染状态。
    pub state: PlatformMenuState,
}

/// 计算单个平台项的侧栏渲染状态（**纯函数**，无副作用）。
///
/// 入参语义：
/// - `enabled` / `locked`：取自该租户该平台的 `tenant_platform` 行；当不存在对应行时，
///   两者均按 `false` 处理（即默认未开通 → [`PlatformMenuState::Hidden`]）。
/// - `tenant_suspended`：`tenants.status == 'suspended'`。
///
/// 判定顺序（与 Property 7 的形式化一致）：
/// 1. `!enabled` ⟹ [`PlatformMenuState::Hidden`]（最高优先级：未开通永不出现，停用也不会让它出现）。
/// 2. 已开通但 `tenant_suspended` 或 `locked` ⟹ [`PlatformMenuState::Locked`]（出现但不可进入）。
/// 3. 其余（已开通、未锁定、未停用）⟹ [`PlatformMenuState::Normal`]。
pub fn compute_menu_state(
    enabled: bool,
    locked: bool,
    tenant_suspended: bool,
) -> PlatformMenuState {
    if !enabled {
        // 未开通：严格不显示（`hidden ⟺ ¬enabled`），且不因租户停用而出现。
        return PlatformMenuState::Hidden;
    }
    if tenant_suspended || locked {
        // 已开通但租户停用或被锁定：灰显锁定、不可进入。
        return PlatformMenuState::Locked;
    }
    PlatformMenuState::Normal
}

/// `platforms ⋈ tenant_platform` 的查询投影（LEFT JOIN，授权列可为空）。
#[derive(Debug, FromRow)]
struct MenuRow {
    code: String,
    name: String,
    sort_order: i32,
    /// LEFT JOIN 未命中时为 `NULL` ⟹ 视为未开通。
    enabled: Option<i8>,
    /// LEFT JOIN 未命中时为 `NULL` ⟹ 视为未锁定。
    locked: Option<i8>,
}

/// 加载某租户的完整侧栏平台菜单（**主库**查询）。
///
/// 实现（Req 7.1、7.2）：
/// 1. 读 `tenants.status` 判定是否 `suspended`；租户不存在 ⟹ [`AppError::TenantUnavailable`]。
/// 2. 以 `platforms` 为左表 LEFT JOIN 该租户的 `tenant_platform`，**按 `platforms.sort_order`
///    升序**（同序再按 `code`）取全集——保证菜单目录与顺序恒由 `platforms` 决定（Req 7.2）。
/// 3. 对每一项调用 [`compute_menu_state`] 得到三态，返回有序 [`Vec<PlatformMenuItem>`]。
///
/// 返回值**包含** [`PlatformMenuState::Hidden`] 项（保持「目录全集」语义）；上层模板按状态决定
/// 是否渲染（`Hidden` 不渲染、`Locked` 灰显带 🔒、`Normal` 正常可点）。
///
/// 仅访问主库 `master`，不触达租户库。
pub async fn load_sidebar_menu(
    master: &MySqlPool,
    tenant_id: i64,
) -> Result<Vec<PlatformMenuItem>, AppError> {
    // 1) 租户状态：决定是否整租户停用。
    let status: Option<(String,)> = sqlx::query_as("SELECT `status` FROM `tenants` WHERE `id` = ?")
        .bind(tenant_id)
        .fetch_optional(master)
        .await?;
    let Some((status,)) = status else {
        // 租户不存在：按不可用处理（与连接池/中间件语义一致）。
        return Err(AppError::TenantUnavailable);
    };
    let suspended = status == "suspended";

    // 2) 目录全集 ⋈ 授权，按 platforms 排序取全集。
    let rows: Vec<MenuRow> = sqlx::query_as::<_, MenuRow>(
        "SELECT p.`code` AS `code`, p.`name` AS `name`, p.`sort_order` AS `sort_order`, \
                tp.`enabled` AS `enabled`, tp.`locked` AS `locked` \
         FROM `platforms` p \
         LEFT JOIN `tenant_platform` tp \
           ON tp.`platform_code` = p.`code` AND tp.`tenant_id` = ? \
         ORDER BY p.`sort_order` ASC, p.`code` ASC",
    )
    .bind(tenant_id)
    .fetch_all(master)
    .await?;

    // 3) 逐项计算三态。
    let items = rows
        .into_iter()
        .map(|r| {
            let enabled = r.enabled.unwrap_or(0) != 0;
            let locked = r.locked.unwrap_or(0) != 0;
            PlatformMenuItem {
                code: r.code,
                name: r.name,
                sort_order: r.sort_order,
                state: compute_menu_state(enabled, locked, suspended),
            }
        })
        .collect();

    Ok(items)
}

/// 超管对某租户某平台的「开通 / 锁定」开关执行 upsert（Req 7.3，改动即时生效）。
///
/// 行为：
/// - 校验 `platform_code` 属于平台目录全集（`y/r/w/m/q/yp`，见 [`Platform::from_code`]）；
///   未知代码返回 [`AppError::Validation`]，避免越过目录写入孤立授权行（亦先于 DB 外键报错给出友好提示）。
/// - 对主库 `tenant_platform` 按唯一键 `(tenant_id, platform_code)` 执行
///   `INSERT ... ON DUPLICATE KEY UPDATE`：不存在则插入、已存在则更新 `enabled` / `locked`。
/// - 因菜单渲染每次都实时读取 `tenant_platform`（见 [`load_sidebar_menu`]），upsert 落库后
///   下一次渲染即反映新值——「改动即时生效」由读路径无缓存保证。
///
/// 仅访问主库 `master`。
pub async fn set_tenant_platform(
    master: &MySqlPool,
    tenant_id: i64,
    platform_code: &str,
    enabled: bool,
    locked: bool,
) -> Result<(), AppError> {
    // 目录全集校验：platform_code 必须是已知平台代码。
    if Platform::from_code(platform_code).is_none() {
        return Err(AppError::Validation(format!(
            "未知平台代码：{platform_code}"
        )));
    }

    sqlx::query(
        "INSERT INTO `tenant_platform` (`tenant_id`, `platform_code`, `enabled`, `locked`) \
         VALUES (?, ?, ?, ?) \
         ON DUPLICATE KEY UPDATE `enabled` = VALUES(`enabled`), `locked` = VALUES(`locked`)",
    )
    .bind(tenant_id)
    .bind(platform_code)
    .bind(enabled as i8)
    .bind(locked as i8)
    .execute(master)
    .await?;

    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    /// 全部 8 种 (enabled, locked, suspended) 组合的三态判定（Req 7.1 / Property 7）。
    #[test]
    fn compute_menu_state_covers_all_combinations() {
        // 未开通：无论锁定/停用如何，一律 Hidden（hidden ⟺ ¬enabled）。
        assert_eq!(
            compute_menu_state(false, false, false),
            PlatformMenuState::Hidden
        );
        assert_eq!(
            compute_menu_state(false, true, false),
            PlatformMenuState::Hidden
        );
        assert_eq!(
            compute_menu_state(false, false, true),
            PlatformMenuState::Hidden
        );
        assert_eq!(
            compute_menu_state(false, true, true),
            PlatformMenuState::Hidden
        );

        // 已开通、未锁定、未停用：Normal。
        assert_eq!(
            compute_menu_state(true, false, false),
            PlatformMenuState::Normal
        );

        // 已开通但锁定：Locked。
        assert_eq!(
            compute_menu_state(true, true, false),
            PlatformMenuState::Locked
        );

        // 已开通、未锁定但租户停用：Locked（整租户不可用）。
        assert_eq!(
            compute_menu_state(true, false, true),
            PlatformMenuState::Locked
        );

        // 已开通、锁定且停用：Locked。
        assert_eq!(
            compute_menu_state(true, true, true),
            PlatformMenuState::Locked
        );
    }

    #[test]
    fn hidden_iff_not_enabled() {
        // 形式化：render == hidden ⟺ ¬enabled（与 locked/suspended 无关）。
        for &locked in &[false, true] {
            for &suspended in &[false, true] {
                assert_eq!(
                    compute_menu_state(false, locked, suspended),
                    PlatformMenuState::Hidden,
                    "未开通必为 Hidden (locked={locked}, suspended={suspended})"
                );
                assert_ne!(
                    compute_menu_state(true, locked, suspended),
                    PlatformMenuState::Hidden,
                    "已开通不应为 Hidden (locked={locked}, suspended={suspended})"
                );
            }
        }
    }

    #[test]
    fn normal_only_when_enabled_unlocked_active() {
        // Normal 当且仅当 已开通 ∧ 未锁定 ∧ 未停用。
        for &enabled in &[false, true] {
            for &locked in &[false, true] {
                for &suspended in &[false, true] {
                    let is_normal =
                        compute_menu_state(enabled, locked, suspended) == PlatformMenuState::Normal;
                    let expected = enabled && !locked && !suspended;
                    assert_eq!(
                        is_normal, expected,
                        "Normal 判定不符 (enabled={enabled}, locked={locked}, suspended={suspended})"
                    );
                }
            }
        }
    }

    #[test]
    fn suspended_disables_enabled_items() {
        // 租户停用：已开通项从 Normal 降级为 Locked；未开通项仍 Hidden。
        assert_eq!(
            compute_menu_state(true, false, true),
            PlatformMenuState::Locked
        );
        assert_eq!(
            compute_menu_state(true, true, true),
            PlatformMenuState::Locked
        );
        assert_eq!(
            compute_menu_state(false, false, true),
            PlatformMenuState::Hidden
        );
    }

    #[test]
    fn menu_state_serde_round_trip() {
        for state in [
            PlatformMenuState::Hidden,
            PlatformMenuState::Locked,
            PlatformMenuState::Normal,
        ] {
            let json = serde_json::to_string(&state).unwrap();
            let back: PlatformMenuState = serde_json::from_str(&json).unwrap();
            assert_eq!(back, state);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // Property 7：平台菜单渲染由授权严格决定（Task 11.3 / Validates: Requirements 7.1）
    //
    // 形式化（design.md「Correctness Properties · Property 7」）：
    //   render(tp) == hidden ⟺ ¬tp.enabled
    //   render(tp) == locked ⟺ tp.enabled ∧ (tp.locked ∨ suspended)
    //   render(tp) == active ⟺ tp.enabled ∧ ¬tp.locked ∧ ¬suspended
    // 即：渲染三态严格由授权三元组 (enabled, locked, suspended) 决定，与其他状态无关。
    // ──────────────────────────────────────────────────────────────────────
    use proptest::prelude::*;

    proptest! {
        /// Property 7：对任意 (enabled, locked, suspended) 布尔三元组，渲染三态映射严格成立。
        ///
        /// **Validates: Requirements 7.1**
        #[test]
        fn prop_menu_render_strictly_determined_by_authorization(
            enabled in any::<bool>(),
            locked in any::<bool>(),
            suspended in any::<bool>(),
        ) {
            let state = compute_menu_state(enabled, locked, suspended);

            // 三态划分互斥且完备：恰好命中一个分支。
            let is_hidden = state == PlatformMenuState::Hidden;
            let is_locked = state == PlatformMenuState::Locked;
            let is_normal = state == PlatformMenuState::Normal;
            prop_assert_eq!(
                (is_hidden as u8) + (is_locked as u8) + (is_normal as u8),
                1,
                "三态必须恰好命中其一 (enabled={}, locked={}, suspended={})",
                enabled, locked, suspended
            );

            // hidden ⟺ ¬enabled。
            prop_assert_eq!(
                is_hidden, !enabled,
                "hidden ⟺ ¬enabled 不成立 (enabled={}, locked={}, suspended={})",
                enabled, locked, suspended
            );

            // normal ⟺ enabled ∧ ¬locked ∧ ¬suspended。
            prop_assert_eq!(
                is_normal, enabled && !locked && !suspended,
                "normal ⟺ enabled∧¬locked∧¬suspended 不成立 (enabled={}, locked={}, suspended={})",
                enabled, locked, suspended
            );

            // locked ⟺ enabled ∧ (locked ∨ suspended)。
            prop_assert_eq!(
                is_locked, enabled && (locked || suspended),
                "locked ⟺ enabled∧(locked∨suspended) 不成立 (enabled={}, locked={}, suspended={})",
                enabled, locked, suspended
            );
        }

        /// Property 7（决定性子性质）：渲染结果是 (enabled, locked, suspended) 的纯函数——
        /// 相同输入恒得相同输出，不依赖任何外部/隐藏状态。
        ///
        /// **Validates: Requirements 7.1**
        #[test]
        fn prop_menu_render_is_deterministic(
            enabled in any::<bool>(),
            locked in any::<bool>(),
            suspended in any::<bool>(),
        ) {
            prop_assert_eq!(
                compute_menu_state(enabled, locked, suspended),
                compute_menu_state(enabled, locked, suspended),
            );
        }
    }
}
