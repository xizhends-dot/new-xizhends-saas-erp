//! 定时任务：采购状态统计快照（Task 14.8 / Requirements 11.1）。
//!
//! 语义对应 old 系统 `cron/caigou_status_stats.php`：每日对各平台按「采购/流程状态」
//! 分组计数，并把当日快照落库（明细表 + 汇总表，按 `(日期, 平台, 状态)` 幂等 upsert）。
//! 与 old 的差异：
//! - old 在 6 张镜像宽表 `ph_order{y,r,w,m,q,yp}` 上分平台 `GROUP BY beizhu`；本实现复用
//!   已规范化的统计服务 [`crate::services::stats_service::caigou_status_stats`]，对
//!   `order_items.purchase_status` 分组，按 `orders.platform` 过滤平台。
//! - old 单库执行；本实现作为多租户定时任务（[`Job`]），对**单个租户库**逐平台快照。
//!
//! ## 可测试接缝
//! 真正触达 DB 的只有「读取分组计数」（委托 stats_service）与「写快照」（`ensure_tables` +
//! upsert）。把「由统计结果装配为当日快照行」抽成纯函数 [`assemble_snapshot`]，可在无 DB
//! 环境下断言「明细行与统计结果一一对应」「汇总总数 = 各状态计数之和」。

use std::time::Duration;

use chrono::{NaiveDate, Utc};
use sqlx::MySqlPool;

use crate::error::AppError;
use crate::jobs::scheduler::{Job, JobContext};
use crate::services::stats_service::{self, PurchaseStatusStatsResult};

/// 需要分平台快照的平台代码（与 old 的 `['y','r','w','m','q','yp']` 一致）。
pub const PLATFORMS: [&str; 6] = ["y", "r", "w", "m", "q", "yp"];

/// 明细快照表名（按 `(stat_date, platform, status_name)` 唯一）。
const DETAIL_TABLE: &str = "caigou_status_daily";

/// 汇总快照表名（按 `(stat_date, platform)` 唯一）。
const SUMMARY_TABLE: &str = "caigou_status_daily_summary";

// ───────────────────────────────────────────────────────────────────────────
// 纯函数辅助（无 IO，便于单元测试）
// ───────────────────────────────────────────────────────────────────────────

/// 单个平台某日的采购状态快照（装配自统计结果，准备落库）。
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct StatusSnapshot {
    /// 统计日期（`YYYY-MM-DD`）。
    pub stat_date: String,
    /// 平台代码。
    pub platform: String,
    /// 每个状态一行：`(状态名, 数量)`。
    pub rows: Vec<(String, i64)>,
    /// 汇总：该平台子商品总数（= 各状态计数之和）。
    pub total: i64,
}

/// 把统计结果装配为当日某平台的快照（纯函数）。
///
/// 明细行与 [`PurchaseStatusStatsResult::stats`] 一一对应；汇总 `total` 直接取统计结果的
/// `total`（其语义为各状态计数之和，见 stats_service）。`stat_date` 以 `YYYY-MM-DD` 文本承载。
pub fn assemble_snapshot(
    stat_date: NaiveDate,
    platform: &str,
    result: &PurchaseStatusStatsResult,
) -> StatusSnapshot {
    let rows = result
        .stats
        .iter()
        .map(|s| (s.status_name.clone(), s.status_count))
        .collect();
    StatusSnapshot {
        stat_date: stat_date.format("%Y-%m-%d").to_string(),
        platform: platform.to_string(),
        rows,
        total: result.total,
    }
}

// ───────────────────────────────────────────────────────────────────────────
// 快照落库
// ───────────────────────────────────────────────────────────────────────────

/// 确保快照表存在（首次运行时建表，结构对齐 old `ph_caigou_status_daily{,_summary}`）。
async fn ensure_tables(pool: &MySqlPool) -> Result<(), AppError> {
    sqlx::query(&format!(
        "CREATE TABLE IF NOT EXISTS `{DETAIL_TABLE}` (\
            `id` BIGINT NOT NULL AUTO_INCREMENT,\
            `stat_date` DATE NOT NULL,\
            `platform` VARCHAR(10) NOT NULL,\
            `status_name` VARCHAR(100) NOT NULL,\
            `status_count` INT NOT NULL DEFAULT 0,\
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\
            PRIMARY KEY (`id`),\
            UNIQUE KEY `uk_date_platform_status` (`stat_date`, `platform`, `status_name`),\
            KEY `idx_stat_date` (`stat_date`)\
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ))
    .execute(pool)
    .await?;

    sqlx::query(&format!(
        "CREATE TABLE IF NOT EXISTS `{SUMMARY_TABLE}` (\
            `id` BIGINT NOT NULL AUTO_INCREMENT,\
            `stat_date` DATE NOT NULL,\
            `platform` VARCHAR(10) NOT NULL,\
            `total_orders` INT NOT NULL DEFAULT 0,\
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\
            PRIMARY KEY (`id`),\
            UNIQUE KEY `uk_date_platform` (`stat_date`, `platform`),\
            KEY `idx_stat_date` (`stat_date`)\
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ))
    .execute(pool)
    .await?;

    Ok(())
}

/// 幂等写入单平台快照：明细逐状态 upsert，汇总一行 upsert（重复执行同日覆盖计数）。
async fn persist_snapshot(pool: &MySqlPool, snapshot: &StatusSnapshot) -> Result<(), AppError> {
    for (status_name, count) in &snapshot.rows {
        sqlx::query(&format!(
            "INSERT INTO `{DETAIL_TABLE}` \
                (`stat_date`, `platform`, `status_name`, `status_count`, `created_at`) \
             VALUES (?, ?, ?, ?, NOW()) \
             ON DUPLICATE KEY UPDATE `status_count` = VALUES(`status_count`), `created_at` = NOW()"
        ))
        .bind(&snapshot.stat_date)
        .bind(&snapshot.platform)
        .bind(status_name)
        .bind(count)
        .execute(pool)
        .await?;
    }

    sqlx::query(&format!(
        "INSERT INTO `{SUMMARY_TABLE}` \
            (`stat_date`, `platform`, `total_orders`, `created_at`) \
         VALUES (?, ?, ?, NOW()) \
         ON DUPLICATE KEY UPDATE `total_orders` = VALUES(`total_orders`), `created_at` = NOW()"
    ))
    .bind(&snapshot.stat_date)
    .bind(&snapshot.platform)
    .bind(snapshot.total)
    .execute(pool)
    .await?;

    Ok(())
}

/// 对单个租户库逐平台快照采购状态（建表 → 统计 → 落库）。
async fn snapshot_tenant(pool: &MySqlPool, stat_date: NaiveDate) -> Result<(), AppError> {
    ensure_tables(pool).await?;

    for platform in PLATFORMS {
        let result = stats_service::caigou_status_stats(pool, Some(platform)).await?;
        let snapshot = assemble_snapshot(stat_date, platform, &result);
        persist_snapshot(pool, &snapshot).await?;
        tracing::info!(
            platform,
            statuses = snapshot.rows.len(),
            total = snapshot.total,
            "caigou_status_stats：平台快照完成"
        );
    }

    Ok(())
}

// ───────────────────────────────────────────────────────────────────────────
// 定时任务
// ───────────────────────────────────────────────────────────────────────────

/// `caigou_status_stats` 定时任务：每日对各平台采购状态分组计数并落库快照。
#[derive(Debug, Default, Clone)]
pub struct CaigouStatusStatsJob;

impl CaigouStatusStatsJob {
    /// 构造任务实例。
    pub fn new() -> Self {
        Self
    }
}

#[async_trait::async_trait]
impl Job for CaigouStatusStatsJob {
    fn name(&self) -> &'static str {
        "caigou_status_stats"
    }

    fn interval(&self) -> Duration {
        // 每日一次快照（与 old crontab `0 0 * * *` 语义一致）。
        Duration::from_secs(24 * 60 * 60)
    }

    async fn run_for_tenant(&self, ctx: &JobContext) -> Result<(), AppError> {
        let stat_date = Utc::now().date_naive();
        snapshot_tenant(&ctx.pool, stat_date).await
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::services::stats_service::PurchaseStatusStat;

    fn result(rows: &[(&str, i64)]) -> PurchaseStatusStatsResult {
        let stats = rows
            .iter()
            .map(|(n, c)| PurchaseStatusStat {
                status_name: (*n).to_string(),
                status_count: *c,
            })
            .collect();
        let total = rows.iter().map(|(_, c)| *c).sum();
        PurchaseStatusStatsResult { stats, total }
    }

    #[test]
    fn snapshot_maps_each_status_row() {
        let date = NaiveDate::from_ymd_opt(2024, 1, 5).unwrap();
        let r = result(&[("待处理", 10), ("已到货", 3)]);
        let snap = assemble_snapshot(date, "y", &r);

        assert_eq!(snap.stat_date, "2024-01-05");
        assert_eq!(snap.platform, "y");
        assert_eq!(
            snap.rows,
            vec![("待处理".to_string(), 10), ("已到货".to_string(), 3)]
        );
    }

    #[test]
    fn snapshot_total_equals_sum_of_status_counts() {
        let date = NaiveDate::from_ymd_opt(2024, 6, 30).unwrap();
        let r = result(&[("待处理", 10), ("已到货", 3), ("发货中", 7)]);
        let snap = assemble_snapshot(date, "r", &r);

        let sum: i64 = snap.rows.iter().map(|(_, c)| *c).sum();
        assert_eq!(snap.total, 20);
        assert_eq!(snap.total, sum, "汇总总数应等于各状态计数之和");
    }

    #[test]
    fn snapshot_empty_result_has_zero_total_and_no_rows() {
        let date = NaiveDate::from_ymd_opt(2024, 12, 1).unwrap();
        let snap = assemble_snapshot(date, "m", &result(&[]));
        assert!(snap.rows.is_empty());
        assert_eq!(snap.total, 0);
    }

    #[test]
    fn platforms_match_legacy_set() {
        assert_eq!(PLATFORMS, ["y", "r", "w", "m", "q", "yp"]);
    }

    #[test]
    fn job_metadata_is_stable() {
        let job = CaigouStatusStatsJob::new();
        assert_eq!(job.name(), "caigou_status_stats");
        assert_eq!(job.interval(), Duration::from_secs(86_400));
    }
}
