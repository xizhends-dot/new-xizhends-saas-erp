//! `Store` 店铺模型。
//!
//! 对应租户库 `stores` 表（见 `migrations/tenant/0001_init_tenant_schema.sql`）：
//! 平台 + 店铺缩写（`dpqz`）/ 全称（`dpquancheng`）+ 隐藏标记（`is_hidden`）。
//!
//! `dpqz`（店铺缩写）是隐藏店铺过滤的键：`Principal::visible_stores` 用
//! `HashSet<String>` 形态的隐藏集合与各店铺的 `dpqz` 求交集做全局过滤
//! （见 design.md 5.5 / Task 6.4）。

use serde::{Deserialize, Serialize};

/// 店铺（租户库 `stores` 表的领域映射）。
///
/// 字段与建表语句保持一致：`is_hidden` 在 MySQL 中为 `TINYINT(1)`，
/// 这里用 `bool` 承载（sqlx 对 `TINYINT(1)` ↔ `bool` 有内建转换）。
#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize, sqlx::FromRow)]
pub struct Store {
    /// 主键 `id`，订单按 `store_id` 归属。
    pub id: i64,
    /// 平台标识 `y/r/w/m/q/yp`。
    pub platform: String,
    /// 店铺缩写（隐藏店铺过滤键）。
    pub dpqz: String,
    /// 店铺全称。
    pub dpquancheng: String,
    /// 是否隐藏店铺。
    pub is_hidden: bool,
}
