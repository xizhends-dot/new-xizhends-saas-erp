//! 定时任务：清理过期主图目录（含 dry-run 预览变体）（Task 14.8 / Requirements 11.1）。
//!
//! 语义对应 old 系统 `cron/cleanup_old_images.php`（实际删除）与
//! `cron/cleanup_old_images_preview.php`（仅预览不删）：扫描图片根目录下的「日期目录」，
//! 删除早于保留期的目录，只留最近 `keep_months` 个月的数据。与 old 的差异：
//! - old 目录名是 4 位 `ym`（如 `2401`），按字符串比较 `< cutoff_ym`；本实现的目录名是
//!   [`crate::jobs::zhutu_downloader::date_dir`] 产出的完整日期 `YYYY-MM-DD`，按真实日期比较，
//!   更稳健（不受跨世纪/字符串比较歧义影响）。
//! - old 把预览与删除拆成两个脚本；本实现合一：[`plan_cleanup`] 永远只「列出将删除的候选」，
//!   `dry_run` 仅决定是否在计划之后真正调用删除——预览即「执行 `plan_cleanup` 但不删」。
//!
//! ## 纯函数接缝
//! 「某目录是否该删」是纯判定 [`should_delete_dir`]，「保留期截止日」是纯计算 [`cutoff_date`]，
//! 「由目录清单产出删除候选」是纯函数 [`plan_cleanup`]——三者均无 IO，可完整单测，含
//! 「dry-run 返回候选但不触发删除」这一关键性质。文件系统访问收敛在 [`DirStore`] trait 之后。

use std::time::Duration;

use async_trait::async_trait;
use chrono::{Datelike, NaiveDate, Utc};

use crate::error::AppError;
use crate::jobs::scheduler::{Job, JobContext};
use crate::jobs::zhutu_downloader::IMAGE_ROOT;

/// 默认保留月数（12 个月 = 1 年，与 old `$keep_months = 12` 一致）。
pub const DEFAULT_KEEP_MONTHS: u32 = 12;

// ───────────────────────────────────────────────────────────────────────────
// 纯函数辅助（无 IO，便于单元测试）
// ───────────────────────────────────────────────────────────────────────────

/// 由「当前日期」与「保留月数」计算保留截止日（纯函数）。
///
/// 早于该截止日（`< cutoff`）的目录将被清理；当月及其后保留。按月回退，跨年自动借位；
/// 若回退后落在不存在的日（如某月没有 31 号），夹取到该月最后一天。
pub fn cutoff_date(today: NaiveDate, keep_months: u32) -> NaiveDate {
    let total_months = today.year() * 12 + (today.month0() as i32) - keep_months as i32;
    let year = total_months.div_euclid(12);
    let month0 = total_months.rem_euclid(12) as u32;
    let month = month0 + 1;
    // 夹取日，避免如 3/31 回退到 2 月时溢出。
    let day = clamp_day(year, month, today.day());
    NaiveDate::from_ymd_opt(year, month, day).expect("clamped date must be valid")
}

/// 把 `day` 夹取到 `(year, month)` 的合法范围内（1..=该月天数）。
fn clamp_day(year: i32, month: u32, day: u32) -> u32 {
    let last = last_day_of_month(year, month);
    day.clamp(1, last)
}

/// 计算某年某月的最后一天（28/29/30/31）。
fn last_day_of_month(year: i32, month: u32) -> u32 {
    let (ny, nm) = if month == 12 {
        (year + 1, 1)
    } else {
        (year, month + 1)
    };
    let first_next = NaiveDate::from_ymd_opt(ny, nm, 1).expect("valid first-of-month");
    first_next.pred_opt().expect("has previous day").day()
}

/// 判定单个目录是否应删除（纯函数）。
///
/// 仅当目录名能解析为 `YYYY-MM-DD` 日期，且该日期 `< cutoff` 时返回 `true`；
/// 名称不符合日期格式（非日期目录）一律保留（返回 `false`），与 old「不符合年月格式则跳过」一致。
pub fn should_delete_dir(dir_name: &str, cutoff: NaiveDate) -> bool {
    match NaiveDate::parse_from_str(dir_name, "%Y-%m-%d") {
        Ok(date) => date < cutoff,
        Err(_) => false,
    }
}

/// 由目录名清单产出「将删除的候选」清单（纯函数，已排序、去格式不符项）。
///
/// 该函数**只计算计划**，从不删除任何东西——无论 dry-run 与否都先经此产出候选，
/// 故「预览」与「实删」共享完全一致的判定逻辑，杜绝两套脚本漂移（old 的隐患）。
pub fn plan_cleanup(dir_names: &[String], cutoff: NaiveDate) -> Vec<String> {
    let mut out: Vec<String> = dir_names
        .iter()
        .filter(|name| should_delete_dir(name, cutoff))
        .cloned()
        .collect();
    out.sort();
    out
}

// ───────────────────────────────────────────────────────────────────────────
// 文件系统接缝（trait）
// ───────────────────────────────────────────────────────────────────────────

/// 目录访问契约：列出某根目录下的直接子目录名 + 递归删除某子目录。
///
/// 把唯一的文件系统副作用收敛于此，使清理计划与编排逻辑可离线测试。
#[async_trait]
pub trait DirStore: Send + Sync {
    /// 列出 `root` 下的直接子目录名（仅名字，不含路径）。`root` 不存在时返回空。
    async fn list_subdirs(&self, root: &str) -> Result<Vec<String>, AppError>;

    /// 递归删除 `root` 下名为 `name` 的子目录。
    async fn delete_subdir(&self, root: &str, name: &str) -> Result<(), AppError>;
}

// ───────────────────────────────────────────────────────────────────────────
// 清理编排
// ───────────────────────────────────────────────────────────────────────────

/// 一次清理的结果汇总。
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct CleanupReport {
    /// 是否为 dry-run（预览）：`true` 时下列候选**未被删除**。
    pub dry_run: bool,
    /// 计划删除（dry-run）或已删除（实删）的目录名清单。
    pub candidates: Vec<String>,
    /// 实际成功删除的数量（dry-run 恒为 0）。
    pub deleted: usize,
}

/// 在 `root` 下执行清理：先用纯函数 [`plan_cleanup`] 算出候选，再按 `dry_run` 决定是否删除。
///
/// - `dry_run == true`：返回候选清单，`deleted = 0`，**不调用任何删除**（预览）。
/// - `dry_run == false`：逐个删除候选；单个目录删除失败只记日志、计入未删，不中断其余。
pub async fn run_cleanup(
    store: &dyn DirStore,
    root: &str,
    cutoff: NaiveDate,
    dry_run: bool,
) -> Result<CleanupReport, AppError> {
    let dirs = store.list_subdirs(root).await?;
    let candidates = plan_cleanup(&dirs, cutoff);

    if dry_run {
        tracing::info!(
            root,
            planned = candidates.len(),
            "cleanup_old_images：预览（dry-run），不删除"
        );
        return Ok(CleanupReport {
            dry_run: true,
            candidates,
            deleted: 0,
        });
    }

    let mut deleted = 0usize;
    for name in &candidates {
        match store.delete_subdir(root, name).await {
            Ok(()) => {
                deleted += 1;
                tracing::info!(root, dir = %name, "已删除过期主图目录");
            }
            Err(e) => {
                tracing::warn!(root, dir = %name, error = %e, "删除过期目录失败，跳过");
            }
        }
    }

    Ok(CleanupReport {
        dry_run: false,
        candidates,
        deleted,
    })
}

// ───────────────────────────────────────────────────────────────────────────
// 定时任务
// ───────────────────────────────────────────────────────────────────────────

/// `cleanup_old_images` 定时任务：每月清理早于保留期的主图日期目录。
///
/// 持有文件系统接缝 [`DirStore`] 与配置（保留月数、是否 dry-run、图片根目录）。
/// 该任务作用于文件系统而非租户库，因此 [`Job::run_for_tenant`] 忽略 `ctx.pool`，
/// 仅按配置对图片根目录执行清理（仍纳入调度器以复用锁门控与定时循环）。
pub struct CleanupOldImagesJob {
    store: std::sync::Arc<dyn DirStore>,
    keep_months: u32,
    dry_run: bool,
    root: String,
}

impl CleanupOldImagesJob {
    /// 构造实际删除模式的任务（保留默认 12 个月，根目录为 `images`）。
    pub fn new(store: std::sync::Arc<dyn DirStore>) -> Self {
        Self {
            store,
            keep_months: DEFAULT_KEEP_MONTHS,
            dry_run: false,
            root: IMAGE_ROOT.to_string(),
        }
    }

    /// 构造 dry-run（预览）模式的任务：只列出将删除的目录，绝不删除。
    pub fn preview(store: std::sync::Arc<dyn DirStore>) -> Self {
        Self {
            dry_run: true,
            ..Self::new(store)
        }
    }

    /// 覆盖保留月数（链式）。
    pub fn with_keep_months(mut self, keep_months: u32) -> Self {
        self.keep_months = keep_months;
        self
    }

    /// 覆盖图片根目录（链式）。
    pub fn with_root(mut self, root: impl Into<String>) -> Self {
        self.root = root.into();
        self
    }

    /// 立即执行一次清理（按配置 dry-run/实删），返回报告。
    pub async fn run(&self, today: NaiveDate) -> Result<CleanupReport, AppError> {
        let cutoff = cutoff_date(today, self.keep_months);
        run_cleanup(self.store.as_ref(), &self.root, cutoff, self.dry_run).await
    }
}

#[async_trait]
impl Job for CleanupOldImagesJob {
    fn name(&self) -> &'static str {
        "cleanup_old_images"
    }

    fn interval(&self) -> Duration {
        // 低频维护：每 30 天一次（old crontab 建议每月 1 日执行）。
        Duration::from_secs(30 * 24 * 60 * 60)
    }

    async fn run_for_tenant(&self, _ctx: &JobContext) -> Result<(), AppError> {
        let report = self.run(Utc::now().date_naive()).await?;
        tracing::info!(
            dry_run = report.dry_run,
            planned = report.candidates.len(),
            deleted = report.deleted,
            "cleanup_old_images：本轮完成"
        );
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::Arc;
    use std::sync::Mutex;

    fn d(y: i32, m: u32, day: u32) -> NaiveDate {
        NaiveDate::from_ymd_opt(y, m, day).unwrap()
    }

    // --- cutoff_date ---

    #[test]
    fn cutoff_subtracts_keep_months() {
        // 2024-06-15 保留 12 个月 → 截止 2023-06-15。
        assert_eq!(cutoff_date(d(2024, 6, 15), 12), d(2023, 6, 15));
    }

    #[test]
    fn cutoff_borrows_across_year() {
        // 2024-03-10 保留 6 个月 → 2023-09-10。
        assert_eq!(cutoff_date(d(2024, 3, 10), 6), d(2023, 9, 10));
    }

    #[test]
    fn cutoff_clamps_day_to_month_end() {
        // 2024-03-31 回退 1 个月 → 2 月没有 31 号，夹取到 2024-02-29（闰年）。
        assert_eq!(cutoff_date(d(2024, 3, 31), 1), d(2024, 2, 29));
    }

    // --- should_delete_dir ---

    #[test]
    fn deletes_only_dirs_before_cutoff() {
        let cutoff = d(2023, 6, 15);
        assert!(should_delete_dir("2023-06-14", cutoff), "早于截止日应删");
        assert!(!should_delete_dir("2023-06-15", cutoff), "等于截止日应保留");
        assert!(!should_delete_dir("2023-06-16", cutoff), "晚于截止日应保留");
        assert!(should_delete_dir("2020-01-01", cutoff));
    }

    #[test]
    fn non_date_dirs_are_never_deleted() {
        let cutoff = d(2023, 6, 15);
        assert!(!should_delete_dir("thumbnails", cutoff));
        assert!(
            !should_delete_dir("2401", cutoff),
            "old 风格 4 位名不再视为日期，保留"
        );
        assert!(
            !should_delete_dir("2023-13-01", cutoff),
            "非法月份解析失败，保留"
        );
        assert!(!should_delete_dir("", cutoff));
    }

    // --- plan_cleanup ---

    #[test]
    fn plan_lists_only_expired_sorted() {
        let cutoff = d(2023, 6, 15);
        let dirs = vec![
            "2023-06-20".to_string(),
            "2022-12-01".to_string(),
            "not-a-date".to_string(),
            "2023-01-09".to_string(),
        ];
        let plan = plan_cleanup(&dirs, cutoff);
        assert_eq!(
            plan,
            vec!["2022-12-01".to_string(), "2023-01-09".to_string()]
        );
    }

    #[test]
    fn plan_empty_when_nothing_expired() {
        let cutoff = d(2020, 1, 1);
        let dirs = vec!["2023-06-20".to_string(), "2024-01-01".to_string()];
        assert!(plan_cleanup(&dirs, cutoff).is_empty());
    }

    // --- DirStore 替身 + run_cleanup（dry-run 关键性质） ---

    /// 替身目录存储：固定子目录清单，记录删除调用。
    struct FakeDirStore {
        dirs: Vec<String>,
        deleted: Mutex<Vec<String>>,
    }

    impl FakeDirStore {
        fn new(dirs: &[&str]) -> Self {
            Self {
                dirs: dirs.iter().map(|s| s.to_string()).collect(),
                deleted: Mutex::new(Vec::new()),
            }
        }
    }

    #[async_trait]
    impl DirStore for FakeDirStore {
        async fn list_subdirs(&self, _root: &str) -> Result<Vec<String>, AppError> {
            Ok(self.dirs.clone())
        }
        async fn delete_subdir(&self, _root: &str, name: &str) -> Result<(), AppError> {
            self.deleted.lock().unwrap().push(name.to_string());
            Ok(())
        }
    }

    /// dry-run：返回候选清单，但**绝不**调用删除。
    #[tokio::test]
    async fn dry_run_returns_candidates_without_deleting() {
        let store = FakeDirStore::new(&["2020-01-01", "2024-01-01", "2019-12-31"]);
        let cutoff = d(2023, 6, 15);

        let report = run_cleanup(&store, "images", cutoff, true).await.unwrap();

        assert!(report.dry_run);
        assert_eq!(
            report.candidates,
            vec!["2019-12-31".to_string(), "2020-01-01".to_string()]
        );
        assert_eq!(report.deleted, 0, "dry-run 不应删除任何目录");
        assert!(
            store.deleted.lock().unwrap().is_empty(),
            "dry-run 不应调用 delete_subdir"
        );
    }

    /// 实删：删除全部候选，删除集合与候选一致。
    #[tokio::test]
    async fn real_run_deletes_all_candidates() {
        let store = FakeDirStore::new(&["2020-01-01", "2024-01-01", "2019-12-31"]);
        let cutoff = d(2023, 6, 15);

        let report = run_cleanup(&store, "images", cutoff, false).await.unwrap();

        assert!(!report.dry_run);
        assert_eq!(report.deleted, 2);
        let mut deleted = store.deleted.lock().unwrap().clone();
        deleted.sort();
        assert_eq!(
            deleted,
            vec!["2019-12-31".to_string(), "2020-01-01".to_string()]
        );
    }

    #[tokio::test]
    async fn job_preview_does_not_delete() {
        let store = Arc::new(FakeDirStore::new(&["2000-01-01"]));
        let job = CleanupOldImagesJob::preview(store.clone()).with_keep_months(12);
        let report = job.run(d(2024, 1, 1)).await.unwrap();
        assert!(report.dry_run);
        assert_eq!(report.deleted, 0);
        assert!(store.deleted.lock().unwrap().is_empty());
    }

    #[test]
    fn job_metadata_is_stable() {
        let store = Arc::new(FakeDirStore::new(&[]));
        let job = CleanupOldImagesJob::new(store);
        assert_eq!(job.name(), "cleanup_old_images");
        assert_eq!(job.interval(), Duration::from_secs(30 * 86_400));
    }
}
