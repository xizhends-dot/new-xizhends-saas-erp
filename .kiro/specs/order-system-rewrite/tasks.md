# Implementation Plan: 西阵订单系统重写（order-system-rewrite）

## Overview

按设计文档 `design.md` 的分层架构，用 **Rust + Axum + Tera + SQLx + tokio** 自底向上实现多租户订单 SaaS。任务顺序遵循依赖：基础骨架 → 数据库 schema → 多租户连接池 → 认证会话 → 权限引擎 → 平台与统一订单模型 → 前端三视图 → 超管后台 → 后台能力与定时任务 → 统计/邮件/搜索 → 数据迁移 → 集成 wiring。

每个任务均为具体编码动作（写哪些文件/函数/测试），可由子代理独立执行。设计含 `## Correctness Properties`（Property 1–10），相应任务安排 `proptest` 属性测试子任务，并以 `*` 标记为可选。每个属性测试标注其 **Property 编号** 与 **Validates: Requirements 编号**。

> 约定：标 `*` 的子任务为可选测试任务，可跳过以加速 MVP；不带 `*` 的子任务必须实现。SQLx 对租户库一律用运行时 `query_as` + 显式 SQL（租户库编译期不存在，无法用 `query!` 宏）。

## Tasks

- [x] 1. 基础层：工程脚手架与核心骨架
  - [x] 1.1 初始化 Cargo 工程与依赖
    - 创建 `Cargo.toml`，加入 `axum`、`tokio`（rt-multi-thread/macros）、`tera`、`sqlx`（mysql/runtime-tokio/macros/chrono/json）、`serde`/`serde_json`、`dashmap`、`argon2`、`tracing`、`anyhow`/`thiserror`、`async-trait`、`proptest`（dev-dep）
    - 创建 `src/main.rs` 启动骨架（占位 `tokio::main`），按 `design.md` 2.3 建立目录树（`config.rs`/`error.rs`/`state.rs`/`middleware/`/`models/`/`repository/`/`services/`/`handlers/`/`integrations/`/`jobs/`/`db/`/`templates/`）的空模块文件
    - _Requirements: 8.1_

  - [x] 1.2 实现 AppConfig
    - 在 `src/config.rs` 定义 `AppConfig`（监听地址、主库 DSN、会话密钥、连接池参数 max_conns_per_tenant/idle_ttl、外部 API 凭证路径），从环境变量/配置文件加载并校验
    - _Requirements: 1.7_

  - [x] 1.3 实现统一 AppError + IntoResponse
    - 在 `src/error.rs` 定义 `AppError` 枚举（`TenantUnavailable`、`PoolBuildFailed`、`Unauthorized`、`Forbidden`、`NotFound`、`Validation(String)`、`ExternalApi{provider,detail}`、`Db(sqlx::Error)`、`MigrationBatchFailed{batch_id}`）
    - 实现 `IntoResponse`：映射状态码，区分普通请求与 HTMX 请求呈现；敏感细节（DSN/密码/栈）只记日志不外泄
    - _Requirements: 4.6_

  - [x] 1.4 实现 AppState
    - 在 `src/state.rs` 定义 `AppState`（主库 `MySqlPool` + `TenantPoolManager` + `Tera` + `AppConfig`），实现构造与 `axum` 共享（`Arc`）
    - _Requirements: 1.3_

- [x] 2. 数据层：数据库 schema 与迁移脚本
  - [x] 2.1 主库 schema 迁移脚本
    - 编写主库 SQLx 迁移：`platforms`（code/name/sort_order/default_enabled）、`tenant_platform`（tenant_id/platform_code/enabled/locked）、`tenants`（company_name/subdomain/db_dsn_enc/plan/status/staff_count/created_at）、`admins`、`sessions`（principal_kind/principal_id/tenant_id/token/created_at/last_seen_at/expires_at/ip/user_agent）、系统公告表（标题/类型/可见范围/内容）
    - _Requirements: 7.2, 10.3, 2.2_

  - [x] 2.2 租户库 schema 迁移脚本
    - 编写租户库 SQLx 迁移：`orders`、`order_items`（含 source_type/purchase_status/item_code/jp_warehouse_id/caigou_user）、`purchases`、`jp_shipments`、`domestic_shipments`、`intl_shipments`、`order_logs`、`stores`、`users`（is_company_admin/role/permissions JSON/dpqz/dpquancheng/password_hash），按 `order_item_id` 建外键
    - _Requirements: 8.1, 8.2, 8.3_

  - [x] 2.3 SQLx 迁移 runner
    - 在 `src/db/migrate.rs`（或独立模块）实现主库迁移执行器与租户库初始化（建空库时套用租户库 schema）
    - _Requirements: 1.3_

- [x] 3. 多租户连接池与租户识别中间件
  - [x] 3.1 实现 TenantPoolManager
    - 在 `src/db/pool.rs` 实现 `TenantPoolManager`（`master` 池 + `DashMap<TenantId, CachedPool>` + `max_conns_per_tenant` + `idle_ttl`）、`TenantDsn`、`pool_for`（缓存命中刷新 last_used；未命中查主库取 DSN→建池→双重检查写缓存）、`build_pool`、`evict_idle`、`invalidate`
    - 实现 `tenant_repo::load_active_dsn`（仅访问主库，状态校验）
    - _Requirements: 1.3, 1.4, 1.5, 1.6, 1.7_

  - [x]* 3.2 编写 TenantPoolManager 单元测试
    - 测试缓存复用刷新 last_used、空闲回收 evict_idle、禁用/DSN 变更 invalidate
    - _Requirements: 1.4, 1.5, 1.6_

  - [x] 3.3 实现 TenantMiddleware
    - 在 `src/middleware/tenant.rs` 由 Host（子域名/自定义域名）解析 tenant_id，调用 `pool_for` 构造 `TenantContext`（tenant_id/pool/company_name）注入请求扩展；无法匹配启用租户时返回 `TenantUnavailable`
    - _Requirements: 1.1, 1.2_

- [x] 4. 检查点 - 确保基础层、数据层、多租户连接池通过测试
  - Ensure all tests pass, ask the user if questions arise.

- [x] 5. 认证与会话
  - [x] 5.1 实现 Argon2 密码哈希模块
    - 在 `src/services/auth_service.rs`（或 `auth/`）实现 `hash_password`（Argon2id）与 `verify_password`
    - _Requirements: 2.1_

  - [x] 5.2 实现 sessions 会话表 repository
    - 实现会话 repository：插入高熵随机 token 会话、按 token 查询、刷新 last_seen_at、过期/吊销判定、按主体吊销全部会话
    - _Requirements: 2.2, 2.6, 2.7, 2.9_

  - [x] 5.3 实现认证服务与三类主体登录
    - 实现登录：超管后台域查主库 `admins` 建 `SuperAdmin` 会话；租户子域名查租户库 `users`，按 `is_company_admin` 区分 `CompanyAdmin`/`Employee`；校验通过插入会话、下发 HttpOnly+Secure+SameSite Cookie；失败计数限流
    - _Requirements: 2.2, 2.3, 2.4, 2.8_

  - [x] 5.4 实现旧明文首登重哈希
    - 旧明文校验通过后即时 Argon2id 重哈希写入新列并清除明文，保证原明文失效
    - _Requirements: 2.5_

  - [x] 5.5 实现 SessionMiddleware
    - 在 `src/middleware/session.rs` 查 `sessions` 表，未过期刷新 last_seen_at 并加载 `Principal` 注入上下文；token 无效/过期/吊销则清 Cookie 要求重登
    - _Requirements: 2.6, 2.7_

  - [x]* 5.6 编写认证与会话单元/集成测试
    - 测试登录建会话→携带 Cookie 访问→登出/过期/吊销被拒；旧明文首登重哈希后明文失效；停用租户/禁用员工吊销会话
    - _Requirements: 2.4, 2.5, 2.9_

- [x] 6. 权限引擎与权限中间件
  - [x] 6.1 实现权限类型与 default_permissions
    - 在 `src/models/user.rs`/权限模块定义 `FeatureModule`（14 项）、`DataAction`、`StoreScope`、`Role`、`Principal`；实现 `default_permissions(role)` 忠实复刻 `get_default_permissions`（采购/客服/品检默认集合）
    - _Requirements: 4.2, 4.5_

  - [x] 6.2 实现 can_access
    - 实现 `Principal::can_access`：SuperAdmin/CompanyAdmin 短路 true；Employee 按 `overrides` 显式值优先，未设置回退角色默认
    - _Requirements: 4.2, 4.5_

  - [x]* 6.3 编写 can_access 属性测试
    - **Property 6: 权限解析回退**
    - **Validates: Requirements 4.2**

  - [x] 6.4 实现 visible_stores
    - 实现 `Principal::visible_stores`：店铺范围过滤（All/Restricted）与隐藏店铺集合求交集
    - _Requirements: 4.3_

  - [x]* 6.5 编写 visible_stores 属性测试
    - **Property 10: 店铺可见性 = 范围 ∩ 非隐藏**
    - **Validates: Requirements 4.3**

  - [x] 6.6 实现 can_operate 与租户隔离守卫
    - 实现 `can_operate(store_id, action)`；在 repository/service 层强制注入 `WHERE store_id IN (...)` 与租户隔离（非超管命中行 tenant_id == 会话 tenant_id，跨租户返回 403）
    - _Requirements: 4.1, 4.4_

  - [x]* 6.7 编写租户隔离属性测试
    - **Property 3: 租户数据隔离**
    - **Validates: Requirements 4.1**

  - [x] 6.8 实现 PermissionMiddleware
    - 在 `src/middleware/permission.rs` 提供页面级守卫：校验失败时普通请求返回 302 跳转、HTMX 请求返回 403 错误片段
    - _Requirements: 4.6_

- [x] 7. 检查点 - 确保认证会话与权限引擎通过测试
  - Ensure all tests pass, ask the user if questions arise.

- [x] 8. 平台与统一订单模型
  - [x] 8.1 实现 Platform 枚举与 item_code_field
    - 在 `src/models/platform.rs` 定义 `Platform`（y/r/w/m/q/yp）与元数据；实现 `Platform::item_code_field()`（y/r=ItemId、w/q=itemCode、m/yp=lotnumber）
    - _Requirements: 8.4_

  - [x] 8.2 实现订单领域模型与 purchase_status 枚举
    - 在 `src/models/order.rs` 定义 `Order` 聚合 + `OrderItem`/`Purchase`/`JpShipment`/`DomesticShipment`/`IntlShipment`；定义 `SourceType`（cn_purchase/jp_stock/pending）与 `PurchaseStatus` 流程枚举（不含「精品/日本库存」等货源语义）
    - _Requirements: 8.1, 8.2, 8.3, 3.2_

  - [x]* 8.3 编写 purchase_status 枚举属性测试
    - **Property 4: 采购状态不含已废弃货源语义**
    - **Validates: Requirements 3.2**

  - [x] 8.4 实现每平台 import mapper
    - 在 `src/services/import` 为 6 平台各实现映射器：Ship 系(y/r)/sender 系(w/m/q/yp) 共有语义→规范列（含地址拼接、商品编码归一），系统自有 16 列 1:1 直映，独有列入 `platform_extra` JSON（键名保留原名），平台区分仅靠 `platform` 列（见 design 3.3.1）
    - _Requirements: 8.4, 8.5_

  - [x]* 8.5 编写 import mapper 单元测试
    - 测试两命名家族→同一组规范列、独有列入 platform_extra、地址拼接、编码归一
    - _Requirements: 8.4, 8.5_

  - [x] 8.6 实现 order repository
    - 在 `src/repository/order_repo.rs` 用 `query_as` 实现订单聚合及子表的读写（按 order_item_id 关联），含 `get`/`set_caigou_user`/`find_pending_intl`/`update_jpship` 等
    - _Requirements: 8.1, 8.3_

  - [x] 8.7 实现 predict_source 货源预判
    - 在 `src/services/order_service.rs` 实现 `predict_source`：命中日本仓现货→jp_stock；未命中可采购→cn_purchase；信息不足→pending；写一条「货源判定」`order_logs`
    - _Requirements: 3.3, 3.4_

  - [x]* 8.8 编写 predict_source 属性测试
    - **Property 8: 货源预判结果完备**
    - **Validates: Requirements 3.3**

  - [x] 8.9 实现 order service 履约分流路由
    - 实现按 `source_type` 分流：仅 cn_purchase 入采购队列、仅 jp_stock 入日本仓发货队列；实现货源改判（更新 source_type + 写改判审计日志）
    - _Requirements: 3.1, 3.5_

  - [x]* 8.10 编写货源分流互斥属性测试
    - **Property 1: 货源分流互斥**
    - **Validates: Requirements 3.1**

  - [x] 8.11 实现 caigou_user 赋值规则
    - 在 `src/services/purchase_service.rs` 实现 `on_save_purchase`：首次写入 tabaono 且 caigou_user 为空时写入当前用户（仅一次，后续不覆盖）；状态变「国内采购-准备/已采购」时 upsert caigou_record
    - _Requirements: 6.1, 6.2_

  - [x]* 8.12 编写 caigou_user 属性测试
    - **Property 2: caigou_user 一次性赋值不可覆盖**
    - **Validates: Requirements 6.1**

  - [x] 8.13 实现多运单号拆分 split_ship_numbers
    - 实现逗号分隔运单号拆分（去空段），每个非空运单号生成一条 `domestic_shipments`
    - _Requirements: 5.1_

  - [x]* 8.14 编写多运单号拆分属性测试
    - **Property 5: 多运单号拆分一一对应**
    - **Validates: Requirements 5.1**

- [x] 9. 检查点 - 确保平台与统一订单模型通过测试
  - Ensure all tests pass, ask the user if questions arise.

- [x] 10. 前端三视图（Tera + HTMX/Alpine）
  - [x] 10.1 实现 Tera layout 与共享订单块模板
    - 在 `src/templates/` 实现 `layout.html` 与共享订单块（A 区客户信息整单共享 / B1 商品明细每子商品一行 / B2 采购或出库 / C 国际物流），含操作日志可折叠面板、多子订单逐行重复规则
    - _Requirements: 9.1, 9.3, 9.4_

  - [x] 10.2 实现三视图 handler 与货源过滤
    - 在 `src/handlers/order_list.rs` 实现平台订单（不过滤）/采购订单（过滤 cn_purchase）/日本仓发货（过滤 jp_stock）三视图共用订单块；无符合货源子商品的订单块不渲染
    - _Requirements: 9.1, 9.2_

  - [x] 10.3 实现筛选与列表交互区
    - 默认显示常用搜索区、折叠高级搜索区；平台视图筛选含货源地下拉（全部/日本仓/国内采购）；列表工具条（全选/展开详情/批量改状态/批量分配/批量删除按权限）
    - _Requirements: 9.5_

  - [x] 10.4 实现日本仓发货工作流视图
    - 列出 jp_stock 子商品，B2 区呈现出库信息；按 out_status（待分配/已分配/已出库/已发货）推进，支持按发货员分配/批量出库，未分配行高亮
    - _Requirements: 9.6_

  - [x] 10.5 实现货源改判 handler
    - 在 `src/handlers/order_save.rs` 实现平台订单视图 B1 区货源下拉改判，调用 order service 改判并返回 HTMX 片段
    - _Requirements: 3.5_

  - [x]* 10.6 编写三视图渲染单元/集成测试
    - 测试三视图货源过滤、空货源订单块不渲染、B2 区按货源显隐
    - _Requirements: 9.1, 9.2, 9.4_

- [x] 11. 超管后台
  - [x] 11.1 实现租户管理 CRUD
    - 在 `src/handlers/admin/` 实现租户列表（公司/子域名/数据库/套餐/平台授权标签/员工数/状态/创建时间）与新建/编辑/停用启用（写主库 `tenants`），停用置 status='suspended' 并失效连接池
    - _Requirements: 10.2, 7.4_

  - [x] 11.2 实现平台授权服务与 upsert
    - 实现 `Platform_Auth_Service`：按 `tenant_platform` 计算侧栏渲染三态（enabled=false 不显示 / enabled+locked 灰显锁定 / enabled+!locked 正常；suspended 全部不可用）；超管切换开关按租户 upsert，改动即时生效；以 `platforms` 为目录全集与排序
    - _Requirements: 7.1, 7.2, 7.3_

  - [x]* 11.3 编写平台菜单渲染属性测试
    - **Property 7: 平台菜单渲染由授权严格决定**
    - **Validates: Requirements 7.1**

  - [x] 11.4 实现系统公告
    - 实现发布全局/指定租户公告（标题/类型/可见范围/内容）写主库公告表与已发布列表
    - _Requirements: 10.3_

  - [x] 11.5 实现概览页与橙色身份界面
    - 实现概览（租户数/活跃租户/员工总数/平台授权数/最近租户列表/系统运行状态聚合）；超管后台用橙色身份色区分
    - _Requirements: 10.1, 10.4_

- [x] 12. 检查点 - 确保前端三视图与超管后台通过测试
  - Ensure all tests pass, ask the user if questions arise.

- [x] 13. 后台能力抽象与外部集成实现
  - [x] 13.1 定义 integration traits
    - 在 `src/integrations/` 定义 `PurchaseProvider`、`PlatformOAuth`、`CarrierTracker`（含 `TrackResult`）、`MailGateway`（含 `SyncReport`/`ReplyResult`）trait 契约
    - _Requirements: 6.3, 11.6_

  - [x] 13.2 实现 PurchaseProvider（1688）
    - 实现 `query_logistics`/`fetch_product`，凭证轮换（多组 key|token|username）+ 有界重试，失败记样本不中断整批
    - _Requirements: 6.3, 6.4_

  - [x] 13.3 实现 CarrierTracker（佐川/日本邮政/大和）
    - 实现 `detect_carrier`（运单号前缀识别）与 `track`，返回 status 与 completed_date
    - _Requirements: 5.3, 11.6_

  - [x]* 13.4 编写 detect_carrier 单元测试
    - 测试佐川/日本邮政/大和及未知前缀的识别
    - _Requirements: 5.3_

  - [x] 13.5 实现 PlatformOAuth（Yahoo/乐天 RMS/Wowma）
    - 实现 `authorize_url`/`exchange_code`/`refresh`
    - _Requirements: 6.3_

  - [x] 13.6 实现 MailGateway（IMAP/SMTP）
    - 实现 `list_folders`/`sync_folder`（增量只拉头，last_uid 游标）/`load_body`（懒加载缓存回库）/`reply`（SMTP 发送 + IMAP APPEND Sent）
    - _Requirements: 11.5_

- [x] 14. 定时任务调度器与 9 个任务
  - [x] 14.1 实现 Job_Scheduler（多租户 + 分布式锁）
    - 在 `src/jobs/scheduler.rs` 用 tokio 定时器对每个启用租户取连接池循环执行；分布式锁避免多实例重复；单租户失败隔离继续其余
    - _Requirements: 11.1, 11.7_

  - [x] 14.2 实现 update_1688_logistics（含禁运窗口）
    - 调用 PurchaseProvider 更新 1688 物流；00:00–08:00 直接跳过且不计为错误；支持 --platform/--limit/--delay/--1688id
    - _Requirements: 11.2, 6.3, 6.4_

  - [x] 14.3 实现 update_jpship_logistics
    - 复刻 `run_platform`：状态过滤（已发货代订单/已发日本/已发出荷通知，乐天追加「日本仓库已处理」）；多运单号拆分逐一查询；有状态结果随机取一；命中「配達完了/お客様引渡完了」且 jpship_completed_at 为空时写入完成时间（已有值不变）
    - _Requirements: 5.2, 5.4, 5.5_

  - [x]* 14.4 编写配達完了时间幂等属性测试
    - **Property 9: 配達完了时间仅首次写入**
    - **Validates: Requirements 5.2**

  - [x] 14.5 实现 order_monitor（编码字段 + 游标）
    - 按平台用对应商品编码字段（y/r=ItemId、w/q=itemCode、m/yp=lotnumber）从历史同编码订单回填 caigoulink/material/tranship_comment；以进度游标文件记录处理位置
    - _Requirements: 11.3_

  - [x] 14.6 实现 order_archive
    - 按 `YEAR(imported_at)` 分批（500 条）归档到 `orders_{year}` 并删除源记录
    - _Requirements: 11.4_

  - [x] 14.7 实现 mail_sync
    - 各店铺邮箱增量只拉邮件头；last_uid=0 时仅取最近 N 封避免超时；支持 --account/--limit/--debug
    - _Requirements: 11.5_

  - [x] 14.8 实现其余四任务（zhutu_downloader/caigou_status_stats/cleanup_old_images/daily_maintain）
    - 主图下载按日期目录；采购状态统计快照；清理过期图（含 dry-run 变体）；日常维护聚合并触发连接池空闲回收
    - _Requirements: 11.1_

- [x] 15. 统计、客服邮件中心与全局搜索
  - [x] 15.1 实现采购统计与利润核算
    - 在 `src/services/stats_service.rs` 实现 caigou_stats / 采购状态统计 / 利润核算口径
    - _Requirements: 6.2_

  - [x] 15.2 实现客服邮件中心 handler
    - 在 `src/handlers/mail.rs` 实现文件夹/邮件列表、正文懒加载、回复发送，调用 MailGateway
    - _Requirements: 11.5_

  - [x] 15.3 实现全局搜索
    - 在 `src/services/search_service.rs` + handler 实现跨订单/子商品/采购/物流的全局搜索（叠加店铺范围与租户隔离）
    - _Requirements: 4.1_

- [x] 16. 检查点 - 确保后台能力、定时任务、统计/搜索通过测试
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 17. 数据迁移工具
  - [x] 17.1 实现迁移工具骨架与抽取
    - 在 `src/db/migrate.rs` 实现按平台逐表只读抽取旧 `ph_order{y,r,w,m,q,yp}` 及归档表（全程只读旧库）
    - _Requirements: 12.1_

  - [x] 17.2 实现规范化拆分与编码归一
    - 复用 8.4 import mapper 把每条旧宽表记录拆为 1 条 orders + 1 条 order_items + 按需子表；按平台选旧编码列归一写 item_code；beizhu_log→order_logs（解析失败入 legacy_log）；多运单号拆分
    - _Requirements: 12.1, 12.2_

  - [x] 17.3 实现货源回填
    - 旧 beizhu 含「精品/日本库存」→ jp_stock 并剥离货源语义；否则按日本仓 ID 命中→jp_stock、未命中→cn_purchase；无法判定→pending；每条回填写一条「货源回填」迁移审计日志
    - _Requirements: 12.3_

  - [x] 17.4 实现分批校验与回滚
    - 分批（500 条）事务包裹，逐批做行数对账/金额对账/关键字段非空/货源分布占比/外键完整校验；失败整批回滚并记失败样本，旧库只读不变
    - _Requirements: 12.4, 12.5_

  - [ ]* 17.5 编写迁移对账集成测试
    - 用脱敏旧库样本跑迁移，校验五类对账并验证失败批次回滚后旧库不变
    - _Requirements: 12.4, 12.5_

- [ ] 18. 集成与 wiring
  - [x] 18.1 装配路由与中间件栈
    - 在 `main.rs` 挂载所有 handler 路由，按「租户识别 → 会话认证 → 权限校验」顺序组装中间件栈，注入 AppState
    - _Requirements: 1.1, 2.6, 4.6_

  - [x] 18.2 装配调度器启动
    - 在 `main.rs` 启动 Job_Scheduler，注册 9 个定时任务与连接池空闲回收
    - _Requirements: 11.1_

  - [ ]* 18.3 编写端到端集成测试
    - 多租户路由物理隔离（A 租户查不到 B 租户、越权 403）；登录→访问→登出全链路；权限受限店铺范围下列表只返回授权店铺（HTMX 与整页两种拒绝分支）
    - _Requirements: 1.1, 4.1, 4.6_

- [ ] 19. 最终检查点 - 确保全部测试通过
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- 标 `*` 的子任务为可选测试任务，可跳过以加速 MVP；核心实现任务不标 `*`。
- 每个任务引用具体 Requirements 子条款以保证可追溯性。
- 属性测试（proptest）覆盖 design 的 Property 1–10，紧邻对应实现任务以尽早发现错误。
- 检查点（4/7/9/12/16/19）用于增量验证。
- 租户库查询统一用 `query_as` + 显式 SQL；外部 API（1688/RMS/jpship/佐川/ShowAPI/IMAP-SMTP）在 CI 以 mock/fixture 覆盖。

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1"] },
    { "id": 1, "tasks": ["1.2", "1.3", "2.1", "2.2"] },
    { "id": 2, "tasks": ["2.3", "3.1", "5.1", "6.1", "8.1"] },
    { "id": 3, "tasks": ["1.4", "3.2", "5.2", "6.2", "6.4", "8.2", "8.13"] },
    { "id": 4, "tasks": ["3.3", "5.3", "5.5", "6.3", "6.5", "6.6", "8.3", "8.4", "8.6", "8.7", "8.14"] },
    { "id": 5, "tasks": ["5.4", "6.7", "6.8", "8.5", "8.8", "8.9", "8.11"] },
    { "id": 6, "tasks": ["5.6", "8.10", "8.12", "10.1", "11.2", "13.1"] },
    { "id": 7, "tasks": ["11.1", "11.3", "11.4", "13.2", "13.3", "13.5", "13.6"] },
    { "id": 8, "tasks": ["10.2", "10.4", "10.5", "11.5", "13.4", "14.1", "15.1", "15.3"] },
    { "id": 9, "tasks": ["10.3", "10.6", "14.2", "14.3", "14.5", "14.6", "14.7", "14.8", "15.2"] },
    { "id": 10, "tasks": ["14.4", "17.1"] },
    { "id": 11, "tasks": ["17.2"] },
    { "id": 12, "tasks": ["17.3"] },
    { "id": 13, "tasks": ["17.4"] },
    { "id": 14, "tasks": ["17.5", "18.1", "18.2"] },
    { "id": 15, "tasks": ["18.3"] }
  ]
}
```
