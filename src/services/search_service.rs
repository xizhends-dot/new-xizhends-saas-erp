//! 全局搜索服务（叠加店铺范围与租户隔离）—— Task 15.3 / Requirements 4.1。
//!
//! 跨「订单 / 子商品 / 采购 / 物流」的统一关键字搜索，返回命中的订单 / 子商品。
//!
//! ## 租户隔离（结构性，Requirements 4.1）
//! 本系统的租户隔离是**物理隔离**：每家租户拥有独立数据库，经
//! [`TenantContext::pool`](crate::middleware::tenant::TenantContext) 取得的连接池
//! 只指向该租户自己的库。[`global_search`] 仅对传入的租户连接池执行查询，因此
//! 命中行的 `tenant_id` 必然等于会话租户——跨租户数据在物理上不可达，无需在 SQL 里
//! 再附加 `tenant_id` 过滤。调用方（handler）务必传入 `TenantContext.pool`。
//!
//! ## 店铺范围叠加（Requirements 4.1 / 4.3）
//! 在租户隔离之上再叠加主体的店铺范围（[`StoreScope`]）：
//! - [`StoreScope::All`]：不附加店铺过滤（超管 / 公司管理员 / 全范围员工）。
//! - [`StoreScope::Restricted(ids)`]：仅返回 `orders.store_id ∈ ids` 的命中。
//!   - `ids` 为空：安全默认——返回空结果（无任何可访问店铺）。
//!
//! 店铺范围 SQL 片段由纯函数 [`store_scope_clause`] 生成，便于单元测试。
//!
//! ## 搜索字段
//! - 订单 `orders`：`platform_order_id` / `customer_name` / `customer_phone` / `customer_mail`
//! - 子商品 `order_items`：`item_code` / `product_title`
//! - 采购 `purchases`：`tabaono` / `caigou_link`
//! - 国内物流 `domestic_shipments`：`ship_number`
//! - 国际物流 `intl_shipments`：`intl_number`

use serde::{Deserialize, Serialize};
use sqlx::MySqlPool;

use crate::error::AppError;
use crate::models::user::StoreScope;

/// 单次搜索返回的命中条数上限（避免一次性扫描整库）。
const SEARCH_LIMIT: i64 = 200;

// ============================================================================
// 结果模型
// ============================================================================

/// 单条搜索命中：定位到一个订单（可选具体子商品）及其命中字段。
#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct SearchHit {
    /// 命中订单 id。
    pub order_id: i64,
    /// 命中的子商品 id；订单级字段命中（且该订单无子商品行）时为 `None`。
    pub order_item_id: Option<i64>,
    /// 平台代码 `y/r/w/m/q/yp`。
    pub platform: String,
    /// 平台订单号。
    pub platform_order_id: String,
    /// 所属店铺 id（可空）。
    pub store_id: Option<i64>,
    /// 命中的字段名（如 `platform_order_id` / `item_code` / `tabaono` ...）。
    pub matched_field: String,
    /// 命中字段的原始值片段（便于前端高亮 / 展示）。
    pub snippet: String,
}

/// 全局搜索结果集。
#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct SearchResults {
    /// 归一后的查询词（首尾空白已去除）。
    pub query: String,
    /// 命中列表（按订单 id 倒序、子商品 id 升序）。
    pub hits: Vec<SearchHit>,
}

impl SearchResults {
    /// 构造一个空结果集（无命中）。
    pub fn empty(query: impl Into<String>) -> Self {
        SearchResults {
            query: query.into(),
            hits: Vec::new(),
        }
    }
}

// ============================================================================
// 纯函数：店铺范围 SQL 片段（可单元测试）
// ============================================================================

/// 由 [`StoreScope`] 生成可叠加到 `WHERE` 的店铺过滤片段与其绑定值（**纯函数**）。
///
/// 约定片段针对 `orders` 表别名 `o`（列 `o.store_id`），返回 `(sql_fragment, binds)`：
/// - [`StoreScope::All`] ⟹ `("", [])`：无店铺过滤，调用方不应追加该片段。
/// - [`StoreScope::Restricted(ids)`] 且 `ids` 非空 ⟹ `("o.store_id IN (?, ?, ...)", ids)`。
/// - [`StoreScope::Restricted([])`]（空集合）⟹ `("1 = 0", [])`：匹配空集，安全默认返回无结果。
///
/// 绑定值与片段中的 `?` 占位符个数、顺序严格一致，供调用方按序 `bind`。
pub fn store_scope_clause(scope: &StoreScope) -> (String, Vec<i64>) {
    match scope {
        StoreScope::All => (String::new(), Vec::new()),
        StoreScope::Restricted(ids) if ids.is_empty() => {
            // 受限但无任何可访问店铺：恒假条件，杜绝任何命中（安全默认）。
            ("1 = 0".to_string(), Vec::new())
        }
        StoreScope::Restricted(ids) => {
            let placeholders = vec!["?"; ids.len()].join(", ");
            (format!("o.store_id IN ({placeholders})"), ids.clone())
        }
    }
}

// ============================================================================
// 纯函数：LIKE 模式构造 / 命中字段判定（可单元测试）
// ============================================================================

/// 把查询词包装为 `%...%` 的 LIKE 模式，并转义 LIKE 元字符（`\` `%` `_`），
/// 避免用户输入被当作通配符（防注入语义混淆）。
pub fn like_pattern(query: &str) -> String {
    let mut out = String::with_capacity(query.len() + 2);
    out.push('%');
    for c in query.chars() {
        if matches!(c, '\\' | '%' | '_') {
            out.push('\\');
        }
        out.push(c);
    }
    out.push('%');
    out
}

/// 在候选字段中按优先级找出第一个包含查询词（不区分大小写）的字段。
///
/// 返回 `(field_name, snippet)`；无任何字段命中时返回 `None`。
/// 候选顺序即命中归类的优先级。
fn first_match(
    needle_lower: &str,
    candidates: &[(&str, Option<&str>)],
) -> Option<(String, String)> {
    for (field, value) in candidates {
        if let Some(v) = value {
            if v.to_lowercase().contains(needle_lower) {
                return Some(((*field).to_string(), v.to_string()));
            }
        }
    }
    None
}

// ============================================================================
// DB 行 → 命中
// ============================================================================

/// 联表查询的原始行（订单 + 子商品 + 采购 + 物流的候选命中字段）。
///
/// 子商品 / 采购 / 物流经 `LEFT JOIN` 接入，相关列可空。
#[derive(Debug, sqlx::FromRow)]
struct RawHit {
    order_id: i64,
    order_item_id: Option<i64>,
    platform: String,
    platform_order_id: String,
    store_id: Option<i64>,
    customer_name: Option<String>,
    customer_phone: Option<String>,
    customer_mail: Option<String>,
    item_code: Option<String>,
    product_title: Option<String>,
    tabaono: Option<String>,
    caigou_link: Option<String>,
    ship_number: Option<String>,
    intl_number: Option<String>,
}

impl RawHit {
    /// 依据查询词判定本行的命中字段与片段，组装为 [`SearchHit`]。
    fn into_hit(self, needle_lower: &str) -> SearchHit {
        let candidates: [(&str, Option<&str>); 10] = [
            ("platform_order_id", Some(self.platform_order_id.as_str())),
            ("customer_name", self.customer_name.as_deref()),
            ("customer_phone", self.customer_phone.as_deref()),
            ("customer_mail", self.customer_mail.as_deref()),
            ("item_code", self.item_code.as_deref()),
            ("product_title", self.product_title.as_deref()),
            ("tabaono", self.tabaono.as_deref()),
            ("caigou_link", self.caigou_link.as_deref()),
            ("ship_number", self.ship_number.as_deref()),
            ("intl_number", self.intl_number.as_deref()),
        ];
        let (matched_field, snippet) = first_match(needle_lower, &candidates).unwrap_or_default();

        SearchHit {
            order_id: self.order_id,
            order_item_id: self.order_item_id,
            platform: self.platform,
            platform_order_id: self.platform_order_id,
            store_id: self.store_id,
            matched_field,
            snippet,
        }
    }
}

// ============================================================================
// 全局搜索入口
// ============================================================================

/// 跨订单 / 子商品 / 采购 / 物流的全局搜索，叠加店铺范围；租户隔离由 `pool` 物理保证。
///
/// 参数：
/// - `pool`：**租户库**连接池（`TenantContext.pool`）。租户隔离即由此结构性保证。
/// - `query`：查询词（首尾空白将被忽略；空词直接返回空结果）。
/// - `store_scope`：主体店铺范围（见 [`store_scope_clause`]）。
///
/// 返回按订单 id 倒序、子商品 id 升序排列的命中列表，最多 [`SEARCH_LIMIT`] 条
/// （同一 `(order_id, order_item_id)` 去重，保留首个命中字段）。
pub async fn global_search(
    pool: &MySqlPool,
    query: &str,
    store_scope: &StoreScope,
) -> Result<SearchResults, AppError> {
    let trimmed = query.trim();
    if trimmed.is_empty() {
        return Ok(SearchResults::empty(String::new()));
    }

    let (scope_sql, scope_binds) = store_scope_clause(store_scope);

    // 受限且无可访问店铺（"1 = 0"）：直接短路返回空结果，免去一次 DB 往返。
    if scope_sql == "1 = 0" {
        return Ok(SearchResults::empty(trimmed.to_string()));
    }

    let mut sql = String::from(
        "SELECT \
            o.id                AS order_id, \
            oi.id               AS order_item_id, \
            o.platform          AS platform, \
            o.platform_order_id AS platform_order_id, \
            o.store_id          AS store_id, \
            o.customer_name     AS customer_name, \
            o.customer_phone    AS customer_phone, \
            o.customer_mail     AS customer_mail, \
            oi.item_code        AS item_code, \
            oi.product_title    AS product_title, \
            p.tabaono           AS tabaono, \
            p.caigou_link       AS caigou_link, \
            ds.ship_number      AS ship_number, \
            intl.intl_number    AS intl_number \
        FROM `orders` o \
        LEFT JOIN `order_items` oi        ON oi.order_id = o.id \
        LEFT JOIN `purchases` p           ON p.order_item_id = oi.id \
        LEFT JOIN `domestic_shipments` ds ON ds.order_item_id = oi.id \
        LEFT JOIN `intl_shipments` intl   ON intl.order_item_id = oi.id \
        WHERE ( \
               o.platform_order_id LIKE ? \
            OR o.customer_name     LIKE ? \
            OR o.customer_phone    LIKE ? \
            OR o.customer_mail     LIKE ? \
            OR oi.item_code        LIKE ? \
            OR oi.product_title    LIKE ? \
            OR p.tabaono           LIKE ? \
            OR p.caigou_link       LIKE ? \
            OR ds.ship_number      LIKE ? \
            OR intl.intl_number    LIKE ? \
        )",
    );

    if !scope_sql.is_empty() {
        sql.push_str(" AND ");
        sql.push_str(&scope_sql);
    }
    sql.push_str(" ORDER BY o.id DESC, oi.id ASC LIMIT ?");

    let pattern = like_pattern(trimmed);

    let mut q = sqlx::query_as::<_, RawHit>(&sql);
    // 10 个字段 LIKE 占位符，按 SQL 中出现顺序绑定同一模式。
    for _ in 0..10 {
        q = q.bind(&pattern);
    }
    // 店铺范围占位符。
    for id in &scope_binds {
        q = q.bind(*id);
    }
    // LIMIT。
    q = q.bind(SEARCH_LIMIT);

    let rows = q.fetch_all(pool).await?;

    let needle_lower = trimmed.to_lowercase();
    let mut hits = Vec::with_capacity(rows.len());
    let mut seen: std::collections::HashSet<(i64, Option<i64>)> = std::collections::HashSet::new();
    for row in rows {
        let key = (row.order_id, row.order_item_id);
        if seen.insert(key) {
            hits.push(row.into_hit(&needle_lower));
        }
    }

    Ok(SearchResults {
        query: trimmed.to_string(),
        hits,
    })
}

// ============================================================================
// 单元测试
// ============================================================================

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn store_scope_clause_all_has_no_filter() {
        let (sql, binds) = store_scope_clause(&StoreScope::All);
        assert!(sql.is_empty(), "All 范围不应产生任何店铺过滤片段");
        assert!(binds.is_empty());
    }

    #[test]
    fn store_scope_clause_empty_restricted_matches_nothing() {
        let (sql, binds) = store_scope_clause(&StoreScope::Restricted(vec![]));
        assert_eq!(sql, "1 = 0", "空受限集合应产生恒假条件（安全默认无结果）");
        assert!(binds.is_empty());
    }

    #[test]
    fn store_scope_clause_non_empty_restricted_builds_in_clause() {
        let (sql, binds) = store_scope_clause(&StoreScope::Restricted(vec![1, 2]));
        assert_eq!(sql, "o.store_id IN (?, ?)");
        assert_eq!(binds, vec![1, 2]);
    }

    #[test]
    fn store_scope_clause_single_id_has_one_placeholder() {
        let (sql, binds) = store_scope_clause(&StoreScope::Restricted(vec![42]));
        assert_eq!(sql, "o.store_id IN (?)");
        assert_eq!(binds, vec![42]);
    }

    #[test]
    fn search_results_serializes_to_expected_json() {
        let results = SearchResults {
            query: "abc".to_string(),
            hits: vec![SearchHit {
                order_id: 7,
                order_item_id: Some(11),
                platform: "y".to_string(),
                platform_order_id: "ORD-abc".to_string(),
                store_id: Some(3),
                matched_field: "platform_order_id".to_string(),
                snippet: "ORD-abc".to_string(),
            }],
        };
        let json = serde_json::to_value(&results).unwrap();
        assert_eq!(json["query"], "abc");
        assert_eq!(json["hits"][0]["order_id"], 7);
        assert_eq!(json["hits"][0]["order_item_id"], 11);
        assert_eq!(json["hits"][0]["platform"], "y");
        assert_eq!(json["hits"][0]["platform_order_id"], "ORD-abc");
        assert_eq!(json["hits"][0]["store_id"], 3);
        assert_eq!(json["hits"][0]["matched_field"], "platform_order_id");
        assert_eq!(json["hits"][0]["snippet"], "ORD-abc");
    }

    #[test]
    fn search_results_round_trips_through_json() {
        let results = SearchResults {
            query: "x".to_string(),
            hits: vec![SearchHit {
                order_id: 1,
                order_item_id: None,
                platform: "r".to_string(),
                platform_order_id: "p1".to_string(),
                store_id: None,
                matched_field: "customer_name".to_string(),
                snippet: "x 太郎".to_string(),
            }],
        };
        let json = serde_json::to_string(&results).unwrap();
        let back: SearchResults = serde_json::from_str(&json).unwrap();
        assert_eq!(back, results);
        // 订单级命中（无子商品）应序列化为 null。
        let value = serde_json::to_value(&results).unwrap();
        assert!(value["hits"][0]["order_item_id"].is_null());
        assert!(value["hits"][0]["store_id"].is_null());
    }

    #[test]
    fn empty_results_helper_has_no_hits() {
        let r = SearchResults::empty("q");
        assert_eq!(r.query, "q");
        assert!(r.hits.is_empty());
    }

    #[test]
    fn like_pattern_wraps_and_escapes_metacharacters() {
        assert_eq!(like_pattern("abc"), "%abc%");
        // % _ \ 应被转义，避免被当作通配符。
        assert_eq!(like_pattern("a%b_c\\d"), "%a\\%b\\_c\\\\d%");
        assert_eq!(like_pattern(""), "%%");
    }

    #[test]
    fn first_match_respects_priority_order() {
        // 两个字段都命中时，返回靠前的字段。
        let candidates = [
            ("platform_order_id", Some("xx-100")),
            ("item_code", Some("100-yy")),
        ];
        let (field, snippet) = first_match("100", &candidates).unwrap();
        assert_eq!(field, "platform_order_id");
        assert_eq!(snippet, "xx-100");
    }

    #[test]
    fn first_match_is_case_insensitive_and_skips_none() {
        let candidates = [
            ("customer_name", None),
            ("product_title", Some("Hello World")),
        ];
        let (field, snippet) = first_match("world", &candidates).unwrap();
        assert_eq!(field, "product_title");
        assert_eq!(snippet, "Hello World");
    }

    #[test]
    fn first_match_returns_none_when_no_field_contains_needle() {
        let candidates = [("tabaono", Some("TB-1")), ("intl_number", None)];
        assert!(first_match("zzz", &candidates).is_none());
    }

    #[test]
    fn raw_hit_into_hit_classifies_matched_field() {
        let raw = RawHit {
            order_id: 5,
            order_item_id: Some(9),
            platform: "w".to_string(),
            platform_order_id: "P-5".to_string(),
            store_id: Some(2),
            customer_name: Some("田中".to_string()),
            customer_phone: None,
            customer_mail: None,
            item_code: None,
            product_title: None,
            tabaono: Some("TABA-777".to_string()),
            caigou_link: None,
            ship_number: None,
            intl_number: None,
        };
        let hit = raw.into_hit("777");
        assert_eq!(hit.order_id, 5);
        assert_eq!(hit.order_item_id, Some(9));
        assert_eq!(hit.matched_field, "tabaono");
        assert_eq!(hit.snippet, "TABA-777");
    }
}
