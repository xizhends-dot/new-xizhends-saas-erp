//! 定时任务：主图下载（按日期目录组织）（Task 14.8 / Requirements 11.1）。
//!
//! 语义对应 old 系统 `cron/zhutu_downloader.php`：扫描近 `day_limit` 天内尚无主图的订单
//! 商品，抓取平台主图、按日期目录存盘，并把相对路径写回。与 old 的差异：
//! - old 在 6 张镜像宽表 `ph_order{y,r,w,m,q,yp}` 上逐平台抓取，存盘目录为 `down/{ym}/...`；
//!   本实现对规范化后的 `order_items`（`main_image` 为空者）统一处理，存盘目录改为
//!   **按日期** `images/{YYYY-MM-DD}/...`（Task 14.8 指定的日期目录形态）。
//! - old 把网络抓取（curl）与文件系统写入直接内联；本实现把这两处**唯一的副作用**收敛到
//!   [`ImageFetcher`]（抓取字节）与 [`ImageStore`]（写盘）两个 trait 之后，使任务逻辑
//!   （选目标、拼路径、写回 DB）可在**无网络、无文件系统**的前提下离线测试。
//!
//! ## 纯函数接缝
//! 目录与文件路径的拼接是纯函数（[`date_dir`] / [`build_image_path`]），不触达任何 IO，
//! 单元测试可直接断言「日期目录形如 `images/2024-01-05`」「最终路径含日期段与扩展名」。

use std::time::Duration;

use async_trait::async_trait;
use chrono::{NaiveDate, Utc};
use sqlx::{FromRow, MySqlPool};

use crate::error::AppError;
use crate::jobs::scheduler::{Job, JobContext};

/// 只处理近 N 天内导入的订单（对应 old `$day_limit = 3`）。
pub const DEFAULT_DAY_LIMIT: i64 = 3;

/// 每个租户每轮最多处理的记录数（对应 old `$count_limit = 20`）。
pub const DEFAULT_COUNT_LIMIT: i64 = 20;

/// 主图根目录（相对租户图片空间）。最终路径为 `images/{YYYY-MM-DD}/{stem}.{ext}`。
pub const IMAGE_ROOT: &str = "images";

// ───────────────────────────────────────────────────────────────────────────
// 纯函数辅助（无 IO，便于单元测试）
// ───────────────────────────────────────────────────────────────────────────

/// 由日期构造日期目录段：`images/{YYYY-MM-DD}`（纯函数）。
///
/// 与 old 的 `down/{ym}` 不同，新实现采用更易读、可排序的完整日期目录，便于
/// [`crate::jobs::cleanup_old_images`] 按日期阈值清理。
pub fn date_dir(date: NaiveDate) -> String {
    format!("{IMAGE_ROOT}/{}", date.format("%Y-%m-%d"))
}

/// 构造主图存盘相对路径：`images/{YYYY-MM-DD}/{stem}.{ext}`（纯函数）。
///
/// `stem` 为去除扩展名的文件主名（调用方保证其唯一性，见 [`image_stem`]），
/// `ext` 为不含点的扩展名（如 `jpg`）。
pub fn build_image_path(date: NaiveDate, stem: &str, ext: &str) -> String {
    format!("{}/{stem}.{ext}", date_dir(date))
}

/// 由子商品 id 与序号构造唯一文件主名（纯函数）：`{item_id}-{seq}`。
///
/// 同一轮内 `seq` 递增即可保证唯一；跨轮因日期目录 + item_id 已天然区分。
pub fn image_stem(order_item_id: i64, seq: usize) -> String {
    format!("{order_item_id}-{seq}")
}

// ───────────────────────────────────────────────────────────────────────────
// 副作用接缝（trait）：抓取 + 写盘
// ───────────────────────────────────────────────────────────────────────────

/// 待下载主图的候选子商品（从 `order_items` 选出，`main_image` 为空）。
#[derive(Debug, Clone, FromRow, PartialEq, Eq)]
pub struct ImageCandidate {
    /// 子商品主键。
    pub id: i64,
    /// 所属平台（来自 `orders.platform`，决定抓取来源）。
    pub platform: String,
    /// 商品编码（ItemId/itemCode/lotnumber 归一）。
    pub item_code: String,
}

/// 抓取结果：图片字节 + 扩展名（不含点）。
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct FetchedImage {
    /// 图片二进制内容。
    pub bytes: Vec<u8>,
    /// 文件扩展名（如 `jpg` / `png`）。
    pub ext: String,
}

/// 主图抓取契约：封装「按平台构造图片地址 + 网络下载」的全部副作用。
///
/// 返回 `Ok(None)` 表示该商品当前无可用主图（如平台页缺图），调用方应跳过而非报错，
/// 与 old 「下载出错则 continue」的容错语义一致。
#[async_trait]
pub trait ImageFetcher: Send + Sync {
    /// 抓取候选商品的主图字节。
    async fn fetch(&self, candidate: &ImageCandidate) -> Result<Option<FetchedImage>, AppError>;
}

/// 主图写盘契约：把字节写入相对路径（实现负责按需建目录）。
#[async_trait]
pub trait ImageStore: Send + Sync {
    /// 将 `bytes` 写入相对路径 `relative_path`。
    async fn save(&self, relative_path: &str, bytes: &[u8]) -> Result<(), AppError>;
}

// ───────────────────────────────────────────────────────────────────────────
// DB 访问
// ───────────────────────────────────────────────────────────────────────────

/// 选出近 `day_limit` 天内、尚无主图的子商品（最多 `count_limit` 条）。
///
/// 对应 old `WHERE (zhutu='' OR zhutu IS NULL) AND cdate>DATE_SUB(NOW(), INTERVAL n DAY)`，
/// 这里映射为 `order_items.main_image=''` 且其订单 `orders.imported_at` 在窗口内。
async fn select_candidates(
    pool: &MySqlPool,
    day_limit: i64,
    count_limit: i64,
) -> Result<Vec<ImageCandidate>, AppError> {
    let rows = sqlx::query_as::<_, ImageCandidate>(
        "SELECT oi.id AS id, o.platform AS platform, oi.item_code AS item_code \
         FROM `order_items` oi \
         JOIN `orders` o ON o.id = oi.order_id \
         WHERE oi.main_image = '' \
           AND oi.item_code <> '' \
           AND o.imported_at > DATE_SUB(NOW(), INTERVAL ? DAY) \
         ORDER BY oi.id DESC \
         LIMIT ?",
    )
    .bind(day_limit)
    .bind(count_limit)
    .fetch_all(pool)
    .await?;
    Ok(rows)
}

/// 把下载好的主图相对路径写回 `order_items.main_image`。
async fn update_main_image(
    pool: &MySqlPool,
    order_item_id: i64,
    relative_path: &str,
) -> Result<(), AppError> {
    sqlx::query("UPDATE `order_items` SET `main_image` = ? WHERE `id` = ?")
        .bind(relative_path)
        .bind(order_item_id)
        .execute(pool)
        .await?;
    Ok(())
}

// ───────────────────────────────────────────────────────────────────────────
// 定时任务
// ───────────────────────────────────────────────────────────────────────────

/// `zhutu_downloader` 定时任务：抓取缺失主图，按日期目录存盘并写回路径。
///
/// 持有抓取/写盘两个副作用接缝（trait 对象），便于生产注入真实实现、测试注入替身。
pub struct ZhutuDownloaderJob {
    fetcher: std::sync::Arc<dyn ImageFetcher>,
    store: std::sync::Arc<dyn ImageStore>,
    day_limit: i64,
    count_limit: i64,
}

impl ZhutuDownloaderJob {
    /// 用抓取器与存储器构造（采用默认的天数/数量上限）。
    pub fn new(
        fetcher: std::sync::Arc<dyn ImageFetcher>,
        store: std::sync::Arc<dyn ImageStore>,
    ) -> Self {
        Self {
            fetcher,
            store,
            day_limit: DEFAULT_DAY_LIMIT,
            count_limit: DEFAULT_COUNT_LIMIT,
        }
    }

    /// 覆盖天数/数量上限（链式）。
    pub fn with_limits(mut self, day_limit: i64, count_limit: i64) -> Self {
        self.day_limit = day_limit;
        self.count_limit = count_limit;
        self
    }

    /// 对单个租户库执行一轮主图下载，返回成功写回的记录数。
    async fn run_once(&self, pool: &MySqlPool, today: NaiveDate) -> Result<usize, AppError> {
        let candidates = select_candidates(pool, self.day_limit, self.count_limit).await?;
        let mut saved = 0usize;

        for (seq, candidate) in candidates.iter().enumerate() {
            // 抓取失败（网络/解析异常）只隔离该条，继续其余（与 old 容错一致）。
            let fetched = match self.fetcher.fetch(candidate).await {
                Ok(Some(img)) => img,
                Ok(None) => {
                    tracing::debug!(item_id = candidate.id, "无可用主图，跳过");
                    continue;
                }
                Err(e) => {
                    tracing::warn!(item_id = candidate.id, error = %e, "主图抓取失败，跳过");
                    continue;
                }
            };

            let stem = image_stem(candidate.id, seq);
            let path = build_image_path(today, &stem, &fetched.ext);

            if let Err(e) = self.store.save(&path, &fetched.bytes).await {
                tracing::warn!(item_id = candidate.id, error = %e, "主图写盘失败，跳过");
                continue;
            }

            update_main_image(pool, candidate.id, &path).await?;
            saved += 1;
            tracing::info!(item_id = candidate.id, path = %path, "主图下载完成");
        }

        Ok(saved)
    }
}

#[async_trait]
impl Job for ZhutuDownloaderJob {
    fn name(&self) -> &'static str {
        "zhutu_downloader"
    }

    fn interval(&self) -> Duration {
        // old 建议「任务间隔 ≥ 3 分钟」，这里取 5 分钟，避免抓取过密。
        Duration::from_secs(5 * 60)
    }

    async fn run_for_tenant(&self, ctx: &JobContext) -> Result<(), AppError> {
        let today = Utc::now().date_naive();
        let saved = self.run_once(&ctx.pool, today).await?;
        tracing::info!(saved, "zhutu_downloader：本轮完成");
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn d(y: i32, m: u32, day: u32) -> NaiveDate {
        NaiveDate::from_ymd_opt(y, m, day).unwrap()
    }

    #[test]
    fn date_dir_uses_full_iso_date_under_images_root() {
        assert_eq!(date_dir(d(2024, 1, 5)), "images/2024-01-05");
        assert_eq!(date_dir(d(2024, 12, 31)), "images/2024-12-31");
    }

    #[test]
    fn build_image_path_contains_date_stem_and_ext() {
        let path = build_image_path(d(2024, 1, 5), "42-0", "jpg");
        assert_eq!(path, "images/2024-01-05/42-0.jpg");
    }

    #[test]
    fn build_image_path_respects_extension() {
        assert!(build_image_path(d(2024, 6, 9), "7-3", "png").ends_with(".png"));
        assert!(build_image_path(d(2024, 6, 9), "7-3", "gif").ends_with(".gif"));
    }

    #[test]
    fn image_stem_is_unique_per_item_and_seq() {
        assert_eq!(image_stem(42, 0), "42-0");
        assert_eq!(image_stem(42, 1), "42-1");
        assert_ne!(image_stem(42, 0), image_stem(42, 1));
        assert_ne!(image_stem(42, 0), image_stem(43, 0));
    }

    /// 整轮路径稳定：同一日期、同一 (item,seq) 总产生相同路径（幂等可重放）。
    #[test]
    fn path_building_is_deterministic() {
        let p1 = build_image_path(d(2024, 3, 1), &image_stem(100, 2), "jpg");
        let p2 = build_image_path(d(2024, 3, 1), &image_stem(100, 2), "jpg");
        assert_eq!(p1, p2);
    }

    #[test]
    fn default_limits_match_legacy() {
        assert_eq!(DEFAULT_DAY_LIMIT, 3);
        assert_eq!(DEFAULT_COUNT_LIMIT, 20);
    }

    // --- 离线行为测试：用替身 fetcher/store 验证任务逻辑（无网络/无文件系统） ---

    use std::sync::Arc;
    use std::sync::Mutex;

    /// 替身抓取器：按 item_code 是否为 "missing" 返回 None，否则返回固定字节。
    struct FakeFetcher;

    #[async_trait]
    impl ImageFetcher for FakeFetcher {
        async fn fetch(
            &self,
            candidate: &ImageCandidate,
        ) -> Result<Option<FetchedImage>, AppError> {
            if candidate.item_code == "missing" {
                Ok(None)
            } else {
                Ok(Some(FetchedImage {
                    bytes: vec![0xFF, 0xD8, 0xFF],
                    ext: "jpg".to_string(),
                }))
            }
        }
    }

    /// 替身存储器：记录所有写入的相对路径（不触碰真实文件系统）。
    #[derive(Default)]
    struct RecordingStore {
        saved: Mutex<Vec<String>>,
    }

    #[async_trait]
    impl ImageStore for RecordingStore {
        async fn save(&self, relative_path: &str, _bytes: &[u8]) -> Result<(), AppError> {
            self.saved.lock().unwrap().push(relative_path.to_string());
            Ok(())
        }
    }

    /// 抓取到 None 的候选不应写盘；正常候选应写入按日期目录组织的路径。
    #[tokio::test]
    async fn fetcher_none_is_skipped_and_others_saved() {
        let store = Arc::new(RecordingStore::default());
        let fetcher = Arc::new(FakeFetcher);
        let job = ZhutuDownloaderJob::new(fetcher, store.clone());

        // 直接驱动副作用接缝（不依赖 DB）：模拟 run_once 的「逐候选抓取+写盘」核心。
        let today = d(2024, 1, 5);
        let candidates = vec![
            ImageCandidate {
                id: 10,
                platform: "y".into(),
                item_code: "abc".into(),
            },
            ImageCandidate {
                id: 11,
                platform: "y".into(),
                item_code: "missing".into(),
            },
            ImageCandidate {
                id: 12,
                platform: "r".into(),
                item_code: "xyz".into(),
            },
        ];

        for (seq, c) in candidates.iter().enumerate() {
            if let Some(img) = job.fetcher.fetch(c).await.unwrap() {
                let path = build_image_path(today, &image_stem(c.id, seq), &img.ext);
                job.store.save(&path, &img.bytes).await.unwrap();
            }
        }

        let saved = store.saved.lock().unwrap();
        assert_eq!(saved.len(), 2, "缺图候选应被跳过，仅 2 条写盘");
        assert_eq!(saved[0], "images/2024-01-05/10-0.jpg");
        assert_eq!(saved[1], "images/2024-01-05/12-2.jpg");
    }

    #[test]
    fn job_name_is_stable() {
        let job =
            ZhutuDownloaderJob::new(Arc::new(FakeFetcher), Arc::new(RecordingStore::default()));
        assert_eq!(job.name(), "zhutu_downloader");
        assert_eq!(job.interval(), Duration::from_secs(300));
    }
}
