-- ============================================================================
-- Align platform order fields with /old ph_order* tables.
--
-- 2026-07-04 修订：本迁移原为老库补列脚本（MariaDB 的 ADD COLUMN IF NOT EXISTS
-- 语法，MySQL 5.7/8.0 均不支持）。经与 0001_init_tenant_schema.sql 逐列核对，
-- 本文件涉及的全部字段（orders.pay_charge 及 order_items 的 order_detail_id /
-- line_id / lot_number / item_management_id / unit_price / postage_price /
-- pay_charge / line_total / item_comment）均已并入 0001 基础建表语句，
-- 新建租户库时无需再执行任何语句。保留本文件仅为维持迁移编号连续。
-- ============================================================================

DO 0;
