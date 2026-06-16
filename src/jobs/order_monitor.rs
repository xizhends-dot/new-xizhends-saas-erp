//! 定时任务：`order_monitor`（按平台编码字段从历史同编码订单回填扩展信息，进度游标）
//! —— Task 14.5 / Requirements 11.3。
//!
//! 语义对应 old 系统 `cron/order_monitor.php`「订单扩展信息自动填充」：扫描订单，
//! 对缺失 `caigoulink` / `material` / `tranship_comment` 的记录，从**同一平台、同一商品编码**
//! 的历史订单回填这三项，并用「进度游标文件」记录上次处理到的位置以便断点续跑。
//!
//! ## 新旧模型差异
//! old 系统每个平台一张镜像宽表 `ph_order{tag}`，编码字段名因平台而异
//! （`y/r`=`ItemId`、`w/q`=`itemCode`、`m/yp`=`lotnumber`），三项扩展信息都在同一行。
//! 新模型规范化后：
//! - **商品编码**已在导入/迁移期按平台归一写入 [`order_items.item_code`]，运行期直接使用，
//!   无需再按平台取不同列名（平台↔编码字段映射由 [`Platform::item_code_field`] 固化，
//!   本模块保留一致性测试与 [`item_code_field_for`] 包装，确保与 Requirements 11.3 对齐）。
//! - `caigoulink` → `purchases.caigou_link`（按 `order_item_id`）。
//! - `material`   → `order_items.material`。
//! - `tranship_comment` → `intl_shipments.tranship_comment`（按 `order_item_id`）。
//!
//! 「历史同编码」匹配限定**同平台**（`orders.platform` 相同），以还原 old 系统按平台分表的
//! 天然隔离，避免不同平台间编码值偶然碰撞造成误回填。
//!
//! ## 进度游标
//! 以「上次成功处理的 `order_items.id`」为游标，按 id 升序、`id > cursor` 取下一批；处理完
//! 推进游标。游标持久化抽象为 [`CursorStore`]：测试用 [`InMemoryCursorStore`]，生产用
//! [`FileCursorStore`]（每租户一个文件，文件名见 [`cursor_file_name`]）。游标推进逻辑
//! [`advance_cursor`] 为纯函数，便于单测。
//!
//! ## 实现约束
//! 与项目其余 DB 访问一致：一律使用 SQLx **运行时** API（`query` / `query_as` /
//! `query_scalar`），不使用编译期 `query!` 宏——租户库在编译期并不存在。

use std::io;
use std::path::{Path, PathBuf};
use std::sync::Mutex;
use std::time::Duration;

use sqlx::MySqlPool;

use crate::db::pool::TenantId;
use crate::error::AppError;
use crate::jobs::scheduler::{Job, JobContext};
use crate::models::platform::Platform;

/// 单批处理的子商品数量（与 old 系统 `sql_result_limit = 100` 一致）。
pub const BATCH_SIZE: i64 = 100;

// ───────────────────────────────────────────────────────────────────────────
// 纯函数辅助（无 IO，便于单元测试）
// ───────────────────────────────────────────────────────────────────────────

/// 平台 → 商品编码字段名的包装（委托 [`Platform::item_code_field`]）。
///
/// 新模型运行期直接用归一后的 `order_items.item_code`，此函数仅用于与 Requirements 11.3
/// 的映射保持显式一致（`y/r`=`ItemId`、`w/q`=`itemCode`、`m/yp`=`lotnumber`）与测试断言。
pub fn item_code_field_for(platform: Platform) -> &'static str {
    platform.item_code_field()
}

/// 单个租户的游标文件名：`order_monitor_{tenant_id}_last`。
///
/// `tenant_id` 为整数，产出文件名不含用户可控文本，拼接安全。一租户一文件，互不干扰。
pub fn cursor_file_name(tenant_id: TenantId) -> String {
    format!("order_monitor_{tenant_id}_last")
}

/// 游标推进：取「当前游标」与「本轮已处理 id」的最大值。
///
/// 本轮按 id 升序处理，故新游标即 `max(current, max(processed))`；空批次保持原值不变。
/// 纯函数，便于单测。
pub fn advance_cursor(current: i64, processed_ids: &[i64]) -> i64 {
    processed_ids.iter().copied().fold(current, i64::max)
}

/// 从一段文本中按顺序抽取所有 `http://` / `https://` URL（以空白为界）。
///
/// 还原 old 系统正则 `http[s]?://[...]+` 的抽取行为：先把换行规整为空格，再逐个截取。
pub fn extract_urls(text: &str) -> Vec<String> {
    let normalized = text.replace("\r\n", " ").replace(['\n', '\r'], " ");
    let mut out = Vec::new();
    let mut rest = normalized.as_str();
    loop {
        let next = match (rest.find("https://"), rest.find("http://")) {
            (None, None) => break,
            (Some(a), None) => a,
            (None, Some(b)) => b,
            (Some(a), Some(b)) => a.min(b),
        };
        let tail = &rest[next..];
        let end = tail.find(char::is_whitespace).unwrap_or(tail.len());
        out.push(tail[..end].to_string());
        rest = &tail[end..];
    }
    out
}

/// 从历史 `caigou_link` 文本中提炼可用采购链接（多个以 `\r\n` 连接）。
///
/// 还原 old 系统判定：含「缺货」者跳过；否则取其中所有 URL 拼接。无 URL 返回 `None`。
pub fn extract_caigou_links(raw: &str) -> Option<String> {
    if raw.contains("缺货") {
        return None;
    }
    let urls = extract_urls(raw);
    if urls.is_empty() {
        None
    } else {
        Some(urls.join("\r\n"))
    }
}

/// 历史采购链接是否可用作回填值（含 URL 且不含「缺货」）。
pub fn is_valid_caigou_link(raw: &str) -> bool {
    extract_caigou_links(raw).is_some()
}

// ───────────────────────────────────────────────────────────────────────────
// 游标持久化抽象（CursorStore）
// ───────────────────────────────────────────────────────────────────────────

/// 进度游标持久化契约：`load` 读取上次处理位置，`save` 写入最新位置。
///
/// 用 `io::Result` 而非 `AppError`，让游标存储与 HTTP 错误层解耦；调用方（任务）
/// 自行决定如何容错（读失败可从 0 起跑、写失败仅告警——回填本身幂等，重复处理无害）。
pub trait CursorStore: Send + Sync {
    /// 读取游标；不存在/读失败由实现决定语义（文件实现：不存在视为 0）。
    fn load(&self) -> io::Result<i64>;
    /// 写入游标。
    fn save(&self, cursor: i64) -> io::Result<()>;
}

/// 内存游标存储：用于测试与同进程临时场景。
#[derive(Debug, Default)]
pub struct InMemoryCursorStore {
    value: Mutex<i64>,
}

impl InMemoryCursorStore {
    /// 以初始游标值构造。
    pub fn new(initial: i64) -> Self {
        Self {
            value: Mutex::new(initial),
        }
    }
}

impl CursorStore for InMemoryCursorStore {
    fn load(&self) -> io::Result<i64> {
        Ok(*self.value.lock().unwrap())
    }
    fn save(&self, cursor: i64) -> io::Result<()> {
        *self.value.lock().unwrap() = cursor;
        Ok(())
    }
}

/// 文件游标存储：把游标写入一个小文件（与 old 系统 `*_last` 文件等价）。
///
/// 读取时：文件不存在 → 视为 `0`（首次运行）；内容非法 → 视为 `0`（与 old `intval` 容错一致）。
#[derive(Debug, Clone)]
pub struct FileCursorStore {
    path: PathBuf,
}

impl FileCursorStore {
    /// 以游标文件路径构造。
    pub fn new(path: impl Into<PathBuf>) -> Self {
        Self { path: path.into() }
    }

    /// 游标文件路径访问（便于日志/测试）。
    pub fn path(&self) -> &Path {
        &self.path
    }
}

impl CursorStore for FileCursorStore {
    fn load(&self) -> io::Result<i64> {
        match std::fs::read_to_string(&self.path) {
            Ok(s) => Ok(s.trim().parse::<i64>().unwrap_or(0)),
            // 文件不存在视为首次运行（游标 0），不算错误。
            Err(e) if e.kind() == io::ErrorKind::NotFound => Ok(0),
            Err(e) => Err(e),
        }
    }

    fn save(&self, cursor: i64) -> io::Result<()> {
        if let Some(dir) = self.path.parent() {
            if !dir.as_os_str().is_empty() {
                std::fs::create_dir_all(dir)?;
            }
        }
        std::fs::write(&self.path, cursor.to_string())
    }
}

// ───────────────────────────────────────────────────────────────────────────
// 回填核心（DB 访问）
// ───────────────────────────────────────────────────────────────────────────

/// 待回填的子商品快照（仅取回填所需字段）。
#[derive(Debug, Clone)]
struct ItemRow {
    id: i64,
    item_code: String,
    material: String,
    platform: String,
}

/// 取下一批待处理子商品：`id > cursor` 且 `item_code` 非空，按 id 升序。
async fn fetch_batch(pool: &MySqlPool, cursor: i64, limit: i64) -> Result<Vec<ItemRow>, AppError> {
    let rows: Vec<(i64, String, String, String)> = sqlx::query_as(
        "SELECT oi.`id`, oi.`item_code`, oi.`material`, o.`platform` \
         FROM `order_items` oi \
         INNER JOIN `orders` o ON o.`id` = oi.`order_id` \
         WHERE oi.`id` > ? AND oi.`item_code` <> '' \
         ORDER BY oi.`id` ASC \
         LIMIT ?",
    )
    .bind(cursor)
    .bind(limit)
    .fetch_all(pool)
    .await?;

    Ok(rows
        .into_iter()
        .map(|(id, item_code, material, platform)| ItemRow {
            id,
            item_code,
            material,
            platform,
        })
        .collect())
}

/// 回填单个子商品的三项扩展信息（缺失才回填）。
async fn backfill_item(pool: &MySqlPool, item: &ItemRow) -> Result<(), AppError> {
    backfill_material(pool, item).await?;
    backfill_caigou_link(pool, item).await?;
    backfill_tranship_comment(pool, item).await?;
    Ok(())
}

/// 回填材质：`order_items.material` 为空时，从同平台同编码历史子商品取最近的非空材质。
async fn backfill_material(pool: &MySqlPool, item: &ItemRow) -> Result<(), AppError> {
    if !item.material.trim().is_empty() {
        return Ok(());
    }
    let hist: Option<String> = sqlx::query_scalar(
        "SELECT oi2.`material` \
         FROM `order_items` oi2 \
         INNER JOIN `orders` o2 ON o2.`id` = oi2.`order_id` \
         WHERE oi2.`id` < ? AND oi2.`item_code` = ? AND o2.`platform` = ? \
           AND LENGTH(TRIM(oi2.`material`)) > 0 \
         ORDER BY oi2.`id` DESC \
         LIMIT 1",
    )
    .bind(item.id)
    .bind(&item.item_code)
    .bind(&item.platform)
    .fetch_optional(pool)
    .await?;

    if let Some(material) = hist {
        let material = material.trim();
        if !material.is_empty() {
            sqlx::query("UPDATE `order_items` SET `material` = ? WHERE `id` = ?")
                .bind(material)
                .bind(item.id)
                .execute(pool)
                .await?;
            tracing::info!(item_id = item.id, "order_monitor：回填材质");
        }
    }
    Ok(())
}

/// 回填采购链接：当前子商品无有效 `purchases.caigou_link` 时，从同平台同编码历史采购记录回填。
///
/// 当前子商品已有 `purchases` 行 → 更新其 `caigou_link`；无行则新建一行承载链接。
async fn backfill_caigou_link(pool: &MySqlPool, item: &ItemRow) -> Result<(), AppError> {
    let existing: Option<(i64, String)> = sqlx::query_as(
        "SELECT `id`, `caigou_link` FROM `purchases` WHERE `order_item_id` = ? ORDER BY `id` ASC LIMIT 1",
    )
    .bind(item.id)
    .fetch_optional(pool)
    .await?;

    let needs = match &existing {
        Some((_, link)) => link.trim().is_empty(),
        None => true,
    };
    if !needs {
        return Ok(());
    }

    // 取最近若干条候选（已过滤非空），在内存中提炼首个含 URL 且不含「缺货」者。
    let candidates: Vec<String> = sqlx::query_scalar(
        "SELECT p.`caigou_link` \
         FROM `purchases` p \
         INNER JOIN `order_items` oi2 ON oi2.`id` = p.`order_item_id` \
         INNER JOIN `orders` o2 ON o2.`id` = oi2.`order_id` \
         WHERE oi2.`id` < ? AND oi2.`item_code` = ? AND o2.`platform` = ? \
           AND LENGTH(TRIM(p.`caigou_link`)) > 0 \
         ORDER BY oi2.`id` DESC \
         LIMIT 5",
    )
    .bind(item.id)
    .bind(&item.item_code)
    .bind(&item.platform)
    .fetch_all(pool)
    .await?;

    let chosen = candidates.iter().find_map(|c| extract_caigou_links(c));
    let Some(link) = chosen else {
        return Ok(());
    };

    match existing {
        Some((pid, _)) => {
            sqlx::query("UPDATE `purchases` SET `caigou_link` = ? WHERE `id` = ?")
                .bind(&link)
                .bind(pid)
                .execute(pool)
                .await?;
        }
        None => {
            sqlx::query("INSERT INTO `purchases` (`order_item_id`, `caigou_link`) VALUES (?, ?)")
                .bind(item.id)
                .bind(&link)
                .execute(pool)
                .await?;
        }
    }
    tracing::info!(item_id = item.id, "order_monitor：回填采购链接");
    Ok(())
}

/// 回填货运备注：当前子商品无有效 `intl_shipments.tranship_comment` 时，从同平台同编码历史回填。
///
/// 当前子商品已有 `intl_shipments` 行 → 更新其 `tranship_comment`；无行则新建一行承载备注。
async fn backfill_tranship_comment(pool: &MySqlPool, item: &ItemRow) -> Result<(), AppError> {
    let existing: Option<(i64, String)> = sqlx::query_as(
        "SELECT `id`, `tranship_comment` FROM `intl_shipments` WHERE `order_item_id` = ? ORDER BY `id` ASC LIMIT 1",
    )
    .bind(item.id)
    .fetch_optional(pool)
    .await?;

    let needs = match &existing {
        Some((_, c)) => c.trim().is_empty(),
        None => true,
    };
    if !needs {
        return Ok(());
    }

    let hist: Option<String> = sqlx::query_scalar(
        "SELECT s.`tranship_comment` \
         FROM `intl_shipments` s \
         INNER JOIN `order_items` oi2 ON oi2.`id` = s.`order_item_id` \
         INNER JOIN `orders` o2 ON o2.`id` = oi2.`order_id` \
         WHERE oi2.`id` < ? AND oi2.`item_code` = ? AND o2.`platform` = ? \
           AND LENGTH(TRIM(s.`tranship_comment`)) > 0 \
         ORDER BY oi2.`id` DESC \
         LIMIT 1",
    )
    .bind(item.id)
    .bind(&item.item_code)
    .bind(&item.platform)
    .fetch_optional(pool)
    .await?;

    let Some(comment) = hist else {
        return Ok(());
    };
    let comment = comment.trim();
    if comment.is_empty() {
        return Ok(());
    }

    match existing {
        Some((sid, _)) => {
            sqlx::query("UPDATE `intl_shipments` SET `tranship_comment` = ? WHERE `id` = ?")
                .bind(comment)
                .bind(sid)
                .execute(pool)
                .await?;
        }
        None => {
            sqlx::query(
                "INSERT INTO `intl_shipments` (`order_item_id`, `tranship_comment`) VALUES (?, ?)",
            )
            .bind(item.id)
            .bind(comment)
            .execute(pool)
            .await?;
        }
    }
    tracing::info!(item_id = item.id, "order_monitor：回填货运备注");
    Ok(())
}

/// 对单个租户库执行一轮回填：从游标处取一批子商品逐条回填，再推进并保存游标。
///
/// 失败隔离：某条回填报错时，先保存已成功处理到的游标，再向上抛错（由调度器隔离该租户）。
/// 回填幂等，故即便游标未推进，下轮重跑也不会产生副作用。
async fn backfill_once(
    pool: &MySqlPool,
    store: &dyn CursorStore,
    batch_size: i64,
) -> Result<(), AppError> {
    let start = store.load().unwrap_or_else(|e| {
        tracing::warn!(error = %e, "order_monitor：读取游标失败，从 0 起跑");
        0
    });

    let items = fetch_batch(pool, start, batch_size).await?;

    let mut processed: Vec<i64> = Vec::with_capacity(items.len());
    let mut run_err: Option<AppError> = None;
    for item in &items {
        match backfill_item(pool, item).await {
            Ok(()) => processed.push(item.id),
            Err(e) => {
                run_err = Some(e);
                break;
            }
        }
    }

    let next = advance_cursor(start, &processed);
    if next != start {
        if let Err(e) = store.save(next) {
            tracing::warn!(error = %e, cursor = next, "order_monitor：保存游标失败");
        }
    }

    match run_err {
        Some(e) => Err(e),
        None => Ok(()),
    }
}

// ───────────────────────────────────────────────────────────────────────────
// 定时任务
// ───────────────────────────────────────────────────────────────────────────

/// `order_monitor` 定时任务：按平台编码（已归一）从历史同编码订单回填扩展信息。
///
/// 每个租户库独立执行（由调度器循环驱动），各租户游标文件落在 `cursor_dir` 下，互不干扰。
#[derive(Debug, Clone)]
pub struct OrderMonitorJob {
    cursor_dir: PathBuf,
}

impl OrderMonitorJob {
    /// 以游标文件所在目录构造。
    pub fn new(cursor_dir: impl Into<PathBuf>) -> Self {
        Self {
            cursor_dir: cursor_dir.into(),
        }
    }

    /// 计算某租户的游标文件路径（`cursor_dir/order_monitor_{tenant_id}_last`）。
    pub fn cursor_path(&self, tenant_id: TenantId) -> PathBuf {
        self.cursor_dir.join(cursor_file_name(tenant_id))
    }
}

#[async_trait::async_trait]
impl Job for OrderMonitorJob {
    fn name(&self) -> &'static str {
        "order_monitor"
    }

    fn interval(&self) -> Duration {
        // 扩展信息回填为高频维护任务，10 分钟一轮（每轮处理一批）。
        Duration::from_secs(10 * 60)
    }

    async fn run_for_tenant(&self, ctx: &JobContext) -> Result<(), AppError> {
        let store = FileCursorStore::new(self.cursor_path(ctx.tenant_id));
        backfill_once(&ctx.pool, &store, BATCH_SIZE).await
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    // ── 平台 ↔ 编码字段一致性（Requirements 11.3）────────────────────────────

    #[test]
    fn item_code_field_matches_requirement_11_3() {
        assert_eq!(item_code_field_for(Platform::Yahoo), "ItemId");
        assert_eq!(item_code_field_for(Platform::Rakuten), "ItemId");
        assert_eq!(item_code_field_for(Platform::Wowma), "itemCode");
        assert_eq!(item_code_field_for(Platform::Qoo10), "itemCode");
        assert_eq!(item_code_field_for(Platform::Mercari), "lotnumber");
        assert_eq!(item_code_field_for(Platform::YahooAuction), "lotnumber");
    }

    #[test]
    fn item_code_field_wrapper_delegates_to_platform() {
        for p in Platform::ALL {
            assert_eq!(item_code_field_for(p), p.item_code_field());
        }
    }

    // ── 游标文件名 ────────────────────────────────────────────────────────────

    #[test]
    fn cursor_file_name_embeds_tenant_id() {
        assert_eq!(cursor_file_name(7), "order_monitor_7_last");
        assert_eq!(cursor_file_name(1), "order_monitor_1_last");
        // 不同租户产出不同文件名，互不干扰。
        assert_ne!(cursor_file_name(1), cursor_file_name(2));
    }

    #[test]
    fn cursor_path_joins_dir_and_name() {
        let job = OrderMonitorJob::new("/tmp/cursors");
        assert!(job.cursor_path(3).ends_with("order_monitor_3_last"));
    }

    // ── 游标推进逻辑 ──────────────────────────────────────────────────────────

    #[test]
    fn advance_cursor_takes_max_of_processed() {
        assert_eq!(advance_cursor(10, &[11, 12, 13]), 13);
    }

    #[test]
    fn advance_cursor_empty_batch_keeps_current() {
        assert_eq!(advance_cursor(42, &[]), 42);
    }

    #[test]
    fn advance_cursor_never_regresses_below_current() {
        // 即便处理 id 全部小于当前游标（异常场景），也不应回退。
        assert_eq!(advance_cursor(100, &[5, 6, 7]), 100);
    }

    #[test]
    fn advance_cursor_ascending_batch_lands_on_last() {
        // 升序处理时，新游标即批内最大 id。
        let processed: Vec<i64> = (50..=59).collect();
        assert_eq!(advance_cursor(49, &processed), 59);
    }

    // ── 游标 load/save round-trip（内存实现）─────────────────────────────────

    #[test]
    fn in_memory_cursor_round_trip() {
        let store = InMemoryCursorStore::new(0);
        assert_eq!(store.load().unwrap(), 0);
        store.save(123).unwrap();
        assert_eq!(store.load().unwrap(), 123);
        store.save(456).unwrap();
        assert_eq!(store.load().unwrap(), 456);
    }

    #[test]
    fn in_memory_cursor_default_starts_at_zero() {
        let store = InMemoryCursorStore::default();
        assert_eq!(store.load().unwrap(), 0);
    }

    // ── 游标 load/save round-trip（文件实现）─────────────────────────────────

    #[test]
    fn file_cursor_round_trip() {
        let mut path = std::env::temp_dir();
        path.push(format!(
            "order_monitor_test_{}_{}_last",
            std::process::id(),
            // 用纳秒时间戳避免并发测试间文件名碰撞。
            std::time::SystemTime::now()
                .duration_since(std::time::UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));
        let store = FileCursorStore::new(&path);

        // 文件尚不存在 → 视为 0。
        assert_eq!(store.load().unwrap(), 0);

        store.save(789).unwrap();
        assert_eq!(store.load().unwrap(), 789);

        // 覆盖写。
        store.save(1000).unwrap();
        assert_eq!(store.load().unwrap(), 1000);

        // 复用同路径的新实例也能读到（持久化生效）。
        let store2 = FileCursorStore::new(&path);
        assert_eq!(store2.load().unwrap(), 1000);

        let _ = std::fs::remove_file(&path);
    }

    #[test]
    fn file_cursor_invalid_content_falls_back_to_zero() {
        let mut path = std::env::temp_dir();
        path.push(format!(
            "order_monitor_badtest_{}_{}_last",
            std::process::id(),
            std::time::SystemTime::now()
                .duration_since(std::time::UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));
        std::fs::write(&path, "not-a-number").unwrap();
        let store = FileCursorStore::new(&path);
        assert_eq!(store.load().unwrap(), 0);
        let _ = std::fs::remove_file(&path);
    }

    // ── URL / 采购链接提炼 ────────────────────────────────────────────────────

    #[test]
    fn extract_urls_collects_in_order() {
        let text = "前缀 https://a.com/x 中间 http://b.com/y 尾";
        assert_eq!(
            extract_urls(text),
            vec!["https://a.com/x".to_string(), "http://b.com/y".to_string()]
        );
    }

    #[test]
    fn extract_urls_handles_newlines() {
        let text = "https://a.com/1\r\nhttps://a.com/2\nhttp://a.com/3";
        assert_eq!(
            extract_urls(text),
            vec![
                "https://a.com/1".to_string(),
                "https://a.com/2".to_string(),
                "http://a.com/3".to_string()
            ]
        );
    }

    #[test]
    fn extract_urls_none_when_absent() {
        assert!(extract_urls("没有链接的纯文本").is_empty());
    }

    #[test]
    fn extract_caigou_links_joins_with_crlf() {
        let raw = "https://x.com/a https://x.com/b";
        assert_eq!(
            extract_caigou_links(raw),
            Some("https://x.com/a\r\nhttps://x.com/b".to_string())
        );
    }

    #[test]
    fn extract_caigou_links_skips_out_of_stock() {
        // 含「缺货」的历史链接不可用。
        assert_eq!(extract_caigou_links("https://x.com/a 缺货"), None);
        assert!(!is_valid_caigou_link("https://x.com/a 缺货"));
    }

    #[test]
    fn extract_caigou_links_none_without_url() {
        assert_eq!(extract_caigou_links("只是文字"), None);
        assert!(!is_valid_caigou_link("只是文字"));
        assert!(is_valid_caigou_link("see https://x.com/a"));
    }

    // ── 任务元信息 ────────────────────────────────────────────────────────────

    #[test]
    fn job_metadata_is_stable() {
        let job = OrderMonitorJob::new("/tmp");
        assert_eq!(job.name(), "order_monitor");
        assert_eq!(job.interval(), Duration::from_secs(600));
    }

    #[test]
    fn batch_size_matches_legacy_value() {
        assert_eq!(BATCH_SIZE, 100);
    }
}
