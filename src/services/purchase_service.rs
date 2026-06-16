//! 采购服务：`caigou_user` 一次性赋值规则、采购记录（caigou_record）upsert。
//!
//! 对应 Task 8.11 / Requirements 6.1、6.2，落地 `design.md` 6.5 的
//! `on_save_purchase` 算法。
//!
//! 两条业务规则（保存订单/采购时触发）：
//!
//! 1. **采购人一次性赋值（Requirements 6.1）**：当本次保存带有非空 `tabaono`
//!    （1688 订单号）且该子商品 `caigou_user` 当前为空时，把当前登录用户写入
//!    `order_items.caigou_user`——**仅写一次**，后续任何保存都不得覆盖。
//!    “只写一次/不覆盖” 由 [`crate::repository::order_repo::set_caigou_user`] 在
//!    SQL 层用 `WHERE caigou_user IS NULL OR caigou_user = ''` 强制保证；本服务只负责
//!    “是否值得尝试写入” 的编排：仅当存在非空 `tabaono` 时才发起写入。
//!
//! 2. **采购记录 upsert（Requirements 6.2）**：当子商品 `purchase_status` 变为
//!    「国内采购-准备」或「国内采购-已采购」时，同步写入/更新该子商品对应的
//!    `purchases` 行（按 `order_item_id` 关联，一个子商品一条）。
//!    `purchases` 表对 `order_item_id` 没有唯一约束（仅普通索引），因此 upsert 采用
//!    “事务内先查后写”（存在则 UPDATE，否则 INSERT），而非 `ON DUPLICATE KEY UPDATE`。
//!
//! 与项目其余数据访问一致：**一律使用 SQLx 运行时 API（`query` / `query_as` + 显式
//! SQL），不使用编译期 `query!` 宏**（租户库在编译期不存在，无法做编译期校验）。

use sqlx::types::chrono::NaiveDateTime;
use sqlx::MySqlPool;

use crate::error::AppError;
use crate::models::order::PurchaseStatus;
use crate::repository::order_repo;

/// 保存采购/订单时的输入。
///
/// 承载触发两条规则所需的数据：`tabaono` 与 `current_user` 用于采购人赋值；
/// `new_status` 用于判定是否触发采购记录 upsert；其余字段是 `purchases` 行
/// （caigou_record）的可编辑内容。
#[derive(Debug, Clone, Default)]
pub struct SavePurchaseInput {
    /// 目标子商品 id（`order_items.id`）。
    pub order_item_id: i64,
    /// 本次保存填入的 1688 订单号；为空（或全空白）表示本次未填写 `tabaono`。
    pub tabaono: String,
    /// 当前登录用户名（采购人候选）。
    pub current_user: String,
    /// 本次保存后的采购流程状态。
    pub new_status: PurchaseStatus,
    /// 采购链接。
    pub caigou_link: String,
    /// 补货链接。
    pub buhuo_link: String,
    /// 采购单号集合（原 `caigou_ordernums`）。
    pub caigou_ordernums: String,
    /// 采购金额（`cnamount`，DECIMAL 以字符串无损承载）。
    pub cn_amount: String,
    /// `comamount`（DECIMAL 以字符串无损承载）。
    pub com_amount: String,
    /// 国内运单号（`shipno`）。
    pub cn_ship_number: String,
    /// 采购时间，可空。
    pub caigou_time: Option<NaiveDateTime>,
}

/// [`on_save_purchase`] 的执行结果，便于调用方/测试观察实际发生的副作用。
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub struct SavePurchaseOutcome {
    /// 本次是否确实写入了 `caigou_user`（此前为空、首次写入）。
    /// `false` 表示未尝试（无 `tabaono`）或已有采购人未被覆盖。
    pub caigou_user_written: bool,
    /// 本次是否对采购记录（`purchases` 行）做了 upsert（状态触发时）。
    pub caigou_record_upserted: bool,
}

/// 是否应尝试写入 `caigou_user`（Requirements 6.1 的编排判定，纯逻辑）。
///
/// 规则：仅当本次保存带有非空（去空白后非空）的 `tabaono` 时才发起写入尝试。
/// “是否首次 / 当前是否为空” 的最终把关交由 SQL 层的一次性写入保证，本函数不关心。
pub fn should_attempt_caigou_user_write(tabaono: &str) -> bool {
    !tabaono.trim().is_empty()
}

/// 解析一次保存后 `caigou_user` **应当**停留的值（Requirements 6.1 的纯模型）。
///
/// 这是对 [`crate::repository::order_repo::set_caigou_user`] 在 SQL 层
/// （`WHERE caigou_user IS NULL OR caigou_user = ''`）所保证语义的**纯函数镜像**，
/// 不触达数据库即可单测/属性测试「一次性赋值不可覆盖」这一不变式：
///
/// - `current` 已为非空（既非 `None` 亦非 `""`）⟹ 一次性赋值已完成，**恒返回 `current`**，
///   与本次 `candidate_user` / `tabaono` 无关（不覆盖）；
/// - `current` 为空（`None` 或 `""`）且本次带非空 `tabaono`
///   （[`should_attempt_caigou_user_write`]）⟹ 写入候选用户 `candidate_user`（首次赋值）；
/// - `current` 为空但本次未填 `tabaono` ⟹ 保持原空值（不发起写入）。
///
/// 返回 `None` 表示「最终仍为空」，`Some(v)` 表示最终存储的字符串值。
///
/// “空” 的判定与 SQL 守卫严格一致：仅 `None` 或精确空串 `""` 视为空；
/// 含空白的非空串（如 `" "`）已是非空值，不再被覆盖。
pub fn resolve_caigou_user(
    current: Option<&str>,
    candidate_user: &str,
    tabaono: &str,
) -> Option<String> {
    let current_is_empty = match current {
        None => true,
        Some(s) => s.is_empty(),
    };

    // 已有非空采购人：一次性赋值已锁定，任何后续保存都不得改变其值。
    if !current_is_empty {
        return current.map(str::to_string);
    }

    // 空槽：仅当本次带非空 tabaono 才发起首次写入（SQL 守卫匹配空槽）。
    if should_attempt_caigou_user_write(tabaono) {
        return Some(candidate_user.to_string());
    }

    // 空槽但本次未填 tabaono：保持原空值。
    current.map(str::to_string)
}

/// 该流程状态是否应触发采购记录 upsert（Requirements 6.2 的判定，纯逻辑）。
///
/// 仅「国内采购-准备」(`CnPreparing`) 与「国内采购-已采购」(`CnPurchased`) 触发。
pub fn status_triggers_caigou_record(status: PurchaseStatus) -> bool {
    matches!(
        status,
        PurchaseStatus::CnPreparing | PurchaseStatus::CnPurchased
    )
}

/// 保存采购/订单时编排两条业务规则（Requirements 6.1、6.2）。
///
/// 步骤：
/// 1. 若本次带非空 `tabaono`，尝试一次性写入 `caigou_user`（SQL 层保证不覆盖既有值）。
/// 2. 若 `new_status` ∈ {国内采购-准备, 国内采购-已采购}，upsert 对应的采购记录。
///
/// 返回 [`SavePurchaseOutcome`] 记录两项副作用是否实际发生。
pub async fn on_save_purchase(
    pool: &MySqlPool,
    input: &SavePurchaseInput,
) -> Result<SavePurchaseOutcome, AppError> {
    let mut outcome = SavePurchaseOutcome::default();

    // 规则 1：首次写入 tabaono 且 caigou_user 为空 → 写入当前用户（仅一次，不覆盖）。
    if should_attempt_caigou_user_write(&input.tabaono) {
        outcome.caigou_user_written =
            order_repo::set_caigou_user(pool, input.order_item_id, &input.current_user).await?;
    }

    // 规则 2：状态变「国内采购-准备/已采购」→ upsert 采购记录。
    if status_triggers_caigou_record(input.new_status) {
        upsert_caigou_record(pool, input).await?;
        outcome.caigou_record_upserted = true;
    }

    Ok(outcome)
}

/// upsert 采购记录（`purchases` 行，按 `order_item_id` 关联，Requirements 6.2）。
///
/// `purchases.order_item_id` 无唯一约束（仅普通索引），故在事务内 “先查后写”：
/// - 已存在 → `UPDATE` 该行的可编辑字段；
/// - 不存在 → `INSERT` 新行。
///
/// `purchases.caigou_user`（采购人）取自该子商品 `order_items.caigou_user` 的当前值
/// （经规则 1 处理后的权威值），以保证两处采购人一致；子商品无采购人时落空串
/// （列定义 `NOT NULL DEFAULT ''`）。
async fn upsert_caigou_record(pool: &MySqlPool, input: &SavePurchaseInput) -> Result<(), AppError> {
    let mut tx = pool.begin().await?;

    // 取子商品当前采购人（规则 1 之后的权威值），用于落入 purchases.caigou_user。
    let caigou_user: Option<String> =
        sqlx::query_scalar("SELECT `caigou_user` FROM `order_items` WHERE `id` = ?")
            .bind(input.order_item_id)
            .fetch_optional(&mut *tx)
            .await?
            .flatten();
    let caigou_user = caigou_user.unwrap_or_default();

    // 该子商品是否已有采购记录。
    let existing_id: Option<i64> =
        sqlx::query_scalar("SELECT `id` FROM `purchases` WHERE `order_item_id` = ? LIMIT 1")
            .bind(input.order_item_id)
            .fetch_optional(&mut *tx)
            .await?;

    if let Some(id) = existing_id {
        sqlx::query(
            "UPDATE `purchases` SET \
                `tabaono` = ?, `caigou_link` = ?, `buhuo_link` = ?, `caigou_user` = ?, \
                `caigou_time` = ?, `caigou_ordernums` = ?, `cn_amount` = ?, \
                `com_amount` = ?, `cn_ship_number` = ? \
             WHERE `id` = ?",
        )
        .bind(&input.tabaono)
        .bind(&input.caigou_link)
        .bind(&input.buhuo_link)
        .bind(&caigou_user)
        .bind(input.caigou_time)
        .bind(&input.caigou_ordernums)
        .bind(&input.cn_amount)
        .bind(&input.com_amount)
        .bind(&input.cn_ship_number)
        .bind(id)
        .execute(&mut *tx)
        .await?;
    } else {
        sqlx::query(
            "INSERT INTO `purchases` \
                (`order_item_id`, `tabaono`, `caigou_link`, `buhuo_link`, `caigou_user`, \
                 `caigou_time`, `caigou_ordernums`, `cn_amount`, `com_amount`, `cn_ship_number`) \
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        )
        .bind(input.order_item_id)
        .bind(&input.tabaono)
        .bind(&input.caigou_link)
        .bind(&input.buhuo_link)
        .bind(&caigou_user)
        .bind(input.caigou_time)
        .bind(&input.caigou_ordernums)
        .bind(&input.cn_amount)
        .bind(&input.com_amount)
        .bind(&input.cn_ship_number)
        .execute(&mut *tx)
        .await?;
    }

    tx.commit().await?;
    Ok(())
}

// ============================================================================
// 单元测试（纯决策逻辑，无需 DB）
//
// `on_save_purchase` / `upsert_caigou_record` 依赖租户库 MySQL 连接，无法离线运行；
// 这里对两条规则的纯判定函数做覆盖，保证编排分支与 Requirements 6.1/6.2 一致。
// ============================================================================
#[cfg(test)]
mod tests {
    use super::*;
    use proptest::prelude::*;

    #[test]
    fn caigou_user_write_attempted_only_when_tabaono_present() {
        // 非空 tabaono → 尝试写入。
        assert!(should_attempt_caigou_user_write("AB1688-001"));
        assert!(should_attempt_caigou_user_write(" 12345 "));
        // 空 / 全空白 → 不尝试（本次未填 tabaono）。
        assert!(!should_attempt_caigou_user_write(""));
        assert!(!should_attempt_caigou_user_write("   "));
        assert!(!should_attempt_caigou_user_write("\t\n"));
    }

    #[test]
    fn only_cn_preparing_and_cn_purchased_trigger_caigou_record() {
        assert!(status_triggers_caigou_record(PurchaseStatus::CnPreparing));
        assert!(status_triggers_caigou_record(PurchaseStatus::CnPurchased));
    }

    #[test]
    fn other_statuses_do_not_trigger_caigou_record() {
        for st in PurchaseStatus::ALL {
            let expected = matches!(
                st,
                PurchaseStatus::CnPreparing | PurchaseStatus::CnPurchased
            );
            assert_eq!(
                status_triggers_caigou_record(st),
                expected,
                "状态 `{}` 的触发判定不符合预期",
                st.as_str()
            );
        }
        // 抽样确认非触发状态。
        assert!(!status_triggers_caigou_record(PurchaseStatus::Pending));
        assert!(!status_triggers_caigou_record(PurchaseStatus::CnProblem));
        assert!(!status_triggers_caigou_record(
            PurchaseStatus::CnPurchasedTbPdd
        ));
        assert!(!status_triggers_caigou_record(
            PurchaseStatus::ShippedToJapan
        ));
        assert!(!status_triggers_caigou_record(PurchaseStatus::Cancelled));
    }

    #[test]
    fn outcome_defaults_to_no_side_effects() {
        let outcome = SavePurchaseOutcome::default();
        assert!(!outcome.caigou_user_written);
        assert!(!outcome.caigou_record_upserted);
    }

    // --- resolve_caigou_user 纯模型的示例单测（Requirements 6.1） ---

    #[test]
    fn resolve_first_write_assigns_candidate() {
        // 空槽 + 带 tabaono → 首次写入候选用户。
        assert_eq!(
            resolve_caigou_user(None, "alice", "AB1688-001"),
            Some("alice".to_string())
        );
        assert_eq!(
            resolve_caigou_user(Some(""), "bob", "12345"),
            Some("bob".to_string())
        );
    }

    #[test]
    fn resolve_no_tabaono_keeps_empty() {
        // 空槽但本次未填 tabaono → 保持空，不写入。
        assert_eq!(resolve_caigou_user(None, "alice", ""), None);
        assert_eq!(resolve_caigou_user(None, "alice", "   "), None);
        assert_eq!(
            resolve_caigou_user(Some(""), "alice", ""),
            Some(String::new())
        );
    }

    #[test]
    fn resolve_existing_user_is_never_overwritten() {
        // 已有非空采购人 → 任何候选/任何 tabaono 都不覆盖。
        assert_eq!(
            resolve_caigou_user(Some("alice"), "bob", "NEW-TABAONO"),
            Some("alice".to_string())
        );
        // 含空白的非空值同样是「已赋值」，不被覆盖。
        assert_eq!(
            resolve_caigou_user(Some(" "), "bob", "NEW-TABAONO"),
            Some(" ".to_string())
        );
    }

    // ========================================================================
    // 属性测试（Property-Based）
    // ========================================================================

    /// 候选用户/已存值生成器：覆盖空串与非空串。
    fn user_strategy() -> impl Strategy<Value = String> {
        prop::string::string_regex("[ A-Za-z0-9]{0,5}").unwrap()
    }

    /// 非空采购人生成器（去空白后仍非空），用于「已赋值」场景。
    fn nonempty_user_strategy() -> impl Strategy<Value = String> {
        prop::string::string_regex("[A-Za-z0-9]{1,5}").unwrap()
    }

    /// tabaono 生成器：覆盖空、全空白、非空（触发/不触发首次写入）。
    fn tabaono_strategy() -> impl Strategy<Value = String> {
        prop::string::string_regex("[ A-Za-z0-9]{0,5}").unwrap()
    }

    proptest! {
        /// **Property 2a: caigou_user 一旦非空，恒不被覆盖**
        ///
        /// 对任意「当前已为非空采购人 `actor`」的子商品，无论本次保存带何种候选用户
        /// `candidate` 与运单/订单号 `tabaono`，解析结果恒等于 `actor`——即首次赋值后
        /// 任何保存都不改变其值。
        ///
        /// 形式化：`∀ actor(非空), candidate, tabaono.
        ///          resolve_caigou_user(Some(actor), candidate, tabaono) == Some(actor)`。
        ///
        /// **Validates: Requirements 6.1**
        #[test]
        fn prop_caigou_user_set_is_never_overwritten(
            actor in nonempty_user_strategy(),
            candidate in user_strategy(),
            tabaono in tabaono_strategy(),
        ) {
            let result = resolve_caigou_user(Some(&actor), &candidate, &tabaono);
            prop_assert_eq!(result, Some(actor));
        }

        /// **Property 2b: 首次非空赋值在任意保存序列下幂等稳定**
        ///
        /// 对任意初始状态与任意保存操作序列（每步给出候选用户与 tabaono），逐步折叠
        /// `resolve_caigou_user`：一旦某步使 `caigou_user` 由空变为某非空值 `actor`，
        /// 则其后所有步骤的状态恒等于 `actor`（不被任何后续保存覆盖）。
        ///
        /// 形式化：令 `state₀ = current`，`stateₙ₊₁ = resolve(stateₙ, userₙ, tabaonoₙ)`，
        /// 若存在最小 `k` 使 `stateₖ` 为非空 `actor`，则 `∀ j ≥ k. stateⱼ == actor`。
        /// 特别地，二次应用与首个非空结果一致（idempotent）。
        ///
        /// **Validates: Requirements 6.1**
        #[test]
        fn prop_caigou_user_first_nonempty_assignment_is_stable(
            initial in prop::option::of(user_strategy()),
            ops in prop::collection::vec((user_strategy(), tabaono_strategy()), 0..8),
        ) {
            // 折叠保存序列，记录第一次出现的非空值及其后续状态。
            let mut state: Option<String> = initial;
            let mut locked: Option<String> = None;

            for (candidate, tabaono) in &ops {
                let next = resolve_caigou_user(state.as_deref(), candidate, tabaono);

                if let Some(actor) = &locked {
                    // 已锁定：后续每一步状态都必须恒等于首个非空值。
                    prop_assert_eq!(
                        next.as_deref(),
                        Some(actor.as_str()),
                        "已赋值的 caigou_user 不应被后续保存覆盖"
                    );
                } else if let Some(v) = &next {
                    if !v.is_empty() {
                        // 首次由空变为非空：锁定该值。
                        locked = Some(v.clone());
                    }
                }

                state = next;
            }

            // 幂等性的直接体现：对最终状态再应用一次解析，结果不变（双重应用 == 单次）。
            if let Some(actor) = &locked {
                let once = state.clone();
                let twice = resolve_caigou_user(
                    state.as_deref(),
                    "ANY_OTHER_USER",
                    "ANY_OTHER_TABAONO",
                );
                prop_assert_eq!(&once, &twice);
                prop_assert_eq!(twice.as_deref(), Some(actor.as_str()));
            }
        }
    }
}
