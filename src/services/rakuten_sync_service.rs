//! 乐天 RMS 订单同步编排。
//!
//! 对齐 old `plugins/rakuten-rms-api/index.php`：
//! `precheck -> get-api-orders(searchOrder) -> sync-orders(getOrder version=8)`。
//! 本服务接收可注入的 RMS provider，默认本地环境不访问外网。

use chrono::{Duration, NaiveDateTime, Utc};
use serde::Serialize;
use sqlx::MySqlPool;

use crate::error::AppError;
use crate::integrations::rakuten_rms::{
    import_batch_from_order_models, RakutenGetOrderRequest, RakutenRmsCredentials,
    RakutenRmsProvider, RakutenSearchOrderRequest, DEFAULT_BATCH_SIZE, DEFAULT_PAGE_SIZE,
};
use crate::repository::order_repo;
use crate::repository::store_repo::{self, StoreSummary};
use crate::services::order_import_service::{self, ImportOperator};

const DEFAULT_QUERY_DAYS: i64 = 7;
const MIN_SYNC_INTERVAL_SECONDS: i64 = 10;

#[derive(Debug, Clone, Serialize)]
pub struct RakutenSyncReport {
    pub store_id: i64,
    pub store_name: String,
    pub platform: String,
    pub ok: bool,
    pub status: String,
    pub message: String,
    pub checked_steps: Vec<String>,
    pub total_found: usize,
    pub skipped_existing: usize,
    pub fetched_orders: usize,
    pub imported_rows: usize,
    pub skipped_rows: usize,
    pub failed_rows: usize,
    pub imported_order_ids: Vec<i64>,
}

impl RakutenSyncReport {
    pub fn local_failure(
        store: &StoreSummary,
        status: &str,
        message: &str,
        steps: Vec<String>,
    ) -> Self {
        Self {
            store_id: store.id,
            store_name: store_name(store),
            platform: store.platform.clone(),
            ok: false,
            status: status.to_string(),
            message: message.to_string(),
            checked_steps: steps,
            total_found: 0,
            skipped_existing: 0,
            fetched_orders: 0,
            imported_rows: 0,
            skipped_rows: 0,
            failed_rows: 0,
            imported_order_ids: Vec::new(),
        }
    }
}

pub async fn sync_rakuten_orders(
    pool: &MySqlPool,
    store_id: i64,
    provider: &(dyn RakutenRmsProvider + Send + Sync),
) -> Result<RakutenSyncReport, AppError> {
    let store = store_repo::get_store_summary(pool, store_id)
        .await?
        .ok_or(AppError::NotFound)?;
    let mut steps = vec!["确认店铺平台为乐天 r".to_string()];

    if store.platform != "r" {
        return Err(AppError::Validation(
            "当前同步功能仅支持乐天店铺。".to_string(),
        ));
    }
    if store.is_hidden {
        return Ok(RakutenSyncReport::local_failure(
            &store,
            "hidden_store",
            "隐藏店铺不能同步订单，请先取消隐藏。",
            steps,
        ));
    }

    steps.push("检查 RMS serviceSecret / licenseKey".to_string());
    let Some(credentials) = credentials_from_store(&store) else {
        return Ok(RakutenSyncReport::local_failure(
            &store,
            "missing_credentials",
            "缺少 RMS serviceSecret 或 licenseKey，请先保存凭证。",
            steps,
        ));
    };

    steps.push("执行同步频率预检".to_string());
    if sync_too_frequent(store.last_sync_at) {
        return Ok(RakutenSyncReport::local_failure(
            &store,
            "too_frequent",
            "同步操作过于频繁，请稍后再试。",
            steps,
        ));
    }

    let start = Utc::now() - Duration::days(DEFAULT_QUERY_DAYS);
    let end = Utc::now();
    let request = RakutenSearchOrderRequest {
        start_datetime: format_rms_datetime(start.naive_utc()),
        end_datetime: format_rms_datetime(end.naive_utc()),
        request_records_amount: DEFAULT_PAGE_SIZE,
        request_page: 1,
    };
    steps.push(format!(
        "searchOrder 查询近 {DEFAULT_QUERY_DAYS} 天待配送订单(orderProgress=300)"
    ));

    let search = match provider.search_orders(&credentials, &request).await {
        Ok(response) => response,
        Err(err) => {
            return Ok(RakutenSyncReport::local_failure(
                &store,
                "provider_unwired",
                &format!(
                    "乐天 RMS provider 尚未接线或调用失败：{}",
                    err.client_message()
                ),
                steps,
            ));
        }
    };

    if search.total_records_amount > DEFAULT_PAGE_SIZE {
        return Ok(RakutenSyncReport::local_failure(
            &store,
            "too_many_orders",
            &format!(
                "订单总数 {} 超过单次查询上限 {}，请缩短查询窗口。",
                search.total_records_amount, DEFAULT_PAGE_SIZE
            ),
            steps,
        ));
    }

    let total_found = search.order_number_list.len();
    steps.push(format!("searchOrder 返回 {total_found} 个订单号"));
    let mut new_order_numbers = Vec::new();
    let mut skipped_existing = 0usize;
    for order_no in search.order_number_list {
        if order_repo::find_order_id_by_identity(pool, "r", Some(store.id), &order_no, None)
            .await?
            .is_some()
        {
            skipped_existing += 1;
        } else {
            new_order_numbers.push(order_no);
        }
    }
    steps.push(format!("过滤已存在订单 {skipped_existing} 个"));

    if new_order_numbers.is_empty() {
        return Ok(RakutenSyncReport {
            store_id: store.id,
            store_name: store_name(&store),
            platform: store.platform,
            ok: true,
            status: "no_new_orders".to_string(),
            message: "没有需要同步的新单。".to_string(),
            checked_steps: steps,
            total_found,
            skipped_existing,
            fetched_orders: 0,
            imported_rows: 0,
            skipped_rows: 0,
            failed_rows: 0,
            imported_order_ids: Vec::new(),
        });
    }

    let mut fetched_orders = 0usize;
    let mut imported_rows = 0usize;
    let mut skipped_rows = 0usize;
    let mut failed_rows = 0usize;
    let mut imported_order_ids = Vec::new();

    for chunk in new_order_numbers.chunks(DEFAULT_BATCH_SIZE) {
        let request = RakutenGetOrderRequest::new(chunk.to_vec());
        steps.push(format!(
            "getOrder(version=8) 拉取 {} 个订单详单",
            chunk.len()
        ));
        let response = match provider.get_orders(&credentials, &request).await {
            Ok(response) => response,
            Err(err) => {
                failed_rows += chunk.len();
                steps.push(format!(
                    "getOrder(version=8) 批次失败：{}",
                    err.client_message()
                ));
                continue;
            }
        };
        fetched_orders += response.orders.len();
        let batch = import_batch_from_order_models(&response.orders);
        let report = order_import_service::import_records(
            pool,
            &store,
            batch.preview,
            batch.records,
            ImportOperator::RakutenSync,
        )
        .await?;
        imported_rows += report.imported_rows;
        skipped_rows += report.skipped_rows;
        failed_rows += report.failed_rows;
        imported_order_ids.extend(report.imported_order_ids);
    }

    let ok = failed_rows == 0;
    let status = if ok { "synced" } else { "partial_failed" };
    let message = format!(
        "同步完成：查询 {total_found} 单，已存在 {skipped_existing} 单，拉取 {fetched_orders} 单，新增 {imported_rows} 行，跳过 {skipped_rows} 行，失败 {failed_rows} 行。"
    );

    Ok(RakutenSyncReport {
        store_id: store.id,
        store_name: store_name(&store),
        platform: store.platform,
        ok,
        status: status.to_string(),
        message,
        checked_steps: steps,
        total_found,
        skipped_existing,
        fetched_orders,
        imported_rows,
        skipped_rows,
        failed_rows,
        imported_order_ids,
    })
}

pub fn credentials_from_store(store: &StoreSummary) -> Option<RakutenRmsCredentials> {
    let service_secret = store.rms_service_secret.as_deref()?.trim();
    let license_key = store.rms_license_key.as_deref()?.trim();
    if service_secret.is_empty() || license_key.is_empty() {
        return None;
    }
    Some(RakutenRmsCredentials {
        service_secret: service_secret.to_string(),
        license_key: license_key.to_string(),
    })
}

pub fn format_rms_datetime(dt: NaiveDateTime) -> String {
    dt.format("%Y-%m-%dT%H:%M:%S+0900").to_string()
}

fn sync_too_frequent(last_sync_at: Option<NaiveDateTime>) -> bool {
    let Some(last_sync_at) = last_sync_at else {
        return false;
    };
    let now = Utc::now().naive_utc();
    now.signed_duration_since(last_sync_at).num_seconds() < MIN_SYNC_INTERVAL_SECONDS
}

fn store_name(store: &StoreSummary) -> String {
    if store.dpquancheng.trim().is_empty() {
        store.dpqz.clone()
    } else {
        store.dpquancheng.clone()
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use chrono::NaiveDate;

    #[test]
    fn credentials_require_both_values() {
        let mut store = store();
        assert!(credentials_from_store(&store).is_none());
        store.rms_service_secret = Some("secret".to_string());
        assert!(credentials_from_store(&store).is_none());
        store.rms_license_key = Some("key".to_string());
        assert_eq!(
            credentials_from_store(&store).unwrap().service_secret,
            "secret"
        );
    }

    #[test]
    fn rms_datetime_format_matches_old_php() {
        let dt = NaiveDate::from_ymd_opt(2026, 6, 1)
            .unwrap()
            .and_hms_opt(8, 9, 10)
            .unwrap();
        assert_eq!(format_rms_datetime(dt), "2026-06-01T08:09:10+0900");
    }

    fn store() -> StoreSummary {
        StoreSummary {
            id: 1,
            platform: "r".to_string(),
            dpqz: "r-main".to_string(),
            dpquancheng: "乐天主店".to_string(),
            is_hidden: false,
            rms_service_secret: None,
            rms_license_key: None,
            rms_credentials_updated_at: None,
            last_sync_at: None,
            last_sync_status: None,
            last_sync_message: None,
            created_at: NaiveDate::from_ymd_opt(2026, 1, 1)
                .unwrap()
                .and_hms_opt(0, 0, 0)
                .unwrap(),
            updated_at: NaiveDate::from_ymd_opt(2026, 1, 1)
                .unwrap()
                .and_hms_opt(0, 0, 0)
                .unwrap(),
        }
    }
}
