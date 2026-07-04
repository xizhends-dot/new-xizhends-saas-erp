-- ============================================================================
-- 租户库初始 schema（每家租户一个独立 MySQL 数据库）
-- 对应 design.md 3.2 ER 图 / 3.3 字段映射 / 3.6 采购状态 / 3.7 日本仓发货模型
-- Requirements: 8.1（规范化结构）、8.2（A 区订单级共享、货源/采购/物流子商品级）、
--               8.3（purchases/jp_shipments/domestic_shipments/intl_shipments 均按 order_item_id 关联）
--
-- 建表顺序遵循外键依赖：stores / users → orders → order_items → 子表 → order_logs
-- 目标库：MySQL 8（InnoDB / utf8mb4）。租户库在编译期不存在，运行期由迁移 runner 套用。
-- ============================================================================

-- ----------------------------------------------------------------------------
-- stores：店铺（平台 + 店铺缩写/全称 + 隐藏标记），订单按 store_id 归属
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `stores` (
    `id`           BIGINT       NOT NULL AUTO_INCREMENT,
    `platform`     VARCHAR(8)   NOT NULL COMMENT '平台 y/r/w/m/q/yp',
    `dpqz`         VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '店铺缩写',
    `dpquancheng`  VARCHAR(255) NOT NULL DEFAULT '' COMMENT '店铺全称',
    `is_hidden`    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '隐藏店铺',
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_stores_platform` (`platform`),
    KEY `idx_stores_dpqz` (`dpqz`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='店铺';

-- ----------------------------------------------------------------------------
-- users：租户员工 / 公司管理员
--   is_company_admin：公司管理员短路全部权限
--   role：采购/客服/品检（驱动 default_permissions）
--   permissions：JSON 覆盖项（Employee 显式值优先，未设置回退角色默认）
--   dpqz/dpquancheng：店铺范围（缩写/全称）
--   password_hash：Argon2id（旧明文首登重哈希后写入）
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id`               BIGINT       NOT NULL AUTO_INCREMENT,
    `username`         VARCHAR(128) NOT NULL COMMENT '登录名',
    `password_hash`    VARCHAR(255) NULL COMMENT 'Argon2id 哈希；旧明文重哈希后写入',
    `legacy_password`  VARCHAR(255) NULL COMMENT '旧明文密码（首登重哈希后清空）',
    `is_company_admin` TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '公司管理员',
    `role`             VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '采购/客服/品检',
    `permissions`      JSON         NULL COMMENT '权限覆盖项（overrides）',
    `dpqz`             VARCHAR(255) NOT NULL DEFAULT '' COMMENT '店铺范围缩写（逗号分隔或 JSON 文本）',
    `dpquancheng`      VARCHAR(512) NOT NULL DEFAULT '' COMMENT '店铺范围全称',
    `is_active`        TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '启用状态；禁用后吊销会话',
    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='租户员工/公司管理员';

-- ----------------------------------------------------------------------------
-- orders：订单聚合根（A 区客户/收件信息整单共享，订单级）
--   平台差异通过 platform 列 + platform_extra JSON 容纳
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `orders` (
    `id`                BIGINT        NOT NULL AUTO_INCREMENT,
    `platform`          VARCHAR(8)    NOT NULL COMMENT '平台 y/r/w/m/q/yp',
    `platform_order_id` VARCHAR(128)  NOT NULL COMMENT 'orderId',
    `order_detail_id`   VARCHAR(128)  NULL COMMENT 'orderDetailId',
    `store_id`          BIGINT        NULL COMMENT '所属店铺',
    `order_date`        DATETIME      NULL COMMENT '平台下单时间',
    `order_status`      VARCHAR(64)   NOT NULL DEFAULT '' COMMENT '平台原始状态',
    -- A 区：客户 / 收件信息（整单共享）
    `customer_name`     VARCHAR(255)  NOT NULL DEFAULT '' COMMENT '收件人姓名',
    `customer_kana`     VARCHAR(255)  NOT NULL DEFAULT '' COMMENT '片假名',
    `customer_zip`      VARCHAR(32)   NOT NULL DEFAULT '' COMMENT '邮编',
    `customer_address`  VARCHAR(1024) NOT NULL DEFAULT '' COMMENT '收件地址（拼接后）',
    `customer_phone`    VARCHAR(64)   NOT NULL DEFAULT '' COMMENT '电话',
    `customer_mail`     VARCHAR(255)  NOT NULL DEFAULT '' COMMENT '邮箱',
    `pay_method`        VARCHAR(128)  NOT NULL DEFAULT '' COMMENT '支付方式',
    `ship_method`       VARCHAR(128)  NOT NULL DEFAULT '' COMMENT '运送方式',
    -- 金额（varchar → decimal）
    `total_item_price`  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `postage_price`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `pay_charge`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total_price`       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    -- 评价
    `review_invited`    TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '已邀评',
    `reviewed`          TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '已评价',
    `imported_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'cdate；归档按年份切分',
    `platform_extra`    JSON          NULL COMMENT '平台特有字段（键名保留原列名）',
    PRIMARY KEY (`id`),
    KEY `idx_orders_platform` (`platform`),
    KEY `idx_orders_platform_order_id` (`platform_order_id`),
    KEY `idx_orders_store_id` (`store_id`),
    KEY `idx_orders_imported_at` (`imported_at`),
    CONSTRAINT `fk_orders_store`
        FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='订单聚合根';

-- ----------------------------------------------------------------------------
-- order_items：子商品（B1 区，每子商品一行）
--   货源地 source_type / 采购流程 purchase_status / 商品编码归一 item_code /
--   日本仓 ID jp_warehouse_id / 采购人 caigou_user 均下沉到子商品级
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `order_items` (
    `id`              BIGINT        NOT NULL AUTO_INCREMENT,
    `order_id`        BIGINT        NOT NULL COMMENT '所属订单',
    `order_detail_id` VARCHAR(128)  NOT NULL DEFAULT '' COMMENT 'orderDetailId',
    `line_id`         VARCHAR(128)  NOT NULL DEFAULT '' COMMENT 'LineId',
    `source_type`     VARCHAR(16)   NOT NULL DEFAULT 'pending' COMMENT '货源地 cn_purchase/jp_stock/pending',
    `purchase_status` VARCHAR(64)   NOT NULL DEFAULT '待处理' COMMENT '采购/出库流程进度（已解耦货源）',
    `item_code`       VARCHAR(255)  NOT NULL DEFAULT '' COMMENT 'ItemId/itemCode/lotnumber 归一',
    `lot_number`      VARCHAR(255)  NOT NULL DEFAULT '' COMMENT 'lotnumber',
    `item_management_id` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'ItemManagerId/itemManagementId',
    `jp_warehouse_id` VARCHAR(128)  NULL COMMENT '日本仓 SKU/ID（货源预判用）',
    `product_title`   VARCHAR(1024) NOT NULL DEFAULT '' COMMENT '商品名',
    `item_option`     VARCHAR(512)  NOT NULL DEFAULT '' COMMENT '商品属性',
    `chinese_option`  VARCHAR(512)  NOT NULL DEFAULT '' COMMENT '中文属性',
    `quantity`        INT           NOT NULL DEFAULT 0,
    `weight`          DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    `material`        VARCHAR(255)  NOT NULL DEFAULT '',
    `unit_price`      DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'UnitPrice/itemPrice',
    `postage_price`   DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'postagePrice/ShipCharge',
    `pay_charge`      DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'PayCharge',
    `line_total`      DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'totalItemPrice/TotalPrice',
    `amount`          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `item_comment`    VARCHAR(1024) NOT NULL DEFAULT '' COMMENT 'comment',
    `caigou_user`     VARCHAR(128)  NULL COMMENT '首次写 tabaono 时赋值一次，后续不覆盖',
    `main_image`      VARCHAR(1024) NOT NULL DEFAULT '' COMMENT 'zhutu 主图',
    `sku_image`       VARCHAR(1024) NOT NULL DEFAULT '' COMMENT 'skuimg',
    `platform_extra`  JSON          NULL COMMENT '子商品平台特有字段（如 itemOptionCommission1..5）',
    PRIMARY KEY (`id`),
    KEY `idx_order_items_order_id` (`order_id`),
    KEY `idx_order_items_source_type` (`source_type`),
    KEY `idx_order_items_purchase_status` (`purchase_status`),
    KEY `idx_order_items_item_code` (`item_code`),
    CONSTRAINT `fk_order_items_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='订单子商品';

-- ----------------------------------------------------------------------------
-- purchases：国内采购信息（仅 cn_purchase 子商品有，按 order_item_id 关联）
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `purchases` (
    `id`               BIGINT        NOT NULL AUTO_INCREMENT,
    `order_item_id`    BIGINT        NOT NULL COMMENT '关联子商品',
    `tabaono`          VARCHAR(128)  NOT NULL DEFAULT '' COMMENT '1688 订单号',
    `caigou_link`      VARCHAR(1024) NOT NULL DEFAULT '' COMMENT '采购链接',
    `buhuo_link`       VARCHAR(1024) NOT NULL DEFAULT '' COMMENT '补货链接',
    `caigou_user`      VARCHAR(128)  NOT NULL DEFAULT '' COMMENT '采购人',
    `caigou_time`      DATETIME      NULL COMMENT '采购时间',
    `caigou_ordernums` VARCHAR(255)  NOT NULL DEFAULT '',
    `cn_amount`        DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'cnamount 采购金额',
    `com_amount`       DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'comamount',
    `cn_ship_number`   VARCHAR(255)  NOT NULL DEFAULT '' COMMENT '国内运单号 shipno',
    `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_purchases_order_item_id` (`order_item_id`),
    KEY `idx_purchases_tabaono` (`tabaono`),
    CONSTRAINT `fk_purchases_order_item`
        FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='国内采购信息';

-- ----------------------------------------------------------------------------
-- jp_shipments：日本仓出库信息（仅 jp_stock 子商品有，按 order_item_id 关联）
--   out_status 状态机：待分配 → 已分配 → 已出库 → 已发货
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `jp_shipments` (
    `id`            BIGINT        NOT NULL AUTO_INCREMENT,
    `order_item_id` BIGINT        NOT NULL COMMENT '关联子商品',
    `out_status`    VARCHAR(32)   NOT NULL DEFAULT '待分配' COMMENT '待分配/已分配/已出库/已发货',
    `assignee`      VARCHAR(128)  NOT NULL DEFAULT '' COMMENT '发货员',
    `operator`      VARCHAR(128)  NOT NULL DEFAULT '' COMMENT '出库人',
    `out_time`      DATETIME      NULL COMMENT '出库时间',
    `location`      VARCHAR(128)  NOT NULL DEFAULT '' COMMENT '仓位',
    `out_no`        VARCHAR(128)  NOT NULL DEFAULT '' COMMENT '出库单号',
    `out_cost`      DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '出库成本',
    `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_jp_shipments_order_item_id` (`order_item_id`),
    KEY `idx_jp_shipments_out_status` (`out_status`),
    CONSTRAINT `fk_jp_shipments_order_item`
        FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='日本仓出库信息';

-- ----------------------------------------------------------------------------
-- domestic_shipments：国内（日本境内）物流（按 order_item_id 关联，多运单号一对多）
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `domestic_shipments` (
    `id`                  BIGINT       NOT NULL AUTO_INCREMENT,
    `order_item_id`       BIGINT       NOT NULL COMMENT '关联子商品',
    `ship_number`         VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'shipnumber（每运单一行）',
    `ship_company`        VARCHAR(128) NOT NULL DEFAULT '' COMMENT 'shipcompany/carrier',
    `ship_quantity`       INT          NOT NULL DEFAULT 0 COMMENT 'shipquantity 发货数量',
    `jpship_status`       VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'jpshipdetails',
    `jpship_completed_at` DATETIME     NULL COMMENT '配達完了时间（仅首次写入）',
    `logistic_trace`      TEXT         NULL COMMENT '物流轨迹',
    `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_domestic_shipments_order_item_id` (`order_item_id`),
    KEY `idx_domestic_shipments_ship_number` (`ship_number`),
    CONSTRAINT `fk_domestic_shipments_order_item`
        FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='国内（日本境内）物流';

-- ----------------------------------------------------------------------------
-- intl_shipments：国际物流（按 order_item_id 关联）
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `intl_shipments` (
    `id`               BIGINT        NOT NULL AUTO_INCREMENT,
    `order_item_id`    BIGINT        NOT NULL COMMENT '关联子商品',
    `intl_number`      VARCHAR(255)  NOT NULL DEFAULT '' COMMENT '国际运单号',
    `intl_status`      VARCHAR(128)  NOT NULL DEFAULT '' COMMENT '运单状态',
    `intl_fee`         DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '运费',
    `intl_qty`         INT           NOT NULL DEFAULT 0 COMMENT '件数',
    `intl_weight`      DECIMAL(12,3) NOT NULL DEFAULT 0.000 COMMENT '重量',
    `tranship_comment` VARCHAR(1024) NOT NULL DEFAULT '',
    `comment`          VARCHAR(1024) NOT NULL DEFAULT '',
    `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_intl_shipments_order_item_id` (`order_item_id`),
    KEY `idx_intl_shipments_intl_number` (`intl_number`),
    CONSTRAINT `fk_intl_shipments_order_item`
        FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='国际物流';

-- ----------------------------------------------------------------------------
-- order_logs：结构化审计日志（取代 beizhu_log 文本日志）
--   order_item_id 可空：订单级变更为空，子商品级变更带 order_item_id
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `order_logs` (
    `id`            BIGINT       NOT NULL AUTO_INCREMENT,
    `order_id`      BIGINT       NOT NULL COMMENT '所属订单',
    `order_item_id` BIGINT       NULL COMMENT '可空：子商品级变更',
    `operator`      VARCHAR(128) NOT NULL DEFAULT '' COMMENT '操作人（系统/用户名）',
    `action_type`   VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '操作类型（货源判定/改判/状态变更等）',
    `field_name`    VARCHAR(128) NOT NULL DEFAULT '',
    `old_value`     TEXT         NULL,
    `new_value`     TEXT         NULL,
    `ip`            VARCHAR(64)  NOT NULL DEFAULT '',
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_order_logs_order_id` (`order_id`),
    KEY `idx_order_logs_order_item_id` (`order_item_id`),
    KEY `idx_order_logs_created_at` (`created_at`),
    CONSTRAINT `fk_order_logs_order`
        FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_order_logs_order_item`
        FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='结构化订单审计日志';
