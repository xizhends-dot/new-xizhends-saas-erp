-- 国内物流签收地
-- 对齐老系统各平台订单表 receipt_city，用于订单筛选、列表展示和编辑侧栏维护。

ALTER TABLE `domestic_shipments`
    ADD COLUMN `receipt_city` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '物流签收地' AFTER `ship_quantity`;
