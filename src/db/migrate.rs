//! SQLx 迁移 runner（Task 2.3 / Requirements 1.3）。
//!
//! 本模块提供两类「schema 套用」能力，二者均基于编译期嵌入的迁移脚本：
//!
//! 1. [`run_master_migrations`]：把 `migrations/master/` 下的主库迁移按文件名前缀顺序
//!    套用到**固定主库连接池**（`AppState` 启动时调用一次）。
//! 2. [`init_tenant_db`]：把 `migrations/tenant/` 下的租户库初始 schema 套用到**新建的空租户库**
//!    （超管创建租户、或数据迁移工具准备目标库时调用）。
//!
//! 设计要点：
//! - 迁移脚本通过 [`sqlx::migrate!`] 在**编译期**嵌入二进制，无需运行期读取文件，便于部署。
//! - 迁移按版本号（文件名前缀，如 `0001_`、`0002_`）有序执行，并由 SQLx 在目标库中维护
//!   `_sqlx_migrations` 跟踪表，保证**幂等**：已套用的版本不会重复执行。
//! - 失败时统一映射为 [`AppError`]，底层 `MigrateError` 仅写入服务端日志、不外泄客户端
//!   （`sqlx::Error::Migrate` 由 `error.rs` 归入 `AppError::Db`，`client_message` 已脱敏）。
//!
//! > 注：数据迁移工具（Task 17，旧单库 6 镜像表 → 租户统一模型）也会复用本模块——
//! > 其在准备目标租户库时先调用 [`init_tenant_db`] 建好 schema，再执行分批数据搬运。
//! > 本任务仅实现 schema runner 部分，数据搬运逻辑由 Task 17 另行追加。

use sqlx::migrate::{MigrateError, Migrator};
use sqlx::MySqlPool;

use crate::error::AppError;

/// 主库迁移集合：编译期嵌入 `migrations/master/` 下全部脚本，按版本号有序。
///
/// 对应 `migrations/master/README.md`：platforms / tenants / tenant_platform /
/// admins / sessions / announcements / billing / tenant_profile 共 8 个迁移。
pub static MASTER_MIGRATOR: Migrator = sqlx::migrate!("migrations/master");

/// 租户库 schema 迁移集合：编译期嵌入 `migrations/tenant/` 下脚本。
///
/// `0001_init_tenant_schema.sql` 建立核心业务表；后续版本在不破坏既有租户库的前提下
/// 追加店铺凭证、导入同步等租户侧能力字段。
pub static TENANT_MIGRATOR: Migrator = sqlx::migrate!("migrations/tenant");

/// 对固定主库连接池套用全部主库迁移（启动时调用）。
///
/// 幂等：已套用的版本会被 SQLx 跳过；首次运行会创建 `_sqlx_migrations` 跟踪表与各业务表。
///
/// # Errors
/// 当任一迁移执行失败（脚本错误 / 数据库不可达 / 校验和与历史记录不符等）时返回
/// [`AppError`]；底层细节仅记入日志。
pub async fn run_master_migrations(pool: &MySqlPool) -> Result<(), AppError> {
    MASTER_MIGRATOR.run(pool).await.map_err(into_app_error)
}

/// 初始化一个**新建的空租户库**：套用租户库初始 schema。
///
/// 适用场景：
/// - 超管创建租户、建好空库后，调用本函数建立业务表结构；
/// - 数据迁移工具（Task 17）准备目标租户库时调用，随后再搬运数据。
///
/// 幂等：基于 SQLx 迁移跟踪表，重复调用不会重复建表。
///
/// # Errors
/// 同 [`run_master_migrations`]。
pub async fn init_tenant_db(pool: &MySqlPool) -> Result<(), AppError> {
    TENANT_MIGRATOR.run(pool).await.map_err(into_app_error)
}

/// 把 SQLx 的 [`MigrateError`] 归一为 [`AppError`]。
///
/// 借助 `sqlx::Error::Migrate` 包装，复用 `error.rs` 中既有的 `AppError::Db` 映射，
/// 从而沿用其「细节只入日志、客户端文案脱敏」的安全策略，无需改动 `error.rs`。
fn into_app_error(err: MigrateError) -> AppError {
    AppError::Db(sqlx::Error::Migrate(Box::new(err)))
}

#[cfg(test)]
mod tests {
    use super::*;

    /// 主库迁移应覆盖 0001..0008 共 8 个版本，且严格按版本号升序。
    #[test]
    fn master_migrator_contains_eight_ordered_migrations() {
        let versions: Vec<i64> = MASTER_MIGRATOR.iter().map(|m| m.version).collect();
        assert_eq!(versions, vec![1, 2, 3, 4, 5, 6, 7, 8]);
    }

    #[test]
    fn master_eighth_migration_extends_tenant_profile() {
        let migration = MASTER_MIGRATOR
            .iter()
            .find(|m| m.version == 8)
            .expect("应包含 0008 租户资料迁移");

        assert!(migration.description.contains("extend_tenant_profile"));
        for column in [
            "company_short_name",
            "contact_name",
            "contact_phone",
            "contact_email",
            "contact_wechat",
            "address",
            "remark",
        ] {
            assert!(
                migration.sql.contains(column),
                "0008 迁移应包含 `{column}` 列"
            );
        }
    }

    /// 主库各迁移脚本均非空（确保嵌入成功、未指向空文件）。
    #[test]
    fn master_migrations_have_non_empty_sql() {
        for m in MASTER_MIGRATOR.iter() {
            assert!(
                !m.sql.trim().is_empty(),
                "迁移版本 {} 的 SQL 不应为空",
                m.version
            );
        }
    }

    /// 主库首个迁移应建立 platforms 表（按文件名描述与脚本内容双重确认）。
    #[test]
    fn master_first_migration_is_platforms() {
        let first = MASTER_MIGRATOR.iter().next().expect("应至少有一个迁移");
        assert_eq!(first.version, 1);
        assert!(first.description.contains("platforms"));
        assert!(first.sql.contains("CREATE TABLE"));
        assert!(first.sql.contains("platforms"));
    }

    /// 租户库迁移应包含初始 schema，且后续版本按序追加。
    #[test]
    fn tenant_migrator_contains_init_schema() {
        let migrations: Vec<_> = TENANT_MIGRATOR.iter().collect();
        assert!(
            !migrations.is_empty(),
            "租户库至少应包含 0001 初始 schema 迁移"
        );

        let init = migrations[0];
        assert_eq!(init.version, 1);
        assert!(init.description.contains("init"));

        // 核心业务表均应在初始 schema 中建立。
        for table in [
            "stores",
            "users",
            "orders",
            "order_items",
            "purchases",
            "jp_shipments",
            "domestic_shipments",
            "intl_shipments",
            "order_logs",
        ] {
            assert!(
                init.sql.contains(table),
                "租户库初始 schema 应包含表 `{table}`"
            );
        }

        let versions: Vec<i64> = migrations.iter().map(|m| m.version).collect();
        assert_eq!(
            versions,
            vec![1, 2],
            "租户库迁移应覆盖 0001 初始 schema 与 0002 乐天 RMS 扩展"
        );
        let rms = migrations
            .iter()
            .find(|m| m.version == 2)
            .expect("应包含 0002 店铺 RMS 扩展迁移");
        for column in [
            "rms_service_secret",
            "rms_license_key",
            "last_sync_at",
            "last_sync_status",
            "last_sync_message",
        ] {
            assert!(rms.sql.contains(column), "0002 迁移应包含 `{column}` 列");
        }
    }

    /// MigrateError 应被归一为 AppError::Db，并通过既有脱敏文案对外呈现。
    #[test]
    fn migrate_error_maps_to_sanitized_db_error() {
        let err = into_app_error(MigrateError::VersionMissing(42));
        assert!(matches!(err, AppError::Db(_)));
        // 客户端文案脱敏：不应泄露底层迁移细节（如版本号）。
        assert_eq!(err.client_message(), "服务器内部错误");
        assert!(!err.client_message().contains("42"));
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// 数据迁移工具：旧单库 6 镜像宽表 → 每租户统一规范化模型（Task 17 / Requirements 12）
//
// 本节是数据迁移流水线的**第一阶段：只读抽取**（Task 17.1 / Requirements 12.1）。
// 依据 design.md 八「数据迁移方案」：
//   8.1 总路径：SRC(旧单库 ph_order{y,r,w,m,q,yp} + 归档 ph_order*_year)
//                → EXP(抽取: 按平台逐表读取) → NORM → CODE → SRCFILL → LOAD → VERIFY
//   3.1 现状：6 张同构镜像宽表，约 60 个扁平列（见 create_ph_orderyp.sql，PRIMARY KEY = `ID`）
//   3.3 字段映射表（old 扁平列 → 新规范化模型）
//
// ┌─ 只读保证（Requirements 12.5：「全程只读旧库保持旧库不变」）────────────────┐
// │ 本节对旧库**只发 SELECT / COUNT**，绝不 INSERT/UPDATE/DELETE/DDL。           │
// │ 抽取使用独立的「源库」连接池（`LegacyExtractor` 持有的 `source` 池），与新库  │
// │ （主库/租户库）连接池完全隔离。强烈建议为该源池配置只读账号，从权限层兜底。  │
// └──────────────────────────────────────────────────────────────────────────┘
//
// 后续阶段在**同一文件**顺序追加，本骨架预留扩展点（搜索 `TODO(17.x)`）：
//   - Task 17.2 规范化拆分 + 编码归一：复用 8.4 import mapper 把 LegacyRow 拆成
//                1 条 orders + 1 条 order_items + 按需子表；按平台选编码列归一 item_code。
//   - Task 17.3 货源回填：按 beizhu「精品/日本库存」语义与日本仓 ID 回填 source_type。
//   - Task 17.4 分批校验与回滚：每批 500 条事务包裹，五类对账 + 失败整批回滚。
// ═══════════════════════════════════════════════════════════════════════════

use std::collections::BTreeMap;

use sqlx::mysql::MySqlRow;
use sqlx::types::chrono::{NaiveDate, NaiveDateTime};
use sqlx::{Column, MySql, Row, Transaction};

use crate::models::platform::Platform;

/// 旧镜像宽表的主键列名（见 `old/database/create_ph_orderyp.sql`：`PRIMARY KEY (\`ID\`)`）。
///
/// 分批抽取按该列升序排序，保证跨批次的**稳定分页**（避免 LIMIT/OFFSET 在无序结果上漏读/重读）。
/// 6 张镜像表与各归档表结构同构，主键列名一致。
pub const LEGACY_ORDER_KEY: &str = "ID";

/// 旧系统主镜像宽表名：`ph_order{tag}`（`tag` = 平台代码 `y/r/w/m/q/yp`）。
///
/// 例：`Platform::Yahoo` → `ph_ordery`、`Platform::YahooAuction` → `ph_orderyp`。
/// 见 design.md 3.1 与 8.1。表名由**枚举白名单**派生（非外部输入），可安全用于 SQL 标识符拼接。
pub fn legacy_table_name(platform: Platform) -> String {
    format!("ph_order{}", platform.code())
}

/// 旧系统归档宽表名：`ph_order{tag}_{year}`（按 `YEAR(cdate)` 切分，见 design.md 3.1 注、8.5）。
///
/// 例：`legacy_archive_table_name(Platform::Yahoo, 2021)` → `ph_ordery_2021`。
/// `year` 为整型（数值，非字符串注入面），`tag` 取自枚举白名单，故可安全用于标识符拼接。
pub fn legacy_archive_table_name(platform: Platform, year: i32) -> String {
    format!("ph_order{}_{}", platform.code(), year)
}

/// 一个待抽取的旧库数据源：主镜像表，或某年份的归档表。
///
/// 作为抽取与后续阶段（17.2 拆分 / 17.4 分批）的统一句柄：`table_name()` 为纯函数、易测，
/// `platform()` 供编码归一（`Platform::item_code_field`）与货源回填按平台分支取用。
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum LegacySource {
    /// 主镜像宽表 `ph_order{tag}`。
    Main(Platform),
    /// 归档宽表 `ph_order{tag}_{year}`（按 `YEAR(cdate)` 切分）。
    Archive { platform: Platform, year: i32 },
}

impl LegacySource {
    /// 该数据源对应的旧库物理表名（纯函数）。
    pub fn table_name(&self) -> String {
        match *self {
            LegacySource::Main(p) => legacy_table_name(p),
            LegacySource::Archive { platform, year } => legacy_archive_table_name(platform, year),
        }
    }

    /// 该数据源所属平台（主表与归档表均归属同一平台）。
    pub fn platform(&self) -> Platform {
        match *self {
            LegacySource::Main(p) => p,
            LegacySource::Archive { platform, .. } => platform,
        }
    }
}

/// 旧宽表的一行（列名 → 文本化单元格值）。
///
/// 旧库 schema 在**编译期未知**（每平台一张约 60 列的扁平宽表，且各部署可能存在列差异），
/// 故不绑定到具体结构体，而以「列名 → `Option<String>`」的有序映射承载整行：
/// - `None` 表示该列 SQL `NULL`；`Some("")` 表示空串（旧库大量列默认空串，迁移时 17.2 再按需归一）。
/// - 单元格统一文本化：旧宽表绝大多数列为 `varchar/text`（含金额，设计 3.3「金额 varchar→decimal」
///   在 17.2 类型规整阶段处理），数值/日期列在抽取时一并转为字符串，保留原始可读形态。
///
/// 使用 `BTreeMap` 以**列名有序**，让调试输出与（无 DB 的）单测断言稳定可复现。
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct LegacyRow {
    cells: BTreeMap<String, Option<String>>,
}

impl LegacyRow {
    /// 构造空行。
    pub fn new() -> Self {
        Self {
            cells: BTreeMap::new(),
        }
    }

    /// 写入/覆盖一个单元格（`None` 表示 SQL NULL）。
    pub fn insert(&mut self, column: impl Into<String>, value: Option<String>) {
        self.cells.insert(column.into(), value);
    }

    /// 读取某列：列不存在或为 NULL 时返回 `None`；存在且非 NULL 返回 `Some(&str)`。
    pub fn get(&self, column: &str) -> Option<&str> {
        self.cells.get(column).and_then(|v| v.as_deref())
    }

    /// 该列是否存在（无论值是否为 NULL）。
    pub fn contains(&self, column: &str) -> bool {
        self.cells.contains_key(column)
    }

    /// 列数。
    pub fn len(&self) -> usize {
        self.cells.len()
    }

    /// 是否为空行（无任何列）。
    pub fn is_empty(&self) -> bool {
        self.cells.is_empty()
    }

    /// 全部列名（升序）。
    pub fn column_names(&self) -> impl Iterator<Item = &str> {
        self.cells.keys().map(|k| k.as_str())
    }

    /// 从一行 `MySqlRow` 构造：遍历所有列，按列名取值并文本化。
    ///
    /// 旧库列类型未知，故对每列**按多种类型依次尝试解码**（字符串 → 整型 → 浮点 → 日期/时间 →
    /// 布尔），命中即文本化；全部失败（罕见的不支持类型）记为 `None`，确保抽取不因个别列类型中断。
    /// 旧宽表以 `varchar/text` 为主，绝大多数列经首个 `String` 分支即命中。
    pub fn from_mysql_row(row: &MySqlRow) -> Self {
        let mut out = LegacyRow::new();
        for col in row.columns() {
            let name = col.name();
            let idx = col.ordinal();
            out.insert(name.to_string(), cell_to_string(row, idx));
        }
        out
    }
}

/// 把第 `idx` 列的单元格值文本化（NULL → `None`）。
///
/// 旧库列类型在编译期未知，按常见 MySQL 类型依次尝试解码；任一命中即返回其文本形态。
/// 顺序覆盖旧宽表实际用到的类型：`varchar/text`（String）、`int`（i64/i32）、`float`（f64/f32）、
/// `date`/`datetime`（NaiveDate/NaiveDateTime）、`tinyint(1)`（bool）。
fn cell_to_string(row: &MySqlRow, idx: usize) -> Option<String> {
    if let Ok(v) = row.try_get::<Option<String>, _>(idx) {
        return v;
    }
    if let Ok(v) = row.try_get::<Option<i64>, _>(idx) {
        return v.map(|x| x.to_string());
    }
    if let Ok(v) = row.try_get::<Option<i32>, _>(idx) {
        return v.map(|x| x.to_string());
    }
    if let Ok(v) = row.try_get::<Option<f64>, _>(idx) {
        return v.map(|x| x.to_string());
    }
    if let Ok(v) = row.try_get::<Option<f32>, _>(idx) {
        return v.map(|x| x.to_string());
    }
    if let Ok(v) = row.try_get::<Option<NaiveDateTime>, _>(idx) {
        return v.map(|x| x.to_string());
    }
    if let Ok(v) = row.try_get::<Option<NaiveDate>, _>(idx) {
        return v.map(|x| x.to_string());
    }
    if let Ok(v) = row.try_get::<Option<bool>, _>(idx) {
        return v.map(|x| x.to_string());
    }
    None
}

/// 一个抽取批次的结果：来源、本批起始 `offset` 与抽取到的行。
#[derive(Debug, Clone)]
pub struct LegacyBatch {
    pub source: LegacySource,
    pub offset: i64,
    pub rows: Vec<LegacyRow>,
}

impl LegacyBatch {
    /// 本批行数。
    pub fn len(&self) -> usize {
        self.rows.len()
    }

    /// 本批是否为空（已抽取到表尾）。
    pub fn is_empty(&self) -> bool {
        self.rows.is_empty()
    }
}

/// 旧库**只读**抽取器（Task 17.1 / Requirements 12.1、12.5）。
///
/// 持有一个**独立于新库的源库连接池**（`source`），仅对旧库发起 `SELECT` / `COUNT(*)`，
/// 绝不写入。后续阶段（17.2/17.3/17.4）从抽取出的 [`LegacyRow`] 规范化、回填、装载到新库，
/// 旧库自始至终保持不变，从而天然可回滚（设计 8.6）。
///
/// 典型用法（分批流式抽取，供 17.4 事务化处理）：
/// ```ignore
/// let extractor = LegacyExtractor::new(source_pool); // source_pool 建议为只读账号
/// let src = LegacySource::Main(Platform::Yahoo);
/// let total = extractor.count(src).await?;
/// let mut offset = 0;
/// while offset < total {
///     let batch = extractor.extract(src, offset, 500).await?;
///     if batch.is_empty() { break; }
///     // TODO(17.2): normalize batch.rows -> orders/order_items/子表
///     // TODO(17.4): 事务包裹 + 五类对账 + 失败整批回滚
///     offset += batch.len() as i64;
/// }
/// ```
pub struct LegacyExtractor {
    /// 源（旧）库连接池。仅用于只读抽取，与新库连接池隔离。
    source: sqlx::MySqlPool,
}

impl LegacyExtractor {
    /// 用给定的**源库**连接池构造抽取器。
    ///
    /// 调用方应传入指向旧单库的连接池；为贯彻「只读旧库」（Requirements 12.5），
    /// 建议该池使用仅授予 `SELECT` 权限的数据库账号，从权限层兜底防止误写。
    pub fn new(source: sqlx::MySqlPool) -> Self {
        Self { source }
    }

    /// 源库连接池访问（供诊断/测试）。
    pub fn source(&self) -> &sqlx::MySqlPool {
        &self.source
    }

    /// 统计某数据源的总行数（`SELECT COUNT(*)`，只读）。
    ///
    /// 供分批抽取计算批次数与对账基线（设计 8.6「行数对账」）。
    pub async fn count(&self, src: LegacySource) -> Result<i64, AppError> {
        let table = src.table_name();
        // 表名取自枚举白名单/整型年份，非外部输入，可安全拼接为标识符。
        let sql = format!("SELECT COUNT(*) FROM `{table}`");
        let (n,): (i64,) = sqlx::query_as(&sql).fetch_one(&self.source).await?;
        Ok(n)
    }

    /// 从某数据源按 `ID` 升序、`offset`/`limit` 分批抽取（只读 `SELECT *`）。
    ///
    /// - `offset` / `limit` 为负数时归一为 0（`limit = 0` 返回空批）。
    /// - 按 [`LEGACY_ORDER_KEY`]（`ID`）排序，保证跨批稳定分页。
    /// - 返回的每行是文本化的 [`LegacyRow`]（列名→值），交由 17.2 规范化拆分。
    ///
    /// 全程只读旧库，不产生任何写操作。
    pub async fn extract(
        &self,
        src: LegacySource,
        offset: i64,
        limit: i64,
    ) -> Result<LegacyBatch, AppError> {
        let offset = offset.max(0);
        let limit = limit.max(0);
        let table = src.table_name();

        // 表名/排序列均来自白名单与常量，非外部输入；offset/limit 走参数绑定。
        let sql = format!(
            "SELECT * FROM `{table}` ORDER BY `{key}` ASC LIMIT ? OFFSET ?",
            key = LEGACY_ORDER_KEY
        );

        let rows: Vec<MySqlRow> = sqlx::query(&sql)
            .bind(limit)
            .bind(offset)
            .fetch_all(&self.source)
            .await?;

        let rows = rows.iter().map(LegacyRow::from_mysql_row).collect();
        Ok(LegacyBatch {
            source: src,
            offset,
            rows,
        })
    }

    /// 便捷封装：抽取某平台**主镜像表**的一批（等价 `extract(LegacySource::Main(platform), ..)`）。
    ///
    /// 对应任务要求的 `extract_platform(platform, offset, limit)` 流式接口。
    pub async fn extract_platform(
        &self,
        platform: Platform,
        offset: i64,
        limit: i64,
    ) -> Result<LegacyBatch, AppError> {
        self.extract(LegacySource::Main(platform), offset, limit)
            .await
    }

    /// 便捷封装：抽取某平台某年份**归档表**的一批。
    pub async fn extract_archive(
        &self,
        platform: Platform,
        year: i32,
        offset: i64,
        limit: i64,
    ) -> Result<LegacyBatch, AppError> {
        self.extract(LegacySource::Archive { platform, year }, offset, limit)
            .await
    }
}

#[cfg(test)]
mod legacy_extract_tests {
    use super::*;

    /// 6 平台主镜像表名应为 `ph_order{tag}`，tag 与平台代码一致。
    #[test]
    fn legacy_table_name_for_all_platforms() {
        assert_eq!(legacy_table_name(Platform::Yahoo), "ph_ordery");
        assert_eq!(legacy_table_name(Platform::Rakuten), "ph_orderr");
        assert_eq!(legacy_table_name(Platform::Wowma), "ph_orderw");
        assert_eq!(legacy_table_name(Platform::Mercari), "ph_orderm");
        assert_eq!(legacy_table_name(Platform::Qoo10), "ph_orderq");
        assert_eq!(legacy_table_name(Platform::YahooAuction), "ph_orderyp");
    }

    /// 主表名应恰好等于 `ph_order` + 平台代码（对全部平台成立）。
    #[test]
    fn legacy_table_name_matches_code_for_every_platform() {
        for p in Platform::ALL {
            assert_eq!(legacy_table_name(p), format!("ph_order{}", p.code()));
        }
    }

    /// 归档表名应为 `ph_order{tag}_{year}`。
    #[test]
    fn legacy_archive_table_name_appends_year() {
        assert_eq!(
            legacy_archive_table_name(Platform::Yahoo, 2021),
            "ph_ordery_2021"
        );
        assert_eq!(
            legacy_archive_table_name(Platform::YahooAuction, 2019),
            "ph_orderyp_2019"
        );
        assert_eq!(
            legacy_archive_table_name(Platform::Mercari, 2023),
            "ph_orderm_2023"
        );
    }

    /// 归档表名对全部平台与给定年份均为「主表名 + _year」。
    #[test]
    fn legacy_archive_table_name_for_all_platforms() {
        let year = 2022;
        for p in Platform::ALL {
            assert_eq!(
                legacy_archive_table_name(p, year),
                format!("{}_{}", legacy_table_name(p), year)
            );
        }
    }

    /// `LegacySource::table_name` 应与独立函数结果一致，且 `platform()` 取回原平台。
    #[test]
    fn legacy_source_table_name_and_platform() {
        let main = LegacySource::Main(Platform::Qoo10);
        assert_eq!(main.table_name(), "ph_orderq");
        assert_eq!(main.platform(), Platform::Qoo10);

        let arch = LegacySource::Archive {
            platform: Platform::Rakuten,
            year: 2020,
        };
        assert_eq!(arch.table_name(), "ph_orderr_2020");
        assert_eq!(arch.platform(), Platform::Rakuten);
    }

    /// 主键排序列固定为旧表 `ID`（见 create_ph_orderyp.sql）。
    #[test]
    fn legacy_order_key_is_id() {
        assert_eq!(LEGACY_ORDER_KEY, "ID");
    }

    /// `LegacyRow` 基本读写语义：存在/NULL/缺失三态区分。
    #[test]
    fn legacy_row_get_insert_semantics() {
        let mut r = LegacyRow::new();
        assert!(r.is_empty());

        r.insert("orderId", Some("A-100".to_string()));
        r.insert("beizhu", Some(String::new())); // 空串：存在但为空
        r.insert("beizhu_log", None); // SQL NULL

        assert_eq!(r.len(), 3);
        assert_eq!(r.get("orderId"), Some("A-100"));
        // 空串：存在且非 NULL，返回 Some("")
        assert_eq!(r.get("beizhu"), Some(""));
        assert!(r.contains("beizhu"));
        // NULL 列：contains 为真，但 get 返回 None
        assert!(r.contains("beizhu_log"));
        assert_eq!(r.get("beizhu_log"), None);
        // 不存在的列：contains 为假，get 返回 None
        assert!(!r.contains("nope"));
        assert_eq!(r.get("nope"), None);
    }

    /// 列名应按字典序有序输出（便于稳定断言/调试）。
    #[test]
    fn legacy_row_column_names_are_sorted() {
        let mut r = LegacyRow::new();
        r.insert("zeta", Some("1".into()));
        r.insert("alpha", Some("2".into()));
        r.insert("mike", None);
        let names: Vec<&str> = r.column_names().collect();
        assert_eq!(names, vec!["alpha", "mike", "zeta"]);
    }

    /// 覆盖写入应替换旧值。
    #[test]
    fn legacy_row_insert_overwrites() {
        let mut r = LegacyRow::new();
        r.insert("k", Some("old".into()));
        r.insert("k", Some("new".into()));
        assert_eq!(r.len(), 1);
        assert_eq!(r.get("k"), Some("new"));
    }

    /// `LegacyBatch` 空批判定。
    #[test]
    fn legacy_batch_empty_semantics() {
        let batch = LegacyBatch {
            source: LegacySource::Main(Platform::Yahoo),
            offset: 0,
            rows: Vec::new(),
        };
        assert!(batch.is_empty());
        assert_eq!(batch.len(), 0);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// 数据迁移工具 · 第二阶段：规范化拆分与编码归一（Task 17.2 / Requirements 12.1、12.2）
//
// 承接 17.1 的只读抽取（LegacyRow / LegacySource / LegacyExtractor），把每条旧宽表
// 记录（LegacyRow）规范化拆分为统一模型的一组记录：
//   1 条 orders + 1 条 order_items + 按需子表（purchases / domestic_shipments /
//   intl_shipments）+ 由 beizhu_log 解析出的结构化 order_logs（解析失败入 legacy_log）。
//
// 设计依据：
//   8.2「字段映射执行」：
//     - 拆行为聚合：旧一行 → 1 orders + 1 order_items + 按需子表；
//     - 类型规整：金额/时间保持文本（领域模型用 String 承载 DECIMAL，见 models/order.rs）；
//     - beizhu_log → order_logs：按 `;` 分隔解析为结构化行，解析失败整段塞入 legacy_log；
//     - 多运单号：shipnumber 逗号分隔 → 多条 domestic_shipments。
//   8.3 / 3.3.1 商品编码归一：按平台选旧编码列（y/r=ItemId、w/q=itemCode、m/yp=lotnumber）。
//
// 关键复用（design 3.3.1「映射规则 4：数据迁移工具复用同一套映射器」）：
//   本阶段**复用 8.4 的 import mapper**（`crate::services::import::map_record`）把 LegacyRow
//   翻译为规范字段，保证「运行时导入」与「数据迁移」字段口径完全一致；编码归一同样经由
//   mapper 内部的 `Platform::item_code_field()` 完成，此处再断言一致性。
//
// 阶段边界：
//   - 货源回填（source_type 的「精品/日本库存」语义剥离与日本仓 ID 命中判定）属 Task 17.3，
//     本阶段一律置 `SourceType::Pending` 占位（见各 `TODO(17.3)`）。
//   - jp_shipments（日本仓出库）仅 jp_stock 子商品才有，依赖 17.3 的货源判定，故本阶段不产出。
//   - 分批事务与五类对账/回滚属 Task 17.4。
//
// 纯函数：本阶段全部为纯转换（无 DB 副作用），便于无需数据库的单元测试。
// ═══════════════════════════════════════════════════════════════════════════

use crate::models::order::{
    DomesticShipment, IntlShipment, Order, OrderItem, Purchase, PurchaseStatus, SourceType,
};
use crate::services::import::{map_record, RawRecord};
use crate::services::order_service::{
    predict_source, split_ship_numbers, ImportedItem, JpStockIndex,
};

/// 由 beizhu_log 解析出的一条结构化日志（→ 装载阶段写入 `order_logs`）。
///
/// 旧 `beizhu_log` 为分号（`;`）分隔的文本日志，单条条目典型形如：
/// `[2024-01-02 03:04:05] 采购张三 手动 国内采购-已采购`
/// （见 old `plugins/caigou_stats/cli_import.php` 的解析正则与各 `inc_order_detail_*.php`
/// 的 `explode(";", beizhu_log)` 渲染）。
///
/// 本结构保留可结构化的最小字段；落库时映射为 `order_logs` 的
/// `operator` / `new_value`(message) / `created_at`(timestamp 原文)。
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ParsedLog {
    /// 条目时间戳原文（已校验为 `%Y-%m-%d %H:%M:%S` 格式）。
    pub timestamp: String,
    /// 操作人（best-effort：正文首个空白分隔 token，如 `采购张三`）。
    pub operator: String,
    /// 日志正文（`]` 之后的完整文本，已 `trim`）。
    pub message: String,
}

/// 一条旧宽表记录规范化拆分后的统一模型记录集合（Task 17.2 产物）。
///
/// 不含数据库主键（`id` 均为 0，由 17.4 装载阶段在事务内分配并回填 `order_item_id` 等外键）。
#[derive(Debug, Clone)]
pub struct NormalizedRecord {
    /// 唯一的订单聚合（A 区客户信息 + 订单头，整单共享）。
    pub order: Order,
    /// 唯一的子商品（旧宽表一行对应一个子商品）。
    pub item: OrderItem,
    /// 国内采购信息：仅当存在采购字段（tabaono/caigou_link/cn_amount 等）时产出。
    pub purchase: Option<Purchase>,
    /// 国内（日本境内）物流：多运单号拆分，每个非空运单号一条。
    pub domestic_shipments: Vec<DomesticShipment>,
    /// 国际物流：仅当存在国际物流字段（tranship_comment/comment 等）时产出。
    pub intl_shipment: Option<IntlShipment>,
    /// 由 beizhu_log 解析成功的结构化日志（→ order_logs）。
    pub logs: Vec<ParsedLog>,
    /// beizhu_log 中解析失败的原始条目（→ 一条 `action_type='legacy_log'` 记录，不丢数据）。
    pub legacy_log: Vec<String>,
    /// 货源回填审计日志（Task 17.3 / Requirements 12.3）：每条记录恰好一条「货源回填」
    /// 迁移审计日志，记录回填得到的 `source_type` 及其依据（原 `beizhu`），→ 新库 `order_logs`。
    pub source_backfill_log: MigrationAuditLog,
}

/// 把一行 [`LegacyRow`] 转为 import mapper 所需的 [`RawRecord`]（列名 → 文本）。
///
/// - SQL `NULL` 列被跳过（mapper 内 `get` 把缺失列视作空串，与 8.4 行为一致）；
/// - 非 NULL 列原样复制（含空串，保留旧库「存在但为空」语义）。
///
/// 纯函数，便于无 DB 单测。
pub fn legacy_row_to_raw(row: &LegacyRow) -> RawRecord {
    let mut raw = RawRecord::new();
    for name in row.column_names() {
        if let Some(value) = row.get(name) {
            raw.insert(name.to_string(), value.to_string());
        }
        // NULL 列：跳过（mapper 视作缺失=空串）。
    }
    raw
}

/// 解析 beizhu_log 文本为「结构化日志 + 解析失败原文」两部分（design 8.2 第 3 点）。
///
/// 规则（忠实复刻旧系统 `explode(';', beizhu_log)` + 时间戳前缀）：
/// 1. 按 `;` 切分，逐条 `trim`，跳过空条目；
/// 2. 条目可解析当且仅当形如 `[<合法时间戳>] <非空正文>`，其中时间戳须匹配
///    `%Y-%m-%d %H:%M:%S`；解析成功 → [`ParsedLog`]；
/// 3. 其余条目（无方括号前缀 / 时间戳非法 / 正文为空）→ 原文进 `legacy_log`，确保不丢数据。
///
/// 返回 `(parsed, unparsed)`。纯函数。
pub fn parse_beizhu_log(raw: &str) -> (Vec<ParsedLog>, Vec<String>) {
    let mut parsed = Vec::new();
    let mut unparsed = Vec::new();
    for entry in raw.split(';') {
        let entry = entry.trim();
        if entry.is_empty() {
            continue;
        }
        match parse_log_entry(entry) {
            Some(p) => parsed.push(p),
            None => unparsed.push(entry.to_string()),
        }
    }
    (parsed, unparsed)
}

/// 解析单条 beizhu_log 条目；不符合 `[<合法时间戳>] <非空正文>` 形态返回 `None`。
fn parse_log_entry(entry: &str) -> Option<ParsedLog> {
    if !entry.starts_with('[') {
        return None;
    }
    let close = entry.find(']')?;
    let ts = entry[1..close].trim();
    // 校验时间戳格式（非法 → 视为解析失败，整段入 legacy_log）。
    NaiveDateTime::parse_from_str(ts, "%Y-%m-%d %H:%M:%S").ok()?;
    let body = entry[close + 1..].trim();
    if body.is_empty() {
        return None;
    }
    let operator = body.split_whitespace().next().unwrap_or("").to_string();
    Some(ParsedLog {
        timestamp: ts.to_string(),
        operator,
        message: body.to_string(),
    })
}

/// 把时间字符串解析为 `NaiveDateTime`（支持「日期+时间」与「仅日期」两种常见旧格式）。
fn parse_legacy_dt(s: &str) -> Option<NaiveDateTime> {
    let s = s.trim();
    if s.is_empty() {
        return None;
    }
    if let Ok(dt) = NaiveDateTime::parse_from_str(s, "%Y-%m-%d %H:%M:%S") {
        return Some(dt);
    }
    // 仅日期：补 00:00:00。
    if let Ok(d) = NaiveDate::parse_from_str(s, "%Y-%m-%d") {
        return d.and_hms_opt(0, 0, 0);
    }
    None
}

/// 解析整数文本，失败/空 → 0（旧宽表数量列多为 varchar，可能为空串）。
fn parse_i32(s: &str) -> i32 {
    s.trim().parse::<i32>().unwrap_or(0)
}

/// 读取 [`RawRecord`]（`HashMap<String,String>`）某列为 `&str`，缺失 → 空串。
fn raw_get<'a>(raw: &'a RawRecord, key: &str) -> &'a str {
    raw.get(key).map(String::as_str).unwrap_or("")
}

/// `Some(非空 trim 串)` 否则 `None`。
fn non_empty_opt(s: &str) -> Option<String> {
    let t = s.trim();
    if t.is_empty() {
        None
    } else {
        Some(t.to_string())
    }
}

/// 规范化拆分：把一行旧宽表记录拆为统一模型的一组记录（Task 17.2 核心，纯函数）。
///
/// 流程：
/// 1. [`legacy_row_to_raw`] 把 LegacyRow → RawRecord；
/// 2. **复用 8.4 import mapper** [`map_record`] 翻译为规范字段（含按平台编码归一）；
/// 3. 组装 1 条 `orders` + 1 条 `order_items`，并按字段存在性产出按需子表；
/// 4. `shipnumber` 多运单号经 [`split_ship_numbers`] 拆为 N 条 `domestic_shipments`；
/// 5. `beizhu_log` 经 [`parse_beizhu_log`] 拆为结构化 logs 与 legacy_log。
///
/// 货源（`source_type`）置 `Pending` 占位，由 Task 17.3 回填（见各 `TODO(17.3)`）。
pub fn normalize_row(platform: Platform, row: &LegacyRow) -> NormalizedRecord {
    let raw = legacy_row_to_raw(row);
    let mapped = map_record(platform, &raw);

    // 编码归一一致性（design 8.3 / 3.3.1）：mapper 经 Platform::item_code_field 选列，
    // 此处断言其确取自平台特定旧列，二者口径一致（debug 下生效，release 无开销）。
    debug_assert_eq!(
        mapped.item_code,
        raw.get(platform.item_code_field())
            .map(|s| s.trim().to_string())
            .unwrap_or_default(),
        "item_code 必须取自平台编码列 {}",
        platform.item_code_field()
    );

    // ---- orders（A 区客户信息 + 订单头，整单共享）----
    let order = Order {
        platform: mapped.platform.clone(),
        platform_order_id: raw_get(&raw, "orderId").trim().to_string(),
        order_detail_id: non_empty_opt(raw_get(&raw, "orderDetailId")),
        order_date: parse_legacy_dt(&mapped.order_date),
        order_status: mapped.order_status.clone(),
        customer_name: mapped.customer_name.clone(),
        customer_kana: mapped.customer_kana.clone(),
        customer_zip: mapped.customer_zip.clone(),
        customer_address: mapped.customer_address.clone(),
        customer_phone: mapped.customer_phone.clone(),
        customer_mail: mapped.customer_mail.clone(),
        pay_method: mapped.pay_method.clone(),
        ship_method: mapped.ship_method.clone(),
        total_item_price: mapped.total_item_price.clone(),
        postage_price: mapped.postage_price.clone(),
        total_price: mapped.total_price.clone(),
        imported_at: parse_legacy_dt(&mapped.imported_at).unwrap_or_default(),
        platform_extra: mapped.order_platform_extra.clone(),
        // id / store_id / review_* / items 由装载阶段或查询装配决定。
        ..Default::default()
    };

    // ---- order_items（B1 区，旧一行对应一个子商品）----
    // 货源回填（Task 17.3 / Requirements 12.3）：先识别旧 beizhu 的「精品/日本库存」货源语义
    // 并剥离，剥离后的纯流程文本再解析为 purchase_status；货源地 source_type 由
    // [`backfill_source`] 依「beizhu 语义 → 日本仓 ID 命中 → 可采购 → 待定」优先级解析。
    //
    // 旧宽表无独立「日本仓 ID」列（见 old/database/create_ph_orderyp.sql），故此处
    // jp_warehouse_id 为 None、日本仓现货索引为空：实际旧数据的回填等价于
    // 「beizhu 语义命中 → jp_stock，否则按可采购线索 → cn_purchase / pending」。
    // `backfill_source` 本身仍接受 jp_warehouse_id 与现货索引，保留设计 8.4 完整语义并可独立单测。
    let imported = ImportedItem {
        jp_warehouse_id: None,
        caigou_link: non_empty_opt(&mapped.caigou_link),
        buhuo_link: non_empty_opt(&mapped.buhuo_link),
        tabaono: non_empty_opt(&mapped.tabaono),
    };
    let backfill = backfill_source(mapped.beizhu.trim(), &imported, &JpStockIndex::new());

    let item = OrderItem {
        id: 0,
        order_id: 0,
        // 货源回填结果（Task 17.3）：beizhu 货源语义 / 日本仓命中 / 可采购 / 待定。
        source_type: backfill.source_type,
        // 流程进度：取自**已剥离货源语义**的 beizhu（精确匹配 13 个流程取值），
        // 未知/空 → 默认「待处理」。货源语义（精品/日本库存）已在 backfill 中剥离，绝不泄漏到此。
        purchase_status: backfill.purchase_status,
        // 编码归一（复用 mapper，等价 Platform::item_code_field 选列）。
        item_code: mapped.item_code.clone(),
        // 旧宽表无独立日本仓 ID 列，置空（货源回填已据 beizhu 语义/可采购线索判定）。
        jp_warehouse_id: None,
        product_title: mapped.product_title.clone(),
        item_option: mapped.item_option.clone(),
        chinese_option: mapped.chinese_option.clone(),
        quantity: parse_i32(&mapped.quantity),
        weight: mapped.weight.clone(),
        material: raw_get(&raw, "material").trim().to_string(),
        amount: mapped.amount.clone(),
        // caigou_user：首次写 tabaono 时赋值一次（旧表已持久化该结果），原样迁移。
        caigou_user: non_empty_opt(raw_get(&raw, "caigou_user")),
        main_image: raw_get(&raw, "zhutu").trim().to_string(),
        sku_image: raw_get(&raw, "skuimg").trim().to_string(),
        platform_extra: mapped.item_platform_extra.clone(),
    };

    // ---- purchases（按需：存在任一采购字段时产出）----
    let has_purchase = !mapped.tabaono.is_empty()
        || !mapped.caigou_link.is_empty()
        || !mapped.buhuo_link.is_empty()
        || !mapped.cn_amount.is_empty()
        || !mapped.com_amount.is_empty()
        || !mapped.caigou_time.is_empty()
        || !raw_get(&raw, "caigou_ordernums").trim().is_empty()
        || !mapped.cn_ship_number.is_empty();
    let purchase = if has_purchase {
        Some(Purchase {
            tabaono: mapped.tabaono.clone(),
            caigou_link: mapped.caigou_link.clone(),
            buhuo_link: mapped.buhuo_link.clone(),
            caigou_user: item.caigou_user.clone().unwrap_or_default(),
            caigou_time: parse_legacy_dt(&mapped.caigou_time),
            caigou_ordernums: raw_get(&raw, "caigou_ordernums").trim().to_string(),
            cn_amount: mapped.cn_amount.clone(),
            com_amount: mapped.com_amount.clone(),
            cn_ship_number: mapped.cn_ship_number.clone(),
            ..Default::default()
        })
    } else {
        None
    };

    // ---- domestic_shipments（多运单号拆分：每个非空运单号一条）----
    let ship_quantity = parse_i32(&mapped.ship_quantity);
    let ship_company = raw_get(&raw, "shipcompany").trim().to_string();
    let logistic_trace = non_empty_opt(raw_get(&raw, "logisticstrace"));
    let jpship_completed_at = parse_legacy_dt(&mapped.jpship_completed_at);
    let domestic_shipments = split_ship_numbers(&mapped.ship_number)
        .into_iter()
        .map(|ship_number| DomesticShipment {
            ship_number,
            ship_company: ship_company.clone(),
            ship_quantity,
            jpship_status: mapped.jpship_status.clone(),
            jpship_completed_at,
            logistic_trace: logistic_trace.clone(),
            ..Default::default()
        })
        .collect();

    // ---- intl_shipments（按需：存在国际物流字段时产出）----
    let tranship_comment = raw_get(&raw, "tranship_comment").trim().to_string();
    let comment = raw_get(&raw, "comment").trim().to_string();
    let intl_shipment = if !tranship_comment.is_empty() || !comment.is_empty() {
        Some(IntlShipment {
            tranship_comment,
            comment,
            ..Default::default()
        })
    } else {
        None
    };

    // ---- beizhu_log → 结构化 logs + 解析失败 legacy_log ----
    let (logs, legacy_log) = parse_beizhu_log(raw_get(&raw, "beizhu_log"));

    NormalizedRecord {
        order,
        item,
        purchase,
        domestic_shipments,
        intl_shipment,
        logs,
        legacy_log,
        source_backfill_log: backfill.audit_log,
    }
}

#[cfg(test)]
mod legacy_normalize_tests {
    use super::*;

    /// 用 (列名, 值) 列表快速构造一行 LegacyRow（值为 `Some`）。
    fn row(cells: &[(&str, &str)]) -> LegacyRow {
        let mut r = LegacyRow::new();
        for (k, v) in cells {
            r.insert((*k).to_string(), Some((*v).to_string()));
        }
        r
    }

    #[test]
    fn legacy_row_to_raw_copies_and_skips_null() {
        let mut r = LegacyRow::new();
        r.insert("orderId", Some("A-1".to_string()));
        r.insert("beizhu", Some(String::new())); // 空串：保留
        r.insert("buhuolink", None); // NULL：跳过

        let raw = legacy_row_to_raw(&r);
        assert_eq!(raw.get("orderId").map(String::as_str), Some("A-1"));
        // 空串保留（存在但为空）。
        assert_eq!(raw.get("beizhu").map(String::as_str), Some(""));
        // NULL 列被跳过。
        assert!(!raw.contains_key("buhuolink"));
    }

    #[test]
    fn normalize_row_yields_one_order_and_one_item() {
        let r = row(&[
            ("orderId", "Y-1001"),
            ("ItemId", "ITEM-Y"),
            ("ShipName", "山田太郎"),
            ("OrderStatus", "新規"),
        ]);
        let rec = normalize_row(Platform::Yahoo, &r);
        // 1 条 orders + 1 条 order_items。
        assert_eq!(rec.order.platform, "y");
        assert_eq!(rec.order.platform_order_id, "Y-1001");
        assert_eq!(rec.order.customer_name, "山田太郎");
        assert_eq!(rec.item.item_code, "ITEM-Y");
    }

    #[test]
    fn normalize_row_item_code_normalized_per_platform() {
        // y/r => ItemId
        let ry = row(&[("orderId", "1"), ("ItemId", "CODE-ItemId")]);
        assert_eq!(
            normalize_row(Platform::Yahoo, &ry).item.item_code,
            "CODE-ItemId"
        );
        assert_eq!(
            normalize_row(Platform::Rakuten, &ry).item.item_code,
            "CODE-ItemId"
        );

        // w/q => itemCode
        let rw = row(&[("orderId", "1"), ("itemCode", "CODE-itemCode")]);
        assert_eq!(
            normalize_row(Platform::Wowma, &rw).item.item_code,
            "CODE-itemCode"
        );
        assert_eq!(
            normalize_row(Platform::Qoo10, &rw).item.item_code,
            "CODE-itemCode"
        );

        // m/yp => lotnumber
        let rm = row(&[("orderId", "1"), ("lotnumber", "CODE-lot")]);
        assert_eq!(
            normalize_row(Platform::Mercari, &rm).item.item_code,
            "CODE-lot"
        );
        assert_eq!(
            normalize_row(Platform::YahooAuction, &rm).item.item_code,
            "CODE-lot"
        );
    }

    #[test]
    fn normalize_row_item_code_takes_only_platform_column() {
        // Yahoo 取 ItemId，应忽略同行的 itemCode/lotnumber 干扰列。
        let r = row(&[
            ("orderId", "1"),
            ("ItemId", "RIGHT"),
            ("itemCode", "WRONG-w"),
            ("lotnumber", "WRONG-m"),
        ]);
        assert_eq!(normalize_row(Platform::Yahoo, &r).item.item_code, "RIGHT");
        // Mercari 取 lotnumber。
        assert_eq!(
            normalize_row(Platform::Mercari, &r).item.item_code,
            "WRONG-m"
        );
    }

    #[test]
    fn normalize_row_multi_tracking_splits_into_n_shipments() {
        let r = row(&[
            ("orderId", "1"),
            ("shipnumber", "111, ,222,333"), // 含空段，应被丢弃
            ("shipcompany", "佐川"),
        ]);
        let rec = normalize_row(Platform::Yahoo, &r);
        let numbers: Vec<&str> = rec
            .domestic_shipments
            .iter()
            .map(|d| d.ship_number.as_str())
            .collect();
        assert_eq!(numbers, vec!["111", "222", "333"]);
        // 公共字段广播到每条。
        assert!(rec
            .domestic_shipments
            .iter()
            .all(|d| d.ship_company == "佐川"));
    }

    #[test]
    fn normalize_row_no_tracking_yields_no_shipment() {
        let r = row(&[("orderId", "1"), ("shipnumber", "")]);
        assert!(normalize_row(Platform::Yahoo, &r)
            .domestic_shipments
            .is_empty());
    }

    #[test]
    fn normalize_row_purchase_present_only_when_fields_present() {
        // 无采购字段 → None。
        let bare = row(&[("orderId", "1"), ("ItemId", "X")]);
        assert!(normalize_row(Platform::Yahoo, &bare).purchase.is_none());

        // 有 tabaono → Some。
        let with_tabao = row(&[("orderId", "1"), ("tabaono", "1688-ORDER-9")]);
        let p = normalize_row(Platform::Yahoo, &with_tabao).purchase;
        assert!(p.is_some());
        assert_eq!(p.unwrap().tabaono, "1688-ORDER-9");

        // 仅 cnamount 也应产出采购。
        let with_amount = row(&[("orderId", "1"), ("cnamount", "12.50")]);
        let p2 = normalize_row(Platform::Yahoo, &with_amount).purchase;
        assert!(p2.is_some());
        assert_eq!(p2.unwrap().cn_amount, "12.50");
    }

    #[test]
    fn normalize_row_intl_present_only_when_fields_present() {
        let bare = row(&[("orderId", "1")]);
        assert!(normalize_row(Platform::Yahoo, &bare)
            .intl_shipment
            .is_none());

        let with_comment = row(&[("orderId", "1"), ("tranship_comment", "转运备注")]);
        let intl = normalize_row(Platform::Yahoo, &with_comment).intl_shipment;
        assert!(intl.is_some());
        assert_eq!(intl.unwrap().tranship_comment, "转运备注");
    }

    #[test]
    fn normalize_row_caigou_user_migrated_as_is() {
        let r = row(&[
            ("orderId", "1"),
            ("tabaono", "T-1"),
            ("caigou_user", "采购张三"),
        ]);
        let rec = normalize_row(Platform::Yahoo, &r);
        assert_eq!(rec.item.caigou_user.as_deref(), Some("采购张三"));
        assert_eq!(rec.purchase.unwrap().caigou_user, "采购张三");
    }

    #[test]
    fn normalize_row_purchase_status_parsed_from_beizhu() {
        let r = row(&[("orderId", "1"), ("beizhu", "已发货代订单")]);
        assert_eq!(
            normalize_row(Platform::Yahoo, &r).item.purchase_status,
            PurchaseStatus::ShippedAgentOrder
        );
        // 未知/空 → 默认「待处理」。
        let r2 = row(&[("orderId", "1"), ("beizhu", "")]);
        assert_eq!(
            normalize_row(Platform::Yahoo, &r2).item.purchase_status,
            PurchaseStatus::Pending
        );
    }

    #[test]
    fn normalize_row_source_type_pending_without_purchasable_clue() {
        // 17.3 货源回填：beizhu 为纯流程文本（无货源语义），且无采购线索、旧表无日本仓 ID 列，
        // 故货源回填判为 Pending（规则 4）。流程进度正常解析为「已采购」。
        let r = row(&[("orderId", "1"), ("beizhu", "国内采购-已采购")]);
        let rec = normalize_row(Platform::Yahoo, &r);
        assert_eq!(rec.item.source_type, SourceType::Pending);
        assert_eq!(rec.item.purchase_status, PurchaseStatus::CnPurchased);
    }

    #[test]
    fn parse_beizhu_log_routes_parseable_and_unparseable() {
        let raw = "[2024-01-02 03:04:05] 采购张三 手动 国内采购-已采购;\
                   随便一段没有时间戳的文本;\
                   [bad-timestamp] 内容;\
                   [2024-05-06 07:08:09] 客服李四 系统 已发日本; ; ";
        let (parsed, unparsed) = parse_beizhu_log(raw);

        // 两条合法条目被结构化。
        assert_eq!(parsed.len(), 2);
        assert_eq!(parsed[0].timestamp, "2024-01-02 03:04:05");
        assert_eq!(parsed[0].operator, "采购张三");
        assert_eq!(parsed[0].message, "采购张三 手动 国内采购-已采购");
        assert_eq!(parsed[1].operator, "客服李四");

        // 无时间戳 / 非法时间戳 两条进 legacy_log；空白条目被跳过（不计入）。
        assert_eq!(unparsed.len(), 2);
        assert!(unparsed.contains(&"随便一段没有时间戳的文本".to_string()));
        assert!(unparsed.contains(&"[bad-timestamp] 内容".to_string()));
    }

    #[test]
    fn parse_beizhu_log_empty_yields_nothing() {
        let (parsed, unparsed) = parse_beizhu_log("");
        assert!(parsed.is_empty());
        assert!(unparsed.is_empty());
        // 全空白/分号同样不产出。
        let (p2, u2) = parse_beizhu_log("  ; ;  ");
        assert!(p2.is_empty());
        assert!(u2.is_empty());
    }

    #[test]
    fn parse_log_entry_rejects_empty_body() {
        // 有合法时间戳但正文为空 → 解析失败。
        assert_eq!(parse_log_entry("[2024-01-02 03:04:05]"), None);
        assert_eq!(parse_log_entry("[2024-01-02 03:04:05]   "), None);
    }

    #[test]
    fn normalize_row_beizhu_log_integrated() {
        let r = row(&[
            ("orderId", "1"),
            (
                "beizhu_log",
                "[2024-01-02 03:04:05] 采购张三 手动 国内采购-已采购;坏数据",
            ),
        ]);
        let rec = normalize_row(Platform::Yahoo, &r);
        assert_eq!(rec.logs.len(), 1);
        assert_eq!(rec.legacy_log, vec!["坏数据".to_string()]);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// 数据迁移工具 · 第三阶段：货源回填（Task 17.3 / Requirements 12.3）
//
// 承接 17.2 的规范化拆分，把旧 `beizhu` 中混杂的**货源语义**抽到独立的 `source_type`
// 维度，并保证剥离后的纯流程文本才进入 `purchase_status`（流程枚举绝不含货源语义）。
//
// 设计依据 design.md 8.4「货源回填（按日本仓ID）」与 Requirements 12.3，回填优先级：
//   1. 旧 `beizhu` 含「精品 / 日本库存」语义 → `source_type = jp_stock`，并把该语义从
//      流程状态中**剥离**（剥离后的残文本再解析 `purchase_status`，货源语义不泄漏）；
//   2. 否则按 `jp_warehouse_id`（旧表日本仓 ID 列）查日本仓现货索引：命中 → `jp_stock`；
//   3. 否则有日本仓 ID 但未命中、却有可采购线索 → `cn_purchase`；
//   4. 完全无法判定（无现货、无可采购线索）→ `pending`，留待人工在平台视图改判。
//   并为**每条**回填写一条「货源回填」迁移审计日志（操作人=迁移），保留可追溯性。
//
// 复用既有原语（design 3.5 / 8.4「迁移与运行时导入复用同一套判定」）：
//   规则 2~4 与运行时导入的 `order_service::predict_source(ImportedItem, JpStockIndex)`
//   完全一致——本阶段仅在其之前叠加旧 `beizhu`「精品/日本库存」语义识别与剥离（运行时
//   导入路径无此问题，因新系统 `beizhu` 已不含货源语义）。
//
// ┌─ 只读旧库（Requirements 12.5）────────────────────────────────────────────┐
// │ 回填全部为**纯函数**（无 DB 副作用），便于无数据库单测；审计日志仅写入**新**租户库  │
// │（[`write_source_backfill_log`] 走运行时 `query`），旧库自始至终只读不变。            │
// └──────────────────────────────────────────────────────────────────────────┘
// ═══════════════════════════════════════════════════════════════════════════

use crate::models::order::SOURCE_SEMANTICS;

/// 货源回填迁移审计日志的操作人（design 8.4：「操作人=迁移」）。
///
/// 区别于运行时导入的「货源判定」日志（`order_service::log_source_decision` 操作人=`system`），
/// 迁移回填以 `迁移` 标识来源，便于审计区分「历史数据回填」与「运行期判定」。
pub const MIGRATION_OPERATOR: &str = "迁移";

/// 货源回填迁移审计日志的动作类型（design 8.4：「类型=货源回填」）。
pub const SOURCE_BACKFILL_ACTION: &str = "货源回填";

/// 一条迁移审计日志（→ 新租户库 `order_logs`）。
///
/// 形状对齐 `order_service::log_source_decision` 写入的「货源判定」行：
/// `operator` / `action_type` / `field_name` / `old_value` / `new_value`。
/// 迁移阶段 `action_type='货源回填'`、`field_name='source_type'`、`new_value=<回填货源>`，
/// `old_value` 记原始 `beizhu`（回填依据）以保留可追溯性。装载时间戳由落库 SQL 取 `NOW()`。
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct MigrationAuditLog {
    /// 操作人（迁移回填固定为 [`MIGRATION_OPERATOR`]）。
    pub operator: String,
    /// 动作类型（货源回填固定为 [`SOURCE_BACKFILL_ACTION`]）。
    pub action_type: String,
    /// 被回填字段名（固定 `source_type`）。
    pub field_name: String,
    /// 旧值（回填依据的原始 `beizhu`；为空时记 `None`）。
    pub old_value: Option<String>,
    /// 新值（回填得到的货源地字符串，如 `jp_stock`）。
    pub new_value: String,
}

impl MigrationAuditLog {
    /// 构造一条「货源回填」审计日志：依据原始 `beizhu` 回填出 `resolved` 货源地。
    ///
    /// `old_value` 取 `beizhu` 去空白后的原文（空串记 `None`），`new_value` 取货源地字符串。
    pub fn source_backfill(old_beizhu: &str, resolved: SourceType) -> Self {
        let old = old_beizhu.trim();
        Self {
            operator: MIGRATION_OPERATOR.to_string(),
            action_type: SOURCE_BACKFILL_ACTION.to_string(),
            field_name: "source_type".to_string(),
            old_value: if old.is_empty() {
                None
            } else {
                Some(old.to_string())
            },
            new_value: resolved.as_str().to_string(),
        }
    }
}

/// 货源回填的完整结果（Task 17.3，纯函数产物）。
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct SourceBackfill {
    /// 回填得到的货源地维度。
    pub source_type: SourceType,
    /// 剥离货源语义后、由残余 `beizhu` 解析出的纯流程进度（未知/空 → 默认「待处理」）。
    pub purchase_status: PurchaseStatus,
    /// 是否从 `beizhu` 中识别并剥离了货源语义（「精品 / 日本库存」）。
    pub source_semantics_stripped: bool,
    /// 本次回填对应的「货源回填」迁移审计日志（每条记录恰好一条）。
    pub audit_log: MigrationAuditLog,
}

/// 从旧 `beizhu` 文本中剥离货源语义（[`SOURCE_SEMANTICS`]：「精品 / 日本库存」）。
///
/// 返回 `(剥离后文本, 是否剥离过)`：逐个删除命中的货源语义子串，再 `trim`。
/// 例：`"日本库存订单"` → `("订单", true)`；`"国内采购-已采购"` → `("国内采购-已采购", false)`。
///
/// 纯函数。这是迁移相比运行时导入额外需要处理的部分——旧系统把货源语义混进了 `beizhu`，
/// 必须剥离，才能保证 `purchase_status` 只承载纯流程进度（Requirements 3.2 / 12.3）。
pub fn strip_source_semantics(beizhu: &str) -> (String, bool) {
    let mut cleaned = beizhu.to_string();
    let mut stripped = false;
    for semantic in SOURCE_SEMANTICS {
        if cleaned.contains(semantic) {
            stripped = true;
            cleaned = cleaned.replace(semantic, "");
        }
    }
    (cleaned.trim().to_string(), stripped)
}

/// 货源回填核心（Task 17.3 / Requirements 12.3 / design 8.4）。**纯函数**，无 DB 副作用。
///
/// 输入：
/// - `beizhu`：旧宽表 `beizhu` 原文（可能混入「精品/日本库存」货源语义）；
/// - `item`：从旧记录解析出的可采购线索 + 日本仓 ID（[`ImportedItem`]）；
/// - `jp_stock`：日本仓现货索引（[`JpStockIndex`]）。
///
/// 优先级（与文件首部 banner 一致）：
/// 1. `beizhu` 含货源语义 → `jp_stock`，并把语义从流程文本剥离；
/// 2~4. 否则委托 [`predict_source`]：日本仓 ID 命中现货 → `jp_stock`；未命中但可采购 →
///     `cn_purchase`；信息不足 → `pending`。
///
/// 产出 [`SourceBackfill`]：回填货源、剥离后解析的纯流程 `purchase_status`、是否剥离、
/// 以及一条「货源回填」审计日志（每条记录恰好一条，Requirements 12.3）。
pub fn backfill_source(
    beizhu: &str,
    item: &ImportedItem,
    jp_stock: &JpStockIndex,
) -> SourceBackfill {
    let (cleaned, stripped) = strip_source_semantics(beizhu);

    // 规则 1：beizhu 货源语义优先 → jp_stock；否则复用运行时导入的 predict_source（规则 2~4）。
    let source_type = if stripped {
        SourceType::JpStock
    } else {
        predict_source(item, jp_stock)
    };

    // 剥离货源语义后的残余文本才解析流程进度，确保货源语义绝不泄漏进 purchase_status。
    let purchase_status = PurchaseStatus::from_str(cleaned.trim()).unwrap_or_default();

    // 审计日志依据**原始** beizhu（保留回填可追溯性），新值为回填得到的货源地。
    let audit_log = MigrationAuditLog::source_backfill(beizhu, source_type);

    SourceBackfill {
        source_type,
        purchase_status,
        source_semantics_stripped: stripped,
        audit_log,
    }
}

/// 把一条「货源回填」迁移审计日志写入**新租户库** `order_logs`（Task 17.3）。
///
/// 形状对齐 `order_service::log_source_decision`（货源判定）：`operator` / `action_type` /
/// `field_name` / `old_value` / `new_value`，时间戳取 `NOW()`。
/// 仅写新库，旧库只读不变（Requirements 12.5）。由 17.4 装载阶段在事务内、回填 `order_id` /
/// `order_item_id` 后调用。遵循租户库运行时 `query` API（编译期不存在租户库，不能用宏）。
pub async fn write_source_backfill_log(
    pool: &MySqlPool,
    order_id: i64,
    order_item_id: Option<i64>,
    log: &MigrationAuditLog,
) -> Result<(), AppError> {
    sqlx::query(
        "INSERT INTO `order_logs` \
         (`order_id`, `order_item_id`, `operator`, `action_type`, `field_name`, \
          `old_value`, `new_value`, `ip`, `created_at`) \
         VALUES (?, ?, ?, ?, ?, ?, ?, '', NOW())",
    )
    .bind(order_id)
    .bind(order_item_id)
    .bind(&log.operator)
    .bind(&log.action_type)
    .bind(&log.field_name)
    .bind(log.old_value.as_deref())
    .bind(&log.new_value)
    .execute(pool)
    .await?;

    Ok(())
}

#[cfg(test)]
mod legacy_source_backfill_tests {
    use super::*;

    /// 用 (列名, 值) 列表快速构造一行 LegacyRow（值为 `Some`）。
    fn row(cells: &[(&str, &str)]) -> LegacyRow {
        let mut r = LegacyRow::new();
        for (k, v) in cells {
            r.insert((*k).to_string(), Some((*v).to_string()));
        }
        r
    }

    /// 仅含可采购线索的 ImportedItem 构造助手。
    fn purchasable_item() -> ImportedItem {
        ImportedItem {
            caigou_link: Some("https://1688.com/p".into()),
            ..Default::default()
        }
    }

    // ── strip_source_semantics：识别 + 剥离 ──────────────────────────────────

    #[test]
    fn strip_detects_and_removes_japanese_stock() {
        // 「日本库存」整词剥离 → 空残文本。
        assert_eq!(strip_source_semantics("日本库存"), (String::new(), true));
        // 「日本库存订单」剥离货源语义后残「订单」。
        assert_eq!(
            strip_source_semantics("日本库存订单"),
            ("订单".to_string(), true)
        );
    }

    #[test]
    fn strip_detects_and_removes_jingpin() {
        assert_eq!(strip_source_semantics("精品"), (String::new(), true));
        assert_eq!(
            strip_source_semantics("－－－精品－－－"),
            ("－－－－－－".to_string(), true)
        );
    }

    #[test]
    fn strip_leaves_pure_process_text_untouched() {
        // 纯流程文本不含货源语义 → 原样返回、未剥离。
        assert_eq!(
            strip_source_semantics("国内采购-已采购"),
            ("国内采购-已采购".to_string(), false)
        );
        assert_eq!(strip_source_semantics(""), (String::new(), false));
    }

    // ── backfill_source 规则 1：beizhu 货源语义 → jp_stock + 剥离 ─────────────

    #[test]
    fn backfill_rule1_japanese_stock_semantic_maps_jp_stock_and_strips() {
        let bf = backfill_source("日本库存", &ImportedItem::default(), &JpStockIndex::new());
        assert_eq!(bf.source_type, SourceType::JpStock);
        assert!(bf.source_semantics_stripped);
        // 剥离后残文本为空 → 流程进度默认「待处理」，绝不含货源语义。
        assert_eq!(bf.purchase_status, PurchaseStatus::Pending);
        assert_eq!(bf.audit_log.action_type, SOURCE_BACKFILL_ACTION);
        assert_eq!(bf.audit_log.new_value, "jp_stock");
        assert_eq!(bf.audit_log.old_value.as_deref(), Some("日本库存"));
    }

    #[test]
    fn backfill_rule1_jingpin_semantic_maps_jp_stock_even_if_purchasable() {
        // 含「精品」即判 jp_stock，优先于可采购线索（规则 1 优先级最高）。
        let bf = backfill_source("精品", &purchasable_item(), &JpStockIndex::new());
        assert_eq!(bf.source_type, SourceType::JpStock);
        assert!(bf.source_semantics_stripped);
    }

    // ── backfill_source 规则 2：日本仓 ID 命中现货 → jp_stock ─────────────────

    #[test]
    fn backfill_rule2_warehouse_hit_maps_jp_stock() {
        let item = ImportedItem {
            jp_warehouse_id: Some("WH-1".into()),
            ..Default::default()
        };
        let index = JpStockIndex::from_ids(["WH-1"]);
        let bf = backfill_source("待处理", &item, &index);
        assert_eq!(bf.source_type, SourceType::JpStock);
        // 非货源语义，未剥离；纯流程文本正常解析。
        assert!(!bf.source_semantics_stripped);
        assert_eq!(bf.purchase_status, PurchaseStatus::Pending);
        assert_eq!(bf.audit_log.new_value, "jp_stock");
    }

    // ── backfill_source 规则 3：有日本仓 ID 未命中但可采购 → cn_purchase ──────

    #[test]
    fn backfill_rule3_warehouse_miss_but_purchasable_maps_cn_purchase() {
        let item = ImportedItem {
            jp_warehouse_id: Some("WH-404".into()),
            caigou_link: Some("https://1688.com/p".into()),
            ..Default::default()
        };
        let index = JpStockIndex::from_ids(["WH-1"]); // 不含 WH-404
        let bf = backfill_source("国内采购-已采购", &item, &index);
        assert_eq!(bf.source_type, SourceType::CnPurchase);
        // 流程文本被正确保留解析。
        assert_eq!(bf.purchase_status, PurchaseStatus::CnPurchased);
        assert_eq!(bf.audit_log.new_value, "cn_purchase");
        assert_eq!(bf.audit_log.old_value.as_deref(), Some("国内采购-已采购"));
    }

    #[test]
    fn backfill_rule3_no_warehouse_id_but_purchasable_maps_cn_purchase() {
        // 无日本仓 ID、有可采购线索 → cn_purchase。
        let bf = backfill_source("发货中", &purchasable_item(), &JpStockIndex::new());
        assert_eq!(bf.source_type, SourceType::CnPurchase);
        assert_eq!(bf.purchase_status, PurchaseStatus::Shipping);
    }

    // ── backfill_source 规则 4：无法判定 → pending ───────────────────────────

    #[test]
    fn backfill_rule4_no_clue_maps_pending() {
        // 无货源语义、无日本仓 ID、无可采购线索 → pending。
        let bf = backfill_source("待处理", &ImportedItem::default(), &JpStockIndex::new());
        assert_eq!(bf.source_type, SourceType::Pending);
        assert!(!bf.source_semantics_stripped);
        assert_eq!(bf.audit_log.new_value, "pending");
    }

    #[test]
    fn backfill_rule4_warehouse_miss_and_not_purchasable_maps_pending() {
        let item = ImportedItem {
            jp_warehouse_id: Some("WH-404".into()),
            ..Default::default()
        };
        let index = JpStockIndex::from_ids(["WH-1"]);
        let bf = backfill_source("", &item, &index);
        assert_eq!(bf.source_type, SourceType::Pending);
    }

    // ── 货源回填恒产出审计日志 + 完备性 ──────────────────────────────────────

    #[test]
    fn backfill_always_emits_one_audit_log_with_resolved_source() {
        // 每条回填恰好一条「货源回填」日志，operator=迁移、field=source_type、new=回填货源。
        for beizhu in ["日本库存", "国内采购-已采购", "待处理", ""] {
            let bf = backfill_source(beizhu, &purchasable_item(), &JpStockIndex::new());
            assert_eq!(bf.audit_log.operator, MIGRATION_OPERATOR);
            assert_eq!(bf.audit_log.action_type, SOURCE_BACKFILL_ACTION);
            assert_eq!(bf.audit_log.field_name, "source_type");
            // new_value 与回填得到的 source_type 始终一致。
            assert_eq!(bf.audit_log.new_value, bf.source_type.as_str());
        }
    }

    #[test]
    fn backfill_source_type_is_always_valid() {
        // 完备性：任意 beizhu × 可采购 × 日本仓命中组合，source_type 必属三取值之一。
        let index = JpStockIndex::from_ids(["HIT"]);
        for beizhu in ["日本库存", "精品订单", "国内采购-已采购", "随便", ""] {
            for jp in [None, Some("HIT"), Some("MISS")] {
                for caigou in [None, Some("https://1688/p")] {
                    let item = ImportedItem {
                        jp_warehouse_id: jp.map(str::to_string),
                        caigou_link: caigou.map(str::to_string),
                        ..Default::default()
                    };
                    let bf = backfill_source(beizhu, &item, &index);
                    assert!(SourceType::ALL.contains(&bf.source_type));
                }
            }
        }
    }

    #[test]
    fn backfill_purchase_status_never_contains_source_semantics() {
        // 剥离保证：任何含货源语义的 beizhu，回填后的 purchase_status 标签都不含货源语义。
        for beizhu in ["日本库存", "日本库存订单", "精品", "精品已发日本"] {
            let bf = backfill_source(beizhu, &ImportedItem::default(), &JpStockIndex::new());
            let label = bf.purchase_status.as_str();
            for banned in SOURCE_SEMANTICS {
                assert!(
                    !label.contains(banned),
                    "回填后 purchase_status `{label}` 不应含货源语义 `{banned}`（beizhu=`{beizhu}`）"
                );
            }
        }
    }

    // ── MigrationAuditLog 构造 ───────────────────────────────────────────────

    #[test]
    fn migration_audit_log_records_empty_beizhu_as_none() {
        let log = MigrationAuditLog::source_backfill("   ", SourceType::Pending);
        assert_eq!(log.old_value, None);
        assert_eq!(log.new_value, "pending");
        assert_eq!(log.operator, MIGRATION_OPERATOR);
    }

    // ── normalize_row 集成（17.2 + 17.3 端到端）─────────────────────────────

    #[test]
    fn normalize_row_backfills_jp_stock_from_beizhu_and_strips_semantics() {
        // 旧 beizhu = 日本库存订单 → jp_stock，且 purchase_status 不再含货源语义。
        let r = row(&[("orderId", "Y-1"), ("beizhu", "日本库存订单")]);
        let rec = normalize_row(Platform::Yahoo, &r);
        assert_eq!(rec.item.source_type, SourceType::JpStock);
        // 剥离「日本库存」后残「订单」不是合法流程值 → 默认待处理。
        assert_eq!(rec.item.purchase_status, PurchaseStatus::Pending);
        // 恰好一条货源回填审计日志。
        assert_eq!(rec.source_backfill_log.action_type, SOURCE_BACKFILL_ACTION);
        assert_eq!(rec.source_backfill_log.new_value, "jp_stock");
        assert_eq!(
            rec.source_backfill_log.old_value.as_deref(),
            Some("日本库存订单")
        );
    }

    #[test]
    fn normalize_row_backfills_cn_purchase_when_purchasable() {
        // 有采购链接 + 纯流程 beizhu → cn_purchase，流程进度正常解析。
        let r = row(&[
            ("orderId", "Y-2"),
            ("beizhu", "国内采购-已采购"),
            ("caigoulink", "https://1688.com/p"),
        ]);
        let rec = normalize_row(Platform::Yahoo, &r);
        assert_eq!(rec.item.source_type, SourceType::CnPurchase);
        assert_eq!(rec.item.purchase_status, PurchaseStatus::CnPurchased);
        assert_eq!(rec.source_backfill_log.new_value, "cn_purchase");
    }

    #[test]
    fn normalize_row_backfills_pending_without_clue() {
        // 无货源语义、无采购线索（旧表也无日本仓 ID 列）→ pending。
        let r = row(&[("orderId", "Y-3"), ("beizhu", "待处理")]);
        let rec = normalize_row(Platform::Yahoo, &r);
        assert_eq!(rec.item.source_type, SourceType::Pending);
        assert_eq!(rec.source_backfill_log.new_value, "pending");
    }
}

// ============================================================================
// 数据迁移工具 · 第四阶段：分批校验与回滚（Task 17.4 / Requirements 12.4, 12.5）
//
// 本阶段把 17.1 的抽取批次和 17.2/17.3 的 NormalizedRecord 串起来：
//   - 默认每批 500 条；
//   - 事务内写入目标租户库；
//   - 校验行数、金额、关键字段、货源分布、外键完整；
//   - 任一校验失败时回滚整批，并把失败样本留在返回值中，便于修正映射后重跑。
//
// 单元测试只覆盖纯函数校验逻辑，不依赖真实数据库。
// ============================================================================

use std::collections::BTreeSet;

/// 设计建议的迁移批次大小。
pub const MIGRATION_BATCH_SIZE: usize = 500;

/// 单批失败报告默认最多保留的样本数，避免坏数据过多时报告无限膨胀。
pub const DEFAULT_FAILURE_SAMPLE_LIMIT: usize = 20;

/// 货源占比区间，单位为 basis point：`10000 == 100%`。
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct SourceRatioRange {
    pub min_basis_points: u16,
    pub max_basis_points: u16,
}

impl SourceRatioRange {
    pub const fn new(min_basis_points: u16, max_basis_points: u16) -> Self {
        Self {
            min_basis_points,
            max_basis_points,
        }
    }

    pub const fn any() -> Self {
        Self::new(0, 10_000)
    }

    fn contains(&self, basis_points: u16) -> bool {
        self.min_basis_points <= self.max_basis_points
            && self.max_basis_points <= 10_000
            && basis_points >= self.min_basis_points
            && basis_points <= self.max_basis_points
    }
}

/// 单批货源分布预期。
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct SourceDistributionPolicy {
    pub cn_purchase: SourceRatioRange,
    pub jp_stock: SourceRatioRange,
    pub pending: SourceRatioRange,
}

impl SourceDistributionPolicy {
    pub const fn allow_any() -> Self {
        Self {
            cn_purchase: SourceRatioRange::any(),
            jp_stock: SourceRatioRange::any(),
            pending: SourceRatioRange::any(),
        }
    }

    fn range_for(&self, source_type: SourceType) -> SourceRatioRange {
        match source_type {
            SourceType::CnPurchase => self.cn_purchase,
            SourceType::JpStock => self.jp_stock,
            SourceType::Pending => self.pending,
        }
    }
}

/// 参与迁移金额对账的字段。
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum MigrationAmountField {
    TotalItemPrice,
    PostagePrice,
    TotalPrice,
    ItemAmount,
    CnAmount,
    ComAmount,
}

impl MigrationAmountField {
    pub const DEFAULT: [MigrationAmountField; 6] = [
        MigrationAmountField::TotalItemPrice,
        MigrationAmountField::PostagePrice,
        MigrationAmountField::TotalPrice,
        MigrationAmountField::ItemAmount,
        MigrationAmountField::CnAmount,
        MigrationAmountField::ComAmount,
    ];

    fn label(&self) -> &'static str {
        match self {
            MigrationAmountField::TotalItemPrice => "total_item_price",
            MigrationAmountField::PostagePrice => "postage_price",
            MigrationAmountField::TotalPrice => "total_price",
            MigrationAmountField::ItemAmount => "amount",
            MigrationAmountField::CnAmount => "cn_amount",
            MigrationAmountField::ComAmount => "com_amount",
        }
    }

    fn legacy_column(&self) -> &'static str {
        match self {
            MigrationAmountField::TotalItemPrice => "totalItemPrice",
            MigrationAmountField::PostagePrice => "postagePrice",
            MigrationAmountField::TotalPrice => "totalPrice",
            MigrationAmountField::ItemAmount => "amount",
            MigrationAmountField::CnAmount => "cnamount",
            MigrationAmountField::ComAmount => "comamount",
        }
    }

    fn normalized_value<'a>(&self, record: &'a NormalizedRecord) -> &'a str {
        match self {
            MigrationAmountField::TotalItemPrice => &record.order.total_item_price,
            MigrationAmountField::PostagePrice => &record.order.postage_price,
            MigrationAmountField::TotalPrice => &record.order.total_price,
            MigrationAmountField::ItemAmount => &record.item.amount,
            MigrationAmountField::CnAmount => record
                .purchase
                .as_ref()
                .map(|p| p.cn_amount.as_str())
                .unwrap_or(""),
            MigrationAmountField::ComAmount => record
                .purchase
                .as_ref()
                .map(|p| p.com_amount.as_str())
                .unwrap_or(""),
        }
    }
}

/// 单批迁移校验配置。
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct MigrationBatchValidationConfig {
    pub batch_size: usize,
    pub amount_fields: Vec<MigrationAmountField>,
    pub source_distribution: SourceDistributionPolicy,
    pub max_failure_samples: usize,
}

impl Default for MigrationBatchValidationConfig {
    fn default() -> Self {
        Self {
            batch_size: MIGRATION_BATCH_SIZE,
            amount_fields: MigrationAmountField::DEFAULT.to_vec(),
            source_distribution: SourceDistributionPolicy::allow_any(),
            max_failure_samples: DEFAULT_FAILURE_SAMPLE_LIMIT,
        }
    }
}

/// 单批上下文，用于稳定生成批次 id 和失败样本位置。
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct MigrationBatchContext {
    pub source: LegacySource,
    pub offset: i64,
}

impl MigrationBatchContext {
    pub fn from_batch(batch: &LegacyBatch) -> Self {
        Self {
            source: batch.source,
            offset: batch.offset,
        }
    }

    pub fn batch_id(&self) -> String {
        format!("{}@{}", self.source.table_name(), self.offset)
    }
}

/// 校验失败类别。
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum MigrationValidationKind {
    RowCount,
    Amount,
    RequiredField,
    SourceDistribution,
    ForeignKey,
}

impl MigrationValidationKind {
    pub fn as_str(&self) -> &'static str {
        match self {
            MigrationValidationKind::RowCount => "row_count",
            MigrationValidationKind::Amount => "amount",
            MigrationValidationKind::RequiredField => "required_field",
            MigrationValidationKind::SourceDistribution => "source_distribution",
            MigrationValidationKind::ForeignKey => "foreign_key",
        }
    }
}

/// 一条失败样本，指向旧表行和归一化后的关键字段。
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct MigrationFailureSample {
    pub batch_id: String,
    pub source: LegacySource,
    pub offset: i64,
    pub row_index: Option<usize>,
    pub legacy_key: Option<String>,
    pub platform_order_id: Option<String>,
    pub item_code: Option<String>,
    pub kind: MigrationValidationKind,
    pub message: String,
}

/// 单批失败报告。`total_failures` 记录全部失败数，`samples` 只保留前 N 条样本。
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct MigrationBatchFailure {
    pub batch_id: String,
    pub rolled_back: bool,
    pub total_failures: usize,
    pub samples: Vec<MigrationFailureSample>,
}

impl MigrationBatchFailure {
    pub fn new(batch_id: impl Into<String>) -> Self {
        Self {
            batch_id: batch_id.into(),
            rolled_back: false,
            total_failures: 0,
            samples: Vec::new(),
        }
    }

    pub fn is_empty(&self) -> bool {
        self.total_failures == 0
    }

    fn push(&mut self, sample: MigrationFailureSample, limit: usize) {
        self.total_failures += 1;
        if self.samples.len() < limit {
            self.samples.push(sample);
        }
    }

    fn mark_rolled_back(mut self) -> Self {
        self.rolled_back = true;
        self
    }
}

/// 货源分布计数。
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
pub struct SourceDistributionCount {
    pub cn_purchase: usize,
    pub jp_stock: usize,
    pub pending: usize,
}

impl SourceDistributionCount {
    pub fn total(&self) -> usize {
        self.cn_purchase + self.jp_stock + self.pending
    }

    pub fn count(&self, source_type: SourceType) -> usize {
        match source_type {
            SourceType::CnPurchase => self.cn_purchase,
            SourceType::JpStock => self.jp_stock,
            SourceType::Pending => self.pending,
        }
    }
}

/// 成功校验后的摘要。
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct MigrationBatchValidationSummary {
    pub legacy_rows: usize,
    pub normalized_records: usize,
    pub order_items: usize,
    pub source_distribution: SourceDistributionCount,
}

/// 一个已落库子表/日志行的外键引用快照，用于事务提交前校验。
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct MigrationForeignKeyRef {
    pub table: &'static str,
    pub record_index: usize,
    pub order_id: Option<i64>,
    pub order_item_id: Option<i64>,
}

/// 事务内实际插入行的外键快照。
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct MigrationForeignKeySnapshot {
    pub order_ids: Vec<i64>,
    pub order_item_ids: Vec<i64>,
    pub refs: Vec<MigrationForeignKeyRef>,
}

/// 单条 NormalizedRecord 的落库主键集合。
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct LoadedMigrationRecord {
    pub record_index: usize,
    pub order_id: i64,
    pub order_item_id: i64,
    pub refs: Vec<MigrationForeignKeyRef>,
}

impl MigrationForeignKeySnapshot {
    pub fn from_loaded_records(records: &[LoadedMigrationRecord]) -> Self {
        let mut snapshot = MigrationForeignKeySnapshot::default();
        for record in records {
            snapshot.order_ids.push(record.order_id);
            snapshot.order_item_ids.push(record.order_item_id);
            snapshot.refs.extend(record.refs.iter().cloned());
        }
        snapshot
    }
}

/// 单批落库成功摘要。
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct MigrationBatchSuccess {
    pub batch_id: String,
    pub source: LegacySource,
    pub offset: i64,
    pub legacy_rows: usize,
    pub orders_inserted: usize,
    pub order_items_inserted: usize,
    pub purchases_inserted: usize,
    pub domestic_shipments_inserted: usize,
    pub intl_shipments_inserted: usize,
    pub order_logs_inserted: usize,
    pub source_distribution: SourceDistributionCount,
    pub foreign_keys: MigrationForeignKeySnapshot,
}

/// 单批迁移执行错误。校验错误携带样本；数据库错误携带是否已尝试回滚。
#[derive(Debug)]
pub enum MigrationBatchRunError {
    Validation(MigrationBatchFailure),
    Db {
        batch_id: String,
        rolled_back: bool,
        source: AppError,
    },
}

/// 归一化一个抽取批次，复用 17.2/17.3 的行级转换。
pub fn normalize_legacy_batch(batch: &LegacyBatch) -> Vec<NormalizedRecord> {
    let platform = batch.source.platform();
    batch
        .rows
        .iter()
        .map(|row| normalize_row(platform, row))
        .collect()
}

/// 校验单批迁移数据。`foreign_keys=None` 时只做落库前校验；传入快照时追加外键完整性校验。
pub fn validate_migration_batch(
    context: &MigrationBatchContext,
    legacy_rows: &[LegacyRow],
    records: &[NormalizedRecord],
    foreign_keys: Option<&MigrationForeignKeySnapshot>,
    config: &MigrationBatchValidationConfig,
) -> Result<MigrationBatchValidationSummary, MigrationBatchFailure> {
    let mut failure = MigrationBatchFailure::new(context.batch_id());
    let limit = config.max_failure_samples;

    if legacy_rows.len() > config.batch_size {
        failure.push(
            make_failure_sample(
                context,
                legacy_rows,
                records,
                Some(config.batch_size),
                MigrationValidationKind::RowCount,
                format!(
                    "批次行数 {} 超过配置上限 {}",
                    legacy_rows.len(),
                    config.batch_size
                ),
            ),
            limit,
        );
    }

    if legacy_rows.len() != records.len() {
        failure.push(
            make_failure_sample(
                context,
                legacy_rows,
                records,
                None,
                MigrationValidationKind::RowCount,
                format!(
                    "旧表抽取行数 {} 与归一化记录数 {} 不一致",
                    legacy_rows.len(),
                    records.len()
                ),
            ),
            limit,
        );
    }

    let order_items = records.len();
    if legacy_rows.len() != order_items {
        failure.push(
            make_failure_sample(
                context,
                legacy_rows,
                records,
                None,
                MigrationValidationKind::RowCount,
                format!(
                    "旧表抽取行数 {} 与目标 order_items 预期行数 {} 不一致",
                    legacy_rows.len(),
                    order_items
                ),
            ),
            limit,
        );
    }

    validate_amounts(context, legacy_rows, records, config, &mut failure);
    validate_required_fields(context, legacy_rows, records, limit, &mut failure);

    let source_distribution = source_distribution(records);
    validate_source_distribution(
        context,
        legacy_rows,
        records,
        source_distribution,
        config,
        &mut failure,
    );

    if let Some(snapshot) = foreign_keys {
        validate_foreign_keys(context, legacy_rows, records, snapshot, limit, &mut failure);
    }

    if failure.is_empty() {
        Ok(MigrationBatchValidationSummary {
            legacy_rows: legacy_rows.len(),
            normalized_records: records.len(),
            order_items,
            source_distribution,
        })
    } else {
        Err(failure)
    }
}

/// 事务包裹地迁移一个抽取批次。校验失败会回滚整批并返回失败样本。
pub async fn migrate_legacy_batch(
    tenant_pool: &MySqlPool,
    batch: &LegacyBatch,
    config: &MigrationBatchValidationConfig,
) -> Result<MigrationBatchSuccess, MigrationBatchRunError> {
    let context = MigrationBatchContext::from_batch(batch);
    let batch_id = context.batch_id();
    let records = normalize_legacy_batch(batch);

    validate_migration_batch(&context, &batch.rows, &records, None, config)
        .map_err(MigrationBatchRunError::Validation)?;

    let mut tx = tenant_pool
        .begin()
        .await
        .map_err(|err| MigrationBatchRunError::Db {
            batch_id: batch_id.clone(),
            rolled_back: false,
            source: AppError::from(err),
        })?;

    let loaded = match insert_normalized_records_tx(&mut tx, &records).await {
        Ok(loaded) => loaded,
        Err(source) => {
            let rolled_back = tx.rollback().await.is_ok();
            return Err(MigrationBatchRunError::Db {
                batch_id,
                rolled_back,
                source,
            });
        }
    };

    let foreign_keys = MigrationForeignKeySnapshot::from_loaded_records(&loaded.records);
    let validation = match validate_migration_batch(
        &context,
        &batch.rows,
        &records,
        Some(&foreign_keys),
        config,
    ) {
        Ok(summary) => summary,
        Err(failure) => {
            let rolled_back = tx.rollback().await.is_ok();
            let failure = if rolled_back {
                failure.mark_rolled_back()
            } else {
                failure
            };
            return Err(MigrationBatchRunError::Validation(failure));
        }
    };

    tx.commit()
        .await
        .map_err(|err| MigrationBatchRunError::Db {
            batch_id: batch_id.clone(),
            rolled_back: false,
            source: AppError::from(err),
        })?;

    Ok(MigrationBatchSuccess {
        batch_id,
        source: batch.source,
        offset: batch.offset,
        legacy_rows: batch.rows.len(),
        orders_inserted: loaded.records.len(),
        order_items_inserted: loaded.records.len(),
        purchases_inserted: loaded.purchases_inserted,
        domestic_shipments_inserted: loaded.domestic_shipments_inserted,
        intl_shipments_inserted: loaded.intl_shipments_inserted,
        order_logs_inserted: loaded.order_logs_inserted,
        source_distribution: validation.source_distribution,
        foreign_keys,
    })
}

#[derive(Debug, Clone, Default, PartialEq, Eq)]
struct LoadedBatch {
    records: Vec<LoadedMigrationRecord>,
    purchases_inserted: usize,
    domestic_shipments_inserted: usize,
    intl_shipments_inserted: usize,
    order_logs_inserted: usize,
}

async fn insert_normalized_records_tx(
    tx: &mut Transaction<'_, MySql>,
    records: &[NormalizedRecord],
) -> Result<LoadedBatch, AppError> {
    let mut loaded = LoadedBatch::default();

    for (record_index, record) in records.iter().enumerate() {
        let order_id = insert_order_tx(tx, &record.order).await?;
        let mut item = record.item.clone();
        item.order_id = order_id;
        let order_item_id = insert_order_item_tx(tx, &item).await?;

        let mut refs = vec![MigrationForeignKeyRef {
            table: "order_items",
            record_index,
            order_id: Some(order_id),
            order_item_id: None,
        }];

        if let Some(purchase) = &record.purchase {
            insert_purchase_tx(tx, order_item_id, purchase).await?;
            loaded.purchases_inserted += 1;
            refs.push(MigrationForeignKeyRef {
                table: "purchases",
                record_index,
                order_id: None,
                order_item_id: Some(order_item_id),
            });
        }

        for shipment in &record.domestic_shipments {
            insert_domestic_shipment_tx(tx, order_item_id, shipment).await?;
            loaded.domestic_shipments_inserted += 1;
            refs.push(MigrationForeignKeyRef {
                table: "domestic_shipments",
                record_index,
                order_id: None,
                order_item_id: Some(order_item_id),
            });
        }

        if let Some(shipment) = &record.intl_shipment {
            insert_intl_shipment_tx(tx, order_item_id, shipment).await?;
            loaded.intl_shipments_inserted += 1;
            refs.push(MigrationForeignKeyRef {
                table: "intl_shipments",
                record_index,
                order_id: None,
                order_item_id: Some(order_item_id),
            });
        }

        for log in &record.logs {
            insert_parsed_log_tx(tx, order_id, order_item_id, log).await?;
            loaded.order_logs_inserted += 1;
            refs.push(MigrationForeignKeyRef {
                table: "order_logs",
                record_index,
                order_id: Some(order_id),
                order_item_id: Some(order_item_id),
            });
        }

        for raw in &record.legacy_log {
            insert_legacy_log_tx(tx, order_id, order_item_id, raw).await?;
            loaded.order_logs_inserted += 1;
            refs.push(MigrationForeignKeyRef {
                table: "order_logs",
                record_index,
                order_id: Some(order_id),
                order_item_id: Some(order_item_id),
            });
        }

        write_source_backfill_log_tx(
            tx,
            order_id,
            Some(order_item_id),
            &record.source_backfill_log,
        )
        .await?;
        loaded.order_logs_inserted += 1;
        refs.push(MigrationForeignKeyRef {
            table: "order_logs",
            record_index,
            order_id: Some(order_id),
            order_item_id: Some(order_item_id),
        });

        loaded.records.push(LoadedMigrationRecord {
            record_index,
            order_id,
            order_item_id,
            refs,
        });
    }

    Ok(loaded)
}

async fn insert_order_tx(tx: &mut Transaction<'_, MySql>, order: &Order) -> Result<i64, AppError> {
    let result = sqlx::query(
        "INSERT INTO `orders` \
         (`platform`, `platform_order_id`, `order_detail_id`, `store_id`, `order_date`, \
          `order_status`, `customer_name`, `customer_kana`, `customer_zip`, \
          `customer_address`, `customer_phone`, `customer_mail`, `pay_method`, \
          `ship_method`, `total_item_price`, `postage_price`, `total_price`, \
          `review_invited`, `reviewed`, `imported_at`, `platform_extra`) \
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
    )
    .bind(&order.platform)
    .bind(&order.platform_order_id)
    .bind(&order.order_detail_id)
    .bind(order.store_id)
    .bind(order.order_date)
    .bind(&order.order_status)
    .bind(&order.customer_name)
    .bind(&order.customer_kana)
    .bind(&order.customer_zip)
    .bind(&order.customer_address)
    .bind(&order.customer_phone)
    .bind(&order.customer_mail)
    .bind(&order.pay_method)
    .bind(&order.ship_method)
    .bind(&order.total_item_price)
    .bind(&order.postage_price)
    .bind(&order.total_price)
    .bind(order.review_invited)
    .bind(order.reviewed)
    .bind(order.imported_at)
    .bind(&order.platform_extra)
    .execute(&mut **tx)
    .await?;

    Ok(result.last_insert_id() as i64)
}

async fn insert_order_item_tx(
    tx: &mut Transaction<'_, MySql>,
    item: &OrderItem,
) -> Result<i64, AppError> {
    let result = sqlx::query(
        "INSERT INTO `order_items` \
         (`order_id`, `source_type`, `purchase_status`, `item_code`, `jp_warehouse_id`, \
          `product_title`, `item_option`, `chinese_option`, `quantity`, `weight`, \
          `material`, `amount`, `caigou_user`, `main_image`, `sku_image`, `platform_extra`) \
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
    )
    .bind(item.order_id)
    .bind(item.source_type)
    .bind(item.purchase_status)
    .bind(&item.item_code)
    .bind(&item.jp_warehouse_id)
    .bind(&item.product_title)
    .bind(&item.item_option)
    .bind(&item.chinese_option)
    .bind(item.quantity)
    .bind(&item.weight)
    .bind(&item.material)
    .bind(&item.amount)
    .bind(&item.caigou_user)
    .bind(&item.main_image)
    .bind(&item.sku_image)
    .bind(&item.platform_extra)
    .execute(&mut **tx)
    .await?;

    Ok(result.last_insert_id() as i64)
}

async fn insert_purchase_tx(
    tx: &mut Transaction<'_, MySql>,
    order_item_id: i64,
    purchase: &Purchase,
) -> Result<i64, AppError> {
    let result = sqlx::query(
        "INSERT INTO `purchases` \
         (`order_item_id`, `tabaono`, `caigou_link`, `buhuo_link`, `caigou_user`, \
          `caigou_time`, `caigou_ordernums`, `cn_amount`, `com_amount`, `cn_ship_number`) \
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
    )
    .bind(order_item_id)
    .bind(&purchase.tabaono)
    .bind(&purchase.caigou_link)
    .bind(&purchase.buhuo_link)
    .bind(&purchase.caigou_user)
    .bind(purchase.caigou_time)
    .bind(&purchase.caigou_ordernums)
    .bind(&purchase.cn_amount)
    .bind(&purchase.com_amount)
    .bind(&purchase.cn_ship_number)
    .execute(&mut **tx)
    .await?;

    Ok(result.last_insert_id() as i64)
}

async fn insert_domestic_shipment_tx(
    tx: &mut Transaction<'_, MySql>,
    order_item_id: i64,
    shipment: &DomesticShipment,
) -> Result<i64, AppError> {
    let result = sqlx::query(
        "INSERT INTO `domestic_shipments` \
         (`order_item_id`, `ship_number`, `ship_company`, `ship_quantity`, `jpship_status`, \
          `jpship_completed_at`, `logistic_trace`) \
         VALUES (?, ?, ?, ?, ?, ?, ?)",
    )
    .bind(order_item_id)
    .bind(&shipment.ship_number)
    .bind(&shipment.ship_company)
    .bind(shipment.ship_quantity)
    .bind(&shipment.jpship_status)
    .bind(shipment.jpship_completed_at)
    .bind(&shipment.logistic_trace)
    .execute(&mut **tx)
    .await?;

    Ok(result.last_insert_id() as i64)
}

async fn insert_intl_shipment_tx(
    tx: &mut Transaction<'_, MySql>,
    order_item_id: i64,
    shipment: &IntlShipment,
) -> Result<i64, AppError> {
    let result = sqlx::query(
        "INSERT INTO `intl_shipments` \
         (`order_item_id`, `intl_number`, `intl_status`, `intl_fee`, `intl_qty`, \
          `intl_weight`, `tranship_comment`, `comment`) \
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
    )
    .bind(order_item_id)
    .bind(&shipment.intl_number)
    .bind(&shipment.intl_status)
    .bind(&shipment.intl_fee)
    .bind(shipment.intl_qty)
    .bind(&shipment.intl_weight)
    .bind(&shipment.tranship_comment)
    .bind(&shipment.comment)
    .execute(&mut **tx)
    .await?;

    Ok(result.last_insert_id() as i64)
}

async fn insert_parsed_log_tx(
    tx: &mut Transaction<'_, MySql>,
    order_id: i64,
    order_item_id: i64,
    log: &ParsedLog,
) -> Result<i64, AppError> {
    let created_at = parse_legacy_dt(&log.timestamp);
    let result = sqlx::query(
        "INSERT INTO `order_logs` \
         (`order_id`, `order_item_id`, `operator`, `action_type`, `field_name`, \
          `old_value`, `new_value`, `ip`, `created_at`) \
         VALUES (?, ?, ?, 'legacy_log', 'beizhu_log', NULL, ?, '', ?)",
    )
    .bind(order_id)
    .bind(order_item_id)
    .bind(&log.operator)
    .bind(&log.message)
    .bind(created_at)
    .execute(&mut **tx)
    .await?;

    Ok(result.last_insert_id() as i64)
}

async fn insert_legacy_log_tx(
    tx: &mut Transaction<'_, MySql>,
    order_id: i64,
    order_item_id: i64,
    raw: &str,
) -> Result<i64, AppError> {
    let result = sqlx::query(
        "INSERT INTO `order_logs` \
         (`order_id`, `order_item_id`, `operator`, `action_type`, `field_name`, \
          `old_value`, `new_value`, `ip`, `created_at`) \
         VALUES (?, ?, ?, 'legacy_log', 'beizhu_log', ?, NULL, '', NOW())",
    )
    .bind(order_id)
    .bind(order_item_id)
    .bind(MIGRATION_OPERATOR)
    .bind(raw)
    .execute(&mut **tx)
    .await?;

    Ok(result.last_insert_id() as i64)
}

async fn write_source_backfill_log_tx(
    tx: &mut Transaction<'_, MySql>,
    order_id: i64,
    order_item_id: Option<i64>,
    log: &MigrationAuditLog,
) -> Result<i64, AppError> {
    let result = sqlx::query(
        "INSERT INTO `order_logs` \
         (`order_id`, `order_item_id`, `operator`, `action_type`, `field_name`, \
          `old_value`, `new_value`, `ip`, `created_at`) \
         VALUES (?, ?, ?, ?, ?, ?, ?, '', NOW())",
    )
    .bind(order_id)
    .bind(order_item_id)
    .bind(&log.operator)
    .bind(&log.action_type)
    .bind(&log.field_name)
    .bind(log.old_value.as_deref())
    .bind(&log.new_value)
    .execute(&mut **tx)
    .await?;

    Ok(result.last_insert_id() as i64)
}

fn validate_amounts(
    context: &MigrationBatchContext,
    legacy_rows: &[LegacyRow],
    records: &[NormalizedRecord],
    config: &MigrationBatchValidationConfig,
    failure: &mut MigrationBatchFailure,
) {
    let limit = config.max_failure_samples;
    for field in &config.amount_fields {
        let mut legacy_sum = 0_i128;
        let mut target_sum = 0_i128;
        let mut parse_failed = false;

        for (idx, row) in legacy_rows.iter().enumerate() {
            let value = row.get(field.legacy_column()).unwrap_or("");
            match parse_decimal_minor_units(value, 2) {
                Ok(v) => legacy_sum += v,
                Err(reason) => {
                    parse_failed = true;
                    failure.push(
                        make_failure_sample(
                            context,
                            legacy_rows,
                            records,
                            Some(idx),
                            MigrationValidationKind::Amount,
                            format!(
                                "旧表金额字段 {} 解析失败：{}",
                                field.legacy_column(),
                                reason
                            ),
                        ),
                        limit,
                    );
                }
            }
        }

        for (idx, record) in records.iter().enumerate() {
            let value = field.normalized_value(record);
            match parse_decimal_minor_units(value, 2) {
                Ok(v) => target_sum += v,
                Err(reason) => {
                    parse_failed = true;
                    failure.push(
                        make_failure_sample(
                            context,
                            legacy_rows,
                            records,
                            Some(idx),
                            MigrationValidationKind::Amount,
                            format!("目标金额字段 {} 解析失败：{}", field.label(), reason),
                        ),
                        limit,
                    );
                }
            }
        }

        if !parse_failed && legacy_sum != target_sum {
            failure.push(
                make_failure_sample(
                    context,
                    legacy_rows,
                    records,
                    None,
                    MigrationValidationKind::Amount,
                    format!(
                        "金额字段 {} 对账不一致：旧表合计 {}，目标合计 {}",
                        field.label(),
                        format_minor_units(legacy_sum, 2),
                        format_minor_units(target_sum, 2)
                    ),
                ),
                limit,
            );
        }
    }
}

fn validate_required_fields(
    context: &MigrationBatchContext,
    legacy_rows: &[LegacyRow],
    records: &[NormalizedRecord],
    limit: usize,
    failure: &mut MigrationBatchFailure,
) {
    for (idx, record) in records.iter().enumerate() {
        if record.order.platform_order_id.trim().is_empty() {
            failure.push(
                make_failure_sample(
                    context,
                    legacy_rows,
                    records,
                    Some(idx),
                    MigrationValidationKind::RequiredField,
                    "关键字段 platform_order_id 为空".to_string(),
                ),
                limit,
            );
        }
        if record.item.item_code.trim().is_empty() {
            failure.push(
                make_failure_sample(
                    context,
                    legacy_rows,
                    records,
                    Some(idx),
                    MigrationValidationKind::RequiredField,
                    "关键字段 item_code 为空".to_string(),
                ),
                limit,
            );
        }
        if record.item.source_type.as_str().is_empty() {
            failure.push(
                make_failure_sample(
                    context,
                    legacy_rows,
                    records,
                    Some(idx),
                    MigrationValidationKind::RequiredField,
                    "关键字段 source_type 为空".to_string(),
                ),
                limit,
            );
        }
    }
}

fn validate_source_distribution(
    context: &MigrationBatchContext,
    legacy_rows: &[LegacyRow],
    records: &[NormalizedRecord],
    count: SourceDistributionCount,
    config: &MigrationBatchValidationConfig,
    failure: &mut MigrationBatchFailure,
) {
    let total = count.total();
    if total == 0 {
        return;
    }

    for source_type in SourceType::ALL {
        let actual = count.count(source_type);
        let basis_points = ((actual * 10_000) / total) as u16;
        let range = config.source_distribution.range_for(source_type);
        if !range.contains(basis_points) {
            failure.push(
                make_failure_sample(
                    context,
                    legacy_rows,
                    records,
                    records
                        .iter()
                        .position(|record| record.item.source_type == source_type),
                    MigrationValidationKind::SourceDistribution,
                    format!(
                        "货源 {} 占比 {} 不在预期区间 {}..={} 内",
                        source_type.as_str(),
                        format_basis_points(basis_points),
                        format_basis_points(range.min_basis_points),
                        format_basis_points(range.max_basis_points)
                    ),
                ),
                config.max_failure_samples,
            );
        }
    }
}

fn validate_foreign_keys(
    context: &MigrationBatchContext,
    legacy_rows: &[LegacyRow],
    records: &[NormalizedRecord],
    snapshot: &MigrationForeignKeySnapshot,
    limit: usize,
    failure: &mut MigrationBatchFailure,
) {
    if snapshot.order_item_ids.len() != records.len() {
        failure.push(
            make_failure_sample(
                context,
                legacy_rows,
                records,
                None,
                MigrationValidationKind::ForeignKey,
                format!(
                    "已落库 order_items 数 {} 与归一化记录数 {} 不一致",
                    snapshot.order_item_ids.len(),
                    records.len()
                ),
            ),
            limit,
        );
    }

    let order_ids: BTreeSet<i64> = snapshot.order_ids.iter().copied().collect();
    let order_item_ids: BTreeSet<i64> = snapshot.order_item_ids.iter().copied().collect();

    for (idx, order_id) in snapshot.order_ids.iter().enumerate() {
        if *order_id <= 0 {
            failure.push(
                make_failure_sample(
                    context,
                    legacy_rows,
                    records,
                    Some(idx),
                    MigrationValidationKind::ForeignKey,
                    format!("orders 主键非法：{}", order_id),
                ),
                limit,
            );
        }
    }

    for (idx, order_item_id) in snapshot.order_item_ids.iter().enumerate() {
        if *order_item_id <= 0 {
            failure.push(
                make_failure_sample(
                    context,
                    legacy_rows,
                    records,
                    Some(idx),
                    MigrationValidationKind::ForeignKey,
                    format!("order_items 主键非法：{}", order_item_id),
                ),
                limit,
            );
        }
    }

    for reference in &snapshot.refs {
        if reference.record_index >= records.len() {
            failure.push(
                make_failure_sample(
                    context,
                    legacy_rows,
                    records,
                    None,
                    MigrationValidationKind::ForeignKey,
                    format!(
                        "{} 引用了不存在的记录索引 {}",
                        reference.table, reference.record_index
                    ),
                ),
                limit,
            );
        }

        if let Some(order_id) = reference.order_id {
            if !order_ids.contains(&order_id) {
                failure.push(
                    make_failure_sample(
                        context,
                        legacy_rows,
                        records,
                        Some(reference.record_index),
                        MigrationValidationKind::ForeignKey,
                        format!("{} 的 order_id={} 未命中 orders", reference.table, order_id),
                    ),
                    limit,
                );
            }
        }

        if let Some(order_item_id) = reference.order_item_id {
            if !order_item_ids.contains(&order_item_id) {
                failure.push(
                    make_failure_sample(
                        context,
                        legacy_rows,
                        records,
                        Some(reference.record_index),
                        MigrationValidationKind::ForeignKey,
                        format!(
                            "{} 的 order_item_id={} 未命中 order_items",
                            reference.table, order_item_id
                        ),
                    ),
                    limit,
                );
            }
        }
    }
}

fn source_distribution(records: &[NormalizedRecord]) -> SourceDistributionCount {
    let mut count = SourceDistributionCount::default();
    for record in records {
        match record.item.source_type {
            SourceType::CnPurchase => count.cn_purchase += 1,
            SourceType::JpStock => count.jp_stock += 1,
            SourceType::Pending => count.pending += 1,
        }
    }
    count
}

fn make_failure_sample(
    context: &MigrationBatchContext,
    legacy_rows: &[LegacyRow],
    records: &[NormalizedRecord],
    row_index: Option<usize>,
    kind: MigrationValidationKind,
    message: String,
) -> MigrationFailureSample {
    let row = row_index.and_then(|idx| legacy_rows.get(idx));
    let record = row_index.and_then(|idx| records.get(idx));
    MigrationFailureSample {
        batch_id: context.batch_id(),
        source: context.source,
        offset: context.offset,
        row_index,
        legacy_key: row
            .and_then(|r| r.get(LEGACY_ORDER_KEY))
            .map(|value| value.to_string()),
        platform_order_id: record
            .map(|r| r.order.platform_order_id.trim().to_string())
            .filter(|value| !value.is_empty()),
        item_code: record
            .map(|r| r.item.item_code.trim().to_string())
            .filter(|value| !value.is_empty()),
        kind,
        message,
    }
}

fn parse_decimal_minor_units(raw: &str, scale: u32) -> Result<i128, String> {
    let mut s = raw.trim().replace(',', "");
    if s.is_empty() {
        return Ok(0);
    }

    let negative = s.starts_with('-');
    if negative || s.starts_with('+') {
        s = s[1..].to_string();
    }
    if s.is_empty() {
        return Err("缺少数字".to_string());
    }

    let mut parts = s.split('.');
    let integer = parts.next().unwrap_or("");
    let fraction = parts.next().unwrap_or("");
    if parts.next().is_some() {
        return Err(format!("非法小数格式 `{raw}`"));
    }
    if integer.is_empty() && fraction.is_empty() {
        return Err("缺少数字".to_string());
    }
    if !integer.chars().all(|c| c.is_ascii_digit()) {
        return Err(format!("整数部分非法 `{raw}`"));
    }
    if !fraction.chars().all(|c| c.is_ascii_digit()) {
        return Err(format!("小数部分非法 `{raw}`"));
    }
    if fraction.len() > scale as usize {
        return Err(format!("小数位超过 {} 位：`{raw}`", scale));
    }

    let mut units = if integer.is_empty() {
        0
    } else {
        integer
            .parse::<i128>()
            .map_err(|_| format!("金额过大 `{raw}`"))?
    };
    units *= 10_i128.pow(scale);

    let mut fraction_text = fraction.to_string();
    while fraction_text.len() < scale as usize {
        fraction_text.push('0');
    }
    if !fraction_text.is_empty() {
        units += fraction_text
            .parse::<i128>()
            .map_err(|_| format!("小数部分过大 `{raw}`"))?;
    }

    if negative {
        units = -units;
    }
    Ok(units)
}

fn format_minor_units(value: i128, scale: u32) -> String {
    let factor = 10_i128.pow(scale);
    let sign = if value < 0 { "-" } else { "" };
    let abs = value.abs();
    let integer = abs / factor;
    let fraction = abs % factor;
    format!("{sign}{integer}.{fraction:0width$}", width = scale as usize)
}

fn format_basis_points(value: u16) -> String {
    format!("{}.{:02}%", value / 100, value % 100)
}

#[cfg(test)]
mod migration_batch_validation_tests {
    use super::*;

    fn row(cells: &[(&str, &str)]) -> LegacyRow {
        let mut r = LegacyRow::new();
        for (k, v) in cells {
            r.insert((*k).to_string(), Some((*v).to_string()));
        }
        r
    }

    fn sample_batch(rows: Vec<LegacyRow>) -> LegacyBatch {
        LegacyBatch {
            source: LegacySource::Main(Platform::Yahoo),
            offset: 0,
            rows,
        }
    }

    fn normalize(batch: &LegacyBatch) -> Vec<NormalizedRecord> {
        normalize_legacy_batch(batch)
    }

    fn context(batch: &LegacyBatch) -> MigrationBatchContext {
        MigrationBatchContext::from_batch(batch)
    }

    fn valid_row(id: &str, order_id: &str, item_code: &str, total: &str) -> LegacyRow {
        row(&[
            (LEGACY_ORDER_KEY, id),
            ("orderId", order_id),
            ("ItemId", item_code),
            ("totalItemPrice", total),
            ("postagePrice", "0"),
            ("totalPrice", total),
            ("amount", total),
        ])
    }

    #[test]
    fn validate_batch_accepts_valid_records_and_foreign_keys() {
        let batch = sample_batch(vec![valid_row("1", "Y-1", "SKU-1", "10.00")]);
        let records = normalize(&batch);
        let fk = MigrationForeignKeySnapshot {
            order_ids: vec![100],
            order_item_ids: vec![200],
            refs: vec![
                MigrationForeignKeyRef {
                    table: "order_items",
                    record_index: 0,
                    order_id: Some(100),
                    order_item_id: None,
                },
                MigrationForeignKeyRef {
                    table: "order_logs",
                    record_index: 0,
                    order_id: Some(100),
                    order_item_id: Some(200),
                },
            ],
        };

        let summary = validate_migration_batch(
            &context(&batch),
            &batch.rows,
            &records,
            Some(&fk),
            &MigrationBatchValidationConfig::default(),
        )
        .expect("valid batch should pass");

        assert_eq!(summary.legacy_rows, 1);
        assert_eq!(summary.order_items, 1);
        assert_eq!(summary.source_distribution.pending, 1);
    }

    #[test]
    fn validate_batch_reports_row_count_mismatch() {
        let batch = sample_batch(vec![valid_row("1", "Y-1", "SKU-1", "10.00")]);
        let records = Vec::new();

        let failure = validate_migration_batch(
            &context(&batch),
            &batch.rows,
            &records,
            None,
            &MigrationBatchValidationConfig::default(),
        )
        .expect_err("row count mismatch should fail");

        assert!(failure
            .samples
            .iter()
            .any(|s| s.kind == MigrationValidationKind::RowCount));
    }

    #[test]
    fn validate_batch_reports_amount_mismatch() {
        let batch = sample_batch(vec![valid_row("1", "Y-1", "SKU-1", "10.00")]);
        let mut records = normalize(&batch);
        records[0].order.total_price = "9.99".to_string();

        let failure = validate_migration_batch(
            &context(&batch),
            &batch.rows,
            &records,
            None,
            &MigrationBatchValidationConfig::default(),
        )
        .expect_err("amount mismatch should fail");

        assert!(failure.samples.iter().any(
            |s| s.kind == MigrationValidationKind::Amount && s.message.contains("total_price")
        ));
    }

    #[test]
    fn validate_batch_reports_required_field_sample() {
        let batch = sample_batch(vec![valid_row("1", "Y-1", "SKU-1", "10.00")]);
        let mut records = normalize(&batch);
        records[0].item.item_code.clear();

        let failure = validate_migration_batch(
            &context(&batch),
            &batch.rows,
            &records,
            None,
            &MigrationBatchValidationConfig::default(),
        )
        .expect_err("empty item_code should fail");

        let sample = failure
            .samples
            .iter()
            .find(|s| s.kind == MigrationValidationKind::RequiredField)
            .expect("required field sample");
        assert_eq!(sample.legacy_key.as_deref(), Some("1"));
        assert_eq!(sample.platform_order_id.as_deref(), Some("Y-1"));
    }

    #[test]
    fn validate_batch_reports_source_distribution_out_of_range() {
        let batch = sample_batch(vec![valid_row("1", "Y-1", "SKU-1", "10.00")]);
        let records = normalize(&batch);
        let config = MigrationBatchValidationConfig {
            source_distribution: SourceDistributionPolicy {
                cn_purchase: SourceRatioRange::any(),
                jp_stock: SourceRatioRange::any(),
                pending: SourceRatioRange::new(0, 0),
            },
            ..Default::default()
        };

        let failure =
            validate_migration_batch(&context(&batch), &batch.rows, &records, None, &config)
                .expect_err("pending ratio should be outside configured range");

        assert!(failure
            .samples
            .iter()
            .any(|s| s.kind == MigrationValidationKind::SourceDistribution));
    }

    #[test]
    fn validate_batch_reports_foreign_key_miss() {
        let batch = sample_batch(vec![valid_row("1", "Y-1", "SKU-1", "10.00")]);
        let records = normalize(&batch);
        let fk = MigrationForeignKeySnapshot {
            order_ids: vec![100],
            order_item_ids: vec![200],
            refs: vec![MigrationForeignKeyRef {
                table: "purchases",
                record_index: 0,
                order_id: None,
                order_item_id: Some(999),
            }],
        };

        let failure = validate_migration_batch(
            &context(&batch),
            &batch.rows,
            &records,
            Some(&fk),
            &MigrationBatchValidationConfig::default(),
        )
        .expect_err("bad order_item_id reference should fail");

        assert!(failure
            .samples
            .iter()
            .any(|s| s.kind == MigrationValidationKind::ForeignKey
                && s.message.contains("order_item_id=999")));
    }

    #[test]
    fn decimal_parser_is_exact_and_rejects_extra_scale() {
        assert_eq!(parse_decimal_minor_units("1,234.50", 2).unwrap(), 123450);
        assert_eq!(parse_decimal_minor_units("-0.01", 2).unwrap(), -1);
        assert!(parse_decimal_minor_units("1.234", 2).is_err());
        assert!(parse_decimal_minor_units("abc", 2).is_err());
        assert_eq!(format_minor_units(123450, 2), "1234.50");
        assert_eq!(format_basis_points(12_34), "12.34%");
    }

    #[test]
    fn failure_samples_are_capped_but_total_failures_count_all() {
        let batch = sample_batch(vec![
            valid_row("1", "", "", "10.00"),
            valid_row("2", "", "", "20.00"),
        ]);
        let records = normalize(&batch);
        let config = MigrationBatchValidationConfig {
            max_failure_samples: 1,
            ..Default::default()
        };

        let failure =
            validate_migration_batch(&context(&batch), &batch.rows, &records, None, &config)
                .expect_err("empty required fields should fail");

        assert!(failure.total_failures > 1);
        assert_eq!(failure.samples.len(), 1);
    }
}
