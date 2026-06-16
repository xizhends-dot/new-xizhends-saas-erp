//! 定时任务：`order_archive`（按 `YEAR(imported_at)` 分批归档）—— Task 14.6 / Requirements 11.4。
//!
//! 语义对应 old 系统 `cron/order_archive.php`：把历史订单按年份迁移到归档表
//! `orders_{year}`，迁移完成后删除源表中的对应记录。与 old 的差异：
//! - old 是 CLI 手动按「平台 + 年份」执行；本实现作为多租户定时任务（[`Job`]），
//!   对**单个租户库**自动枚举需归档的年份并逐年处理。
//! - old 用 `LIMIT offset,limit` 翻页且最后一次性 `DELETE`；本实现改为
//!   **「取一批 id → 复制该批 → 删除该批」的每批事务**，避免 offset 随删除漂移，
//!   且让每批「复制+删除」原子化：复制成功才删除，复制失败整批回滚、源数据不丢。
//!
//! ## 年份选择
//! 仅归档 **早于当前年份** 的订单（`YEAR(imported_at) < 当前年`）。当年订单仍属活跃数据，
//! 不应被归档。该判定为设计决策（Requirements 11.4 仅约束「按 `YEAR(imported_at)` 归档」，
//! 未指定触发年份），如需归档当年数据可调整 [`should_archive_year`]。
//!
//! ## 实现约束
//! 与项目其余 DB 访问一致：**一律使用 SQLx 运行时 API（`query` / `query_scalar`），
//! 不使用编译期 `query!` 宏**——租户库在编译期并不存在。归档表名由年份（整数）拼接，
//! 年份来自 `YEAR()` 聚合，为纯数字，拼接安全（另由 [`archive_table_name`] 统一产出）。

use std::time::Duration;

use chrono::{Datelike, Utc};
use sqlx::MySqlPool;

use crate::error::AppError;
use crate::jobs::scheduler::{Job, JobContext};

/// 单批迁移的记录数（与 old 系统一致：每批 500 条）。
pub const BATCH_SIZE: i64 = 500;

/// 源表名（统一订单聚合根表）。
const SOURCE_TABLE: &str = "orders";

// ───────────────────────────────────────────────────────────────────────────
// 纯函数辅助（无 IO，便于单元测试）
// ───────────────────────────────────────────────────────────────────────────

/// 归档表名：`orders_{year}`。
///
/// `year` 为整数，产出的标识符不含用户可控文本，可安全用于 SQL 拼接。
pub fn archive_table_name(year: i32) -> String {
    format!("{SOURCE_TABLE}_{year}")
}

/// 是否应归档该年份：仅归档「早于当前年份」的数据（当年订单仍活跃，不归档）。
pub fn should_archive_year(year: i32, current_year: i32) -> bool {
    year < current_year
}

/// 计算把 `total` 条记录按 `batch_size` 切分所需的批次数（向上取整）。
///
/// `batch_size <= 0` 视为非法，返回 0（调用方不应以非正批量进入循环）。
pub fn batch_count(total: i64, batch_size: i64) -> i64 {
    if total <= 0 || batch_size <= 0 {
        return 0;
    }
    (total + batch_size - 1) / batch_size
}

/// 为 `IN (...)` 子句生成 `n` 个占位符：`?,?,...,?`。
///
/// `n == 0` 返回空串（调用方应避免对空批构造 `IN ()`）。
pub fn in_placeholders(n: usize) -> String {
    if n == 0 {
        return String::new();
    }
    let mut s = String::with_capacity(n * 2);
    for i in 0..n {
        if i > 0 {
            s.push(',');
        }
        s.push('?');
    }
    s
}

// ───────────────────────────────────────────────────────────────────────────
// 归档任务
// ───────────────────────────────────────────────────────────────────────────

/// `order_archive` 定时任务：按 `YEAR(imported_at)` 把历史订单分批归档到 `orders_{year}`。
///
/// 每个租户库独立执行（由调度器循环驱动）。运行周期默认每日一次。
#[derive(Debug, Default, Clone)]
pub struct OrderArchiveJob;

impl OrderArchiveJob {
    /// 构造任务实例。
    pub fn new() -> Self {
        Self
    }
}

#[async_trait::async_trait]
impl Job for OrderArchiveJob {
    fn name(&self) -> &'static str {
        "order_archive"
    }

    fn interval(&self) -> Duration {
        // 归档为低频维护任务，每日触发一次即可。
        Duration::from_secs(24 * 60 * 60)
    }

    async fn run_for_tenant(&self, ctx: &JobContext) -> Result<(), AppError> {
        let current_year = Utc::now().year();
        archive_tenant(&ctx.pool, current_year).await
    }
}

/// 对单个租户库执行归档：枚举需归档的年份，逐年分批迁移。
///
/// 仅迁移 `YEAR(imported_at) < current_year` 的年份（见模块文档「年份选择」）。
async fn archive_tenant(pool: &MySqlPool, current_year: i32) -> Result<(), AppError> {
    // 枚举源表中出现过的全部年份（升序），过滤出需归档年份。
    let years: Vec<i32> = sqlx::query_scalar::<_, i32>(
        "SELECT DISTINCT YEAR(`imported_at`) AS `y` \
         FROM `orders` \
         WHERE `imported_at` IS NOT NULL \
         ORDER BY `y` ASC",
    )
    .fetch_all(pool)
    .await?;

    for year in years {
        if !should_archive_year(year, current_year) {
            continue;
        }
        let migrated = archive_year(pool, year).await?;
        tracing::info!(year, migrated, "order_archive：年份归档完成");
    }

    Ok(())
}

/// 归档单个年份：建归档表后按 `BATCH_SIZE` 分批「复制→删除」，返回迁移条数。
async fn archive_year(pool: &MySqlPool, year: i32) -> Result<i64, AppError> {
    let archive_table = archive_table_name(year);

    // 1) 建归档表（结构与源表一致）。表名为 `orders_{整数}`，拼接安全。
    let create_sql = format!("CREATE TABLE IF NOT EXISTS `{archive_table}` LIKE `{SOURCE_TABLE}`");
    sqlx::query(&create_sql).execute(pool).await?;

    let mut migrated: i64 = 0;

    // 2) 逐批迁移：每批取一组 id → 在一个事务内复制并删除。
    loop {
        // 2a) 取下一批待归档的 id（按 id 升序，稳定且确定）。
        let ids: Vec<i64> = sqlx::query_scalar::<_, i64>(
            "SELECT `id` FROM `orders` \
             WHERE YEAR(`imported_at`) = ? \
             ORDER BY `id` ASC \
             LIMIT ?",
        )
        .bind(year)
        .bind(BATCH_SIZE)
        .fetch_all(pool)
        .await?;

        if ids.is_empty() {
            break;
        }

        let placeholders = in_placeholders(ids.len());

        // 2b) 每批事务：复制该批 → 删除该批。复制失败则整批回滚，源数据不丢。
        let mut tx = pool.begin().await?;

        // INSERT ... SELECT *：归档表由 `LIKE orders` 建立，列完全一致。
        let insert_sql = format!(
            "INSERT INTO `{archive_table}` \
             SELECT * FROM `{SOURCE_TABLE}` WHERE `id` IN ({placeholders})"
        );
        let mut insert_q = sqlx::query(&insert_sql);
        for id in &ids {
            insert_q = insert_q.bind(id);
        }
        insert_q.execute(&mut *tx).await?;

        // 删除已复制的同一批源记录。
        let delete_sql = format!("DELETE FROM `{SOURCE_TABLE}` WHERE `id` IN ({placeholders})");
        let mut delete_q = sqlx::query(&delete_sql);
        for id in &ids {
            delete_q = delete_q.bind(id);
        }
        delete_q.execute(&mut *tx).await?;

        tx.commit().await?;

        migrated += ids.len() as i64;

        // 取到的批次不足一批 → 已是最后一批，结束循环。
        if (ids.len() as i64) < BATCH_SIZE {
            break;
        }
    }

    Ok(migrated)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn archive_table_name_appends_year() {
        assert_eq!(archive_table_name(2021), "orders_2021");
        assert_eq!(archive_table_name(2010), "orders_2010");
    }

    #[test]
    fn should_archive_only_past_years() {
        assert!(should_archive_year(2021, 2024));
        assert!(should_archive_year(2023, 2024));
        // 当年及未来年份不归档。
        assert!(!should_archive_year(2024, 2024));
        assert!(!should_archive_year(2025, 2024));
    }

    #[test]
    fn batch_count_rounds_up() {
        assert_eq!(batch_count(0, BATCH_SIZE), 0);
        assert_eq!(batch_count(1, BATCH_SIZE), 1);
        assert_eq!(batch_count(500, 500), 1);
        assert_eq!(batch_count(501, 500), 2);
        assert_eq!(batch_count(1000, 500), 2);
        assert_eq!(batch_count(1001, 500), 3);
    }

    #[test]
    fn batch_count_handles_non_positive_inputs() {
        assert_eq!(batch_count(-5, 500), 0);
        assert_eq!(batch_count(100, 0), 0);
        assert_eq!(batch_count(100, -1), 0);
    }

    #[test]
    fn in_placeholders_builds_comma_separated_marks() {
        assert_eq!(in_placeholders(0), "");
        assert_eq!(in_placeholders(1), "?");
        assert_eq!(in_placeholders(3), "?,?,?");
        assert_eq!(in_placeholders(5).matches('?').count(), 5);
    }

    #[test]
    fn batch_size_matches_legacy_value() {
        assert_eq!(BATCH_SIZE, 500);
    }

    /// 任务元信息：名称用于分布式锁键与日志，周期为每日一次。
    #[test]
    fn job_metadata_is_stable() {
        let job = OrderArchiveJob::new();
        assert_eq!(job.name(), "order_archive");
        assert_eq!(job.interval(), Duration::from_secs(86_400));
    }
}
