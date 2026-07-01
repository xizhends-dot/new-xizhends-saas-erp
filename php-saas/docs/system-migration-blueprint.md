# 西阵订单 SaaS 体系化迁移蓝图

## 目标

新系统不是单页重画，而是把 `/old` 的业务能力迁到一套可维护的 SaaS 架构中：

- 超管侧管理租户、授权、计费、公告、系统状态。
- 租户侧管理订单、采购、日本仓、物流、邮件、利润、员工、店铺、设置。
- Rust SQL 迁移作为正式 MySQL 数据库契约。
- `/old` 保留为线上旧系统和业务参考，不直接改动。

## 数据层

当前开发期：

- `JsonStore`：用 `storage/data/app.json` 跑通页面与业务流。
- `LegacySettingsService`：只读 `old/setting.ini`，把可复用配置展示到新设置页。

正式上线：

- 主库：`migrations/master/*.sql`
- 租户库：`migrations/tenant/*.sql`
- `MysqlStore`：按同一契约替换 JSON 数据源。

## 旧配置迁移

可直接迁入业务设置：

- 公司名称
- 售价预警指数
- 国内快递签收地
- 性能配置：每页数量、查询天数、缓存配置、大数据警告阈值
- 利润计算：汇率、汇率模式、默认运费、平台默认扣点
- 物流编号映射：Yahoo、Rakuten、Wowma、日本快递公司、物流状态查询
- 隐藏店铺清单

必须脱敏或加密：

- 两步验证密码
- ShowAPI Sign
- 轮循代理账号密码
- 1688 AppSecret / AccessToken
- 旧数据库密码

## 页面模块对应

| 新系统模块 | 旧系统来源 | 当前状态 | 下一步 |
|---|---|---|---|
| 平台订单 | `old/ordery|orderr|orderw|orderm|orderq|orderyp` | 页面与四表结构已接 | 补完整字段保存、平台 API 同步 |
| 采购订单 | 6 平台 `inc_list_default.php` + `caigou_status` | 页面已接 | 接采购状态保存、1688 下单信息 |
| 日本仓发货 | 日本仓现货逻辑 + 物流脚本 | 页面已接 | 接分配、出库、发货状态机 |
| 全局搜索 | `plugins/global-search` | 页面已接 | 接跨平台真实查询 |
| 1688 物流 | `plugins/1688api` + `cron/update_1688_logistics.php` | 页面已接 | 接加密凭证和任务队列 |
| 日本物流 | `jpshipinfo` / `sagawa-shipinfo` | 页面已接 | 接承运商映射与轨迹查询 |
| 邮件中心 | `old/kefu_mail` | 页面已接 | 接 IMAP/SMTP 扩展和账号配置 |
| 利润分析 | `plugins/profit-analysis` / `price_calculator.php` | 已读旧汇率配置 | 接订单成本、运费、平台扣点 |
| 采购统计 | `caigou_stats` / `caigou_status` | 页面已接 | 接采购人、状态、业绩口径 |
| 导入导出 | PHPExcel / 各平台导出 | 页面已接 | 接 CSV/XLSX 解析和导出 |
| 图片管理 | 上传 AJAX / `zhutu_downloader.php` | 页面已接 | 接上传、主图缓存、清理任务 |
| 定时任务 | `old/cron` | 页面已接 | 接任务运行记录和手动执行 |
| 店铺管理 | `ph_dianpu` / 平台店铺字段 | 页面已接 | 接隐藏店铺、RMS 凭证、店铺范围 |
| 员工管理 | `ph_user` / 权限函数 | 页面已接 | 接角色权限、店铺范围、密码哈希 |
| 系统设置 | `setting.ini` / `setting.php` | 已展示旧配置 | 接保存、审计、敏感项加密 |

## 开发顺序

1. 订单页面完全对齐 `sample.html`，保证展示不跑偏。
2. 把 `setting.ini` 迁移为结构化配置页面，敏感项只脱敏显示。
3. 补真实保存动作：货源改判、采购状态、发货分配、店铺隐藏、员工权限。
4. 开启 MySQL 环境后，导入 Rust SQL，并实现旧宽表到新规范表的数据迁移脚本。
5. 逐步接外部 API：平台订单同步、1688、ShowAPI、日本物流、邮件。

## 验收标准

- `/old` 不被修改。
- 新系统页面可以覆盖旧系统主要工作流。
- 订单页密集表格与 `sample.html` 一致，不溢出、不错位。
- 设置页能展示旧系统配置来源，并明确哪些可迁移、哪些需要加密。
- PHP 语法检查通过，关键页面 HTTP 200。
