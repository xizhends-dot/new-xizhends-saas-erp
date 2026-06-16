# Requirements Document

> 需求文档：西阵订单系统重写（order-system-rewrite）

## Introduction

本需求文档由已定稿的设计文档 `design.md` **反推生成**，用于把设计中的技术决策固化为可验证的功能与质量要求。系统目标是将现有无框架 PHP 跨境电商订单系统（xizhends，覆盖 6 个日本电商平台）重写为多租户 SaaS：每家公司（租户）拥有独立数据库，超级管理员统一运营，公司管理员管理自身员工与店铺。技术栈为 Rust + Axum + Tera + HTMX/Alpine.js + SQLx + MySQL。

需求覆盖：多租户基础与连接池、认证与会话、货源地与采购状态解耦、租户隔离与三维权限、物流跟踪、采购管理、平台/租户管理、统一订单数据模型、三视图前端、超管后台、后台能力与定时任务、数据迁移。

> 编号约定：本文 Requirement 3/4/5/6/7 下的验收标准编号与 `design.md` 的 `## Correctness Properties` 中 `**Validates: Requirements X.Y**` 引用一一对应，确保设计到需求的可追溯性。

## Glossary

- **租户（Tenant）**：使用本系统的一家公司，拥有独立的 MySQL 数据库，由主库 `tenants` 表登记。
- **主库（Master_DB）**：存放租户档案、超管账号、平台目录、平台授权与系统公告的全局数据库。
- **租户库（Tenant_DB）**：单个租户独享的业务数据库，存放订单、商品、采购、物流等数据。
- **租户识别中间件（Tenant_Middleware）**：依据请求 Host（子域名/自定义域名）解析 `tenant_id` 并注入租户上下文的组件。
- **连接池管理器（Pool_Manager）**：按需懒创建、缓存、回收、失效各租户数据库连接池的组件（设计中的 `TenantPoolManager`）。
- **会话中间件（Session_Middleware）**：校验会话 Cookie、加载登录主体的组件。
- **认证服务（Auth_Service）**：处理登录、密码哈希校验、会话创建与吊销的组件。
- **权限引擎（Permission_Engine）**：解析「功能模块 × 店铺范围 × 数据操作」三维权限并叠加主体层级的组件（设计中的 `Principal`）。
- **主体（Principal）**：登录身份，分为超级管理员（SuperAdmin）、公司管理员（CompanyAdmin）、员工（Employee）。
- **货源判定器（Source_Predictor）**：导入时按日本仓 ID 预判子商品货源地 `source_type` 的组件（设计中的 `predict_source`）。
- **订单服务（Order_Service）**：负责订单/子商品的保存、分流路由、状态流转的领域服务。
- **采购服务（Purchase_Service）**：负责 1688 采购、采购人赋值、采购记录的领域服务。
- **物流跟踪器（Carrier_Tracker）**：识别承运商、查询并更新物流状态的组件（设计中的 `CarrierTracker`）。
- **平台授权服务（Platform_Auth_Service）**：依据 `tenant_platform` 计算租户侧栏平台菜单显隐与锁定的组件。
- **迁移工具（Migration_Tool）**：把旧单库 6 张镜像宽表迁移到租户库统一模型的工具（设计中的 `db/migrate.rs`）。
- **调度器（Job_Scheduler）**：按 cron 语义对所有启用租户循环执行后台任务的组件。
- **子商品（Order_Item）**：订单下的单条商品明细，独立持有货源地、采购/出库状态、采购物流与国际物流。
- **货源地（source_type）**：子商品履约维度，取值 `cn_purchase`（国内采购）/ `jp_stock`（日本仓现货）/ `pending`（待定）。
- **采购状态（purchase_status）**：子商品流程进度枚举，已与货源语义解耦。
- **隐藏店铺（Hidden_Store）**：被全局配置标记为不可见的店铺。

## Requirements

### Requirement 1: 多租户基础与连接池

**User Story:** 作为平台运营者，我希望系统按子域名识别租户并为每个租户使用独立数据库与连接池，以便实现租户间的物理隔离与资源可控。

#### Acceptance Criteria

1. WHEN 收到一个携带 Host 的 HTTP 请求，THE 租户识别中间件 SHALL 依据子域名或自定义域名从主库解析出对应的 `tenant_id`。
2. IF 请求 Host 无法在主库匹配到启用状态的租户，THEN THE 租户识别中间件 SHALL 拒绝该请求并返回租户不可用响应。
3. WHEN 首次访问某个启用租户，THE 连接池管理器 SHALL 从主库读取该租户的加密 DSN、解密后创建连接池并写入缓存。
4. WHEN 后续请求命中已缓存的租户连接池，THE 连接池管理器 SHALL 直接复用缓存的连接池并刷新其 `last_used` 时间戳。
5. WHILE 某租户连接池空闲时长超过设定的空闲阈值，THE 连接池管理器 SHALL 关闭并移除该连接池。
6. WHEN 某租户被禁用、删除或其 DSN 发生变更，THE 连接池管理器 SHALL 立即失效该租户的连接池缓存。
7. THE 连接池管理器 SHALL 为每个租户连接池限制最大连接数不超过配置值。

### Requirement 2: 认证与会话

**User Story:** 作为系统用户，我希望使用安全的密码哈希与服务端会话登录，以便我的账号与登录态不被仿冒。

#### Acceptance Criteria

1. WHEN 用户注册或修改密码，THE 认证服务 SHALL 使用 Argon2id 算法生成密码哈希并存储该哈希。
2. WHEN 用户提交登录凭证，THE 认证服务 SHALL 使用 Argon2 校验口令，校验通过后在 `sessions` 表插入一条带高熵随机 token 的会话记录。
3. WHEN 会话创建成功，THE 认证服务 SHALL 下发设置了 HttpOnly、Secure 与 SameSite 属性的安全 Cookie。
4. IF 用户提交的登录凭证校验失败，THEN THE 认证服务 SHALL 拒绝登录并对失败尝试进行计数限流。
5. WHEN 用户以旧明文密码首次登录且校验通过，THE 认证服务 SHALL 即时用 Argon2id 重新哈希该密码并使原明文失效。
6. WHEN 已登录请求携带会话 Cookie，THE 会话中间件 SHALL 查询 `sessions` 表，对未过期会话刷新 `last_seen_at` 并加载对应主体。
7. IF 会话 token 无效、已过期或已被吊销，THEN THE 会话中间件 SHALL 清除 Cookie 并要求重新登录。
8. WHERE 登录请求来自超管后台域，THE 认证服务 SHALL 在主库 `admins` 表校验并建立超级管理员会话；WHERE 登录请求来自租户子域名，THE 认证服务 SHALL 在该租户库 `users` 表校验并按 `is_company_admin` 区分公司管理员与员工会话。
9. WHEN 超管停用某租户或公司管理员禁用某员工，THE 认证服务 SHALL 吊销相关主体的全部会话。

### Requirement 3: 货源地与采购状态解耦

**User Story:** 作为客服人员，我希望每个子商品独立持有与采购状态解耦的货源地，以便不同子商品按各自的履约路径正确分流。

#### Acceptance Criteria

1. THE 订单服务 SHALL 依据子商品的 `source_type` 进行履约分流，且仅将 `source_type` 为 `cn_purchase` 的子商品纳入采购订单队列、仅将 `source_type` 为 `jp_stock` 的子商品纳入日本仓发货队列。
2. THE 订单服务 SHALL 仅允许 `order_items.purchase_status` 取自流程进度枚举（待处理、国内采购-准备、国内采购--问题、国内采购-已采购、国内采购-TB/PDD已采购、发货中、已到货、已发货代订单、已发日本、已发出荷通知、已到货问题件、问题订单(后台处理)、已取消），且该枚举不包含任何货源语义取值（如「精品」「日本库存」）。
3. WHEN 导入一个子商品，THE 货源判定器 SHALL 返回 `cn_purchase`、`jp_stock`、`pending` 三者之一：命中日本仓现货时返回 `jp_stock`；未命中现货但可采购时返回 `cn_purchase`；信息不足无法判定时返回 `pending`。
4. WHEN 货源判定器完成一次货源预判，THE 订单服务 SHALL 写入一条操作人为系统、类型为货源判定的 `order_logs` 记录。
5. WHERE 处于平台订单视图，WHEN 客服将某子商品的货源地在 `cn_purchase`、`jp_stock`、`pending` 之间改判，THE 订单服务 SHALL 更新该子商品的 `source_type` 并写入一条货源改判审计日志。

### Requirement 4: 租户隔离与三维权限

**User Story:** 作为公司管理员，我希望权限按「功能模块 × 店铺范围 × 数据操作」三维度并叠加租户隔离生效，以便员工只能访问被授权的数据。

#### Acceptance Criteria

1. IF 非超管主体（公司管理员或员工）发起数据访问，THEN THE 权限引擎 SHALL 确保命中行的 `tenant_id` 等于其会话 `tenant_id`，对跨租户访问返回 403。
2. WHEN 员工访问某功能模块，THE 权限引擎 SHALL 优先采用其 `overrides` 中对该模块的显式取值；WHERE 该模块在 `overrides` 中未显式设置，THE 权限引擎 SHALL 回退到该员工角色的默认权限；超级管理员与公司管理员对一切功能模块恒为允许。
3. THE 权限引擎 SHALL 将主体的可见店铺集合计算为「主体店铺范围允许的店铺」与「非隐藏店铺」的交集。
4. WHEN 主体对某店铺执行查看、编辑或删除操作，THE 权限引擎 SHALL 依据该主体的数据操作权限（View/Edit/Delete）判定是否允许。
5. THE 权限引擎 SHALL 对超级管理员主体短路放行一切功能模块与数据操作。
6. WHEN 权限校验失败，THE 权限引擎 SHALL 对普通请求返回跳转响应、对 HTMX 请求返回 403 错误片段。

### Requirement 5: 物流跟踪

**User Story:** 作为客服人员，我希望系统按运单号识别承运商并自动更新国内与国际物流状态，以便我准确掌握每个子商品的配送进度。

#### Acceptance Criteria

1. WHEN 处理一个逗号分隔的国内运单号字符串，THE 物流跟踪器 SHALL 为其中每个非空运单号生成恰好一条 `domestic_shipments` 记录，且记录数量等于去除空段后的运单号个数。
2. WHEN 物流查询结果命中「配達完了」或「お客様引渡完了」且该子商品的 `jpship_completed_at` 为空，THE 物流跟踪器 SHALL 写入物流完成时间；IF `jpship_completed_at` 已有值，THEN THE 物流跟踪器 SHALL 保持其原值不变。
3. WHEN 需要查询某运单号，THE 物流跟踪器 SHALL 通过运单号前缀识别承运商（佐川、日本邮政、大和）。
4. WHILE 更新国际物流任务运行，THE 物流跟踪器 SHALL 仅处理 `purchase_status` 属于「已发货代订单、已发日本、已发出荷通知」的订单；WHERE 平台为乐天，THE 物流跟踪器 SHALL 额外纳入「日本仓库已处理」状态。
5. WHEN 某子商品存在多个有物流状态结果的运单号，THE 物流跟踪器 SHALL 从有效结果中随机选取一个作为该子商品的物流状态。

### Requirement 6: 采购管理

**User Story:** 作为采购人员，我希望系统在我首次填入 1688 订单号时记录采购人且不再被覆盖，以便采购责任归属清晰可追溯。

#### Acceptance Criteria

1. WHEN 采购员保存订单且首次写入 `tabaono`（1688 订单号）且该子商品 `caigou_user` 当前为空，THE 采购服务 SHALL 将当前登录用户写入 `caigou_user`，且后续任何保存操作 SHALL NOT 覆盖该值。
2. WHEN 子商品 `purchase_status` 变更为「国内采购-准备」或「国内采购-已采购」，THE 采购服务 SHALL 同步写入或更新对应的采购记录（caigou_record）。
3. WHEN 用户查询某 1688 订单的物流，THE 采购服务 SHALL 通过 1688 采购能力查询并返回物流轨迹。
4. IF 1688 采购接口调用失败，THEN THE 采购服务 SHALL 在有界重试与凭证轮换后记录失败样本且不中断整批处理。

### Requirement 7: 平台与租户管理（主库）

**User Story:** 作为超级管理员，我希望通过平台授权开关驱动租户侧栏菜单的渲染，以便精确控制每个租户可用的平台。

#### Acceptance Criteria

1. WHEN 渲染某租户的侧栏平台菜单，THE 平台授权服务 SHALL 依据该租户对应的 `tenant_platform` 记录决定每个平台项：`enabled=false` 时不显示；`enabled=true` 且 `locked=true` 时显示为灰显锁定且不可进入；`enabled=true` 且 `locked=false` 时显示为正常可点；当 `tenants.status='suspended'` 时该租户全部平台项不可用。
2. THE 平台授权服务 SHALL 以主库 `platforms` 表作为 6 个平台（y/r/w/m/q/yp）的目录全集与排序依据。
3. WHEN 超级管理员对某租户的某平台切换开通或锁定开关，THE 平台授权服务 SHALL 按租户对 `tenant_platform` 执行 upsert 且改动即时生效。
4. WHEN 超级管理员新建、编辑或停用/启用租户，THE 平台授权服务 SHALL 在主库 `tenants` 表写入相应变更。

### Requirement 8: 统一订单数据模型

**User Story:** 作为系统架构者，我希望用一套规范化模型取代 6 张镜像宽表，以便统一查询并保留平台差异信息。

#### Acceptance Criteria

1. THE 订单服务 SHALL 以「订单聚合（orders）+ 子表（order_items、purchases、jp_shipments、domestic_shipments、intl_shipments、order_logs）」的规范化结构存储订单数据。
2. THE 订单服务 SHALL 将客户与收件信息（A 区）存储于订单级、由整单共享，将货源地、采购/出库状态、采购物流与国际物流存储于子商品级。
3. THE 订单服务 SHALL 将 `purchases`、`jp_shipments`、`domestic_shipments`、`intl_shipments` 均按 `order_item_id` 关联到对应子商品。
4. WHEN 导入或保存子商品，THE 订单服务 SHALL 依据平台把商品编码字段归一写入 `order_items.item_code`（y/r 取 ItemId、w/q 取 itemCode、m/yp 取 lotnumber）。
5. WHERE 字段为平台特有或低频字段，THE 订单服务 SHALL 将其存入 `orders.platform_extra` 或子商品 JSON 扩展，不污染主表结构。

### Requirement 9: 三视图与前端交互

**User Story:** 作为客服人员，我希望平台订单、采购订单、日本仓发货三个视图共享同一订单块模板并各自按货源过滤，以便用一致的界面处理不同履约阶段。

#### Acceptance Criteria

1. THE 订单服务 SHALL 使三个视图共用同一订单块渲染模板，仅以货源过滤条件与默认展开区相区分：平台订单视图不过滤货源、采购订单视图过滤 `cn_purchase`、日本仓发货视图过滤 `jp_stock`。
2. IF 某订单在某视图下无符合该视图货源的子商品，THEN THE 订单服务 SHALL 不在该视图渲染该订单块。
3. THE 订单服务 SHALL 将订单块渲染为 A 区（客户信息，整单共享一行）、B1 区（商品明细，每子商品一行）、B2 区（采购或出库信息）、C 区（国际物流）四张表，并按视图应用显隐规则。
4. WHERE 子商品货源为 `cn_purchase`，THE 订单服务 SHALL 在 B2 区呈现采购物流信息；WHERE 子商品货源为 `jp_stock`，THE 订单服务 SHALL 在 B2 区呈现出库信息。
5. THE 订单服务 SHALL 默认显示常用搜索区并折叠高级搜索区，且在平台订单视图的筛选中提供货源地下拉（全部/日本仓/国内采购）。
6. WHILE 处于日本仓发货视图，THE 订单服务 SHALL 支持按发货员分配并按出库状态（待分配/已分配/已出库/已发货）推进出库工作流，且对未分配的行进行高亮提示。

### Requirement 10: 超管后台

**User Story:** 作为超级管理员，我希望有独立的运营后台查看概览并管理租户、平台授权与公告，以便统一运营所有租户。

#### Acceptance Criteria

1. WHEN 超级管理员打开概览页，THE 平台授权服务 SHALL 展示租户数、活跃租户数、员工总数、平台授权数及最近租户列表与系统运行状态。
2. WHEN 超级管理员打开租户管理页，THE 平台授权服务 SHALL 展示包含公司名、子域名、数据库、套餐、平台授权标签、员工数、状态与创建时间的租户列表。
3. WHEN 超级管理员发布系统公告，THE 平台授权服务 SHALL 在主库公告表写入含标题、类型、可见范围与内容的公告记录。
4. THE 平台授权服务 SHALL 以橙色身份色界面区分超管后台与租户系统。

### Requirement 11: 后台能力与定时任务

**User Story:** 作为系统运维者，我希望各类后台能力与 9 个定时任务按既有语义对所有租户执行，以便采购、物流、邮件与统计自动化运转。

#### Acceptance Criteria

1. THE 调度器 SHALL 对每个启用租户取其连接池后循环执行后台任务，并使用分布式锁避免多实例重复执行。
2. WHILE 当前时间处于 00:00 至 08:00 之间，THE 调度器 SHALL 跳过 `update_1688_logistics` 任务且不将该跳过计为错误。
3. WHEN `order_monitor` 任务运行，THE 调度器 SHALL 按平台使用对应商品编码字段（y/r=ItemId、w/q=itemCode、m/yp=lotnumber）从历史同编码订单回填 `caigoulink`、`material`、`tranship_comment`，并以进度游标文件记录处理位置。
4. WHEN `order_archive` 任务运行，THE 调度器 SHALL 按 `YEAR(imported_at)` 将订单分批归档到 `orders_{year}` 并在迁移后删除源记录。
5. WHEN `mail_sync` 任务运行，THE 调度器 SHALL 对各店铺邮箱增量只拉取邮件头；WHERE 某邮箱 `last_uid` 为 0，THE 调度器 SHALL 仅取最近 N 封以避免超时。
6. WHEN 用户查询日本物流，THE 物流跟踪器 SHALL 按承运商前缀识别并支持多运单号逐一查询。
7. IF 任一后台任务对某租户执行失败，THEN THE 调度器 SHALL 隔离该失败并继续处理其余租户。

### Requirement 12: 数据迁移

**User Story:** 作为系统迁移负责人，我希望把旧的 6 张镜像宽表安全迁移到统一模型并可校验回滚，以便平滑切换且不丢失数据。

#### Acceptance Criteria

1. WHEN 迁移工具执行迁移，THE 迁移工具 SHALL 把每条旧宽表记录拆分为 1 条 `orders`、1 条 `order_items` 及按需的 `purchases`、`jp_shipments`、`domestic_shipments`、`intl_shipments` 记录。
2. WHEN 迁移商品编码，THE 迁移工具 SHALL 按平台选取旧列（y/r=ItemId、w/q=itemCode、m/yp=lotnumber）归一写入 `order_items.item_code`。
3. WHEN 迁移子商品货源，THE 迁移工具 SHALL 回填 `source_type`：旧 `beizhu` 含「精品/日本库存」语义时回填 `jp_stock` 并剥离该货源语义；否则按日本仓 ID 命中现货回填 `jp_stock`、未命中回填 `cn_purchase`；完全无法判定时回填 `pending`，并为每条回填写入一条迁移审计日志。
4. THE 迁移工具 SHALL 分批执行迁移，每批对行数对账、金额对账、关键字段非空、货源分布占比与外键完整性进行校验。
5. IF 某迁移批次校验失败，THEN THE 迁移工具 SHALL 整体回滚该批次并记录失败样本，且全程只读旧库保持旧库不变。
