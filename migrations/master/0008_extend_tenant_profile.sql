-- 主库迁移 0008：补全租户资料字段
-- 支持 SaaS 超管独立新建租户页面填写联系人、地址、备注等资料。

ALTER TABLE `tenants`
    ADD COLUMN `company_short_name` VARCHAR(128) NULL COMMENT '公司简称' AFTER `company_name`,
    ADD COLUMN `contact_name` VARCHAR(64) NULL COMMENT '联系人姓名' AFTER `company_short_name`,
    ADD COLUMN `contact_phone` VARCHAR(32) NULL COMMENT '联系人电话' AFTER `contact_name`,
    ADD COLUMN `contact_email` VARCHAR(128) NULL COMMENT '联系人邮箱' AFTER `contact_phone`,
    ADD COLUMN `contact_wechat` VARCHAR(64) NULL COMMENT '联系人微信' AFTER `contact_email`,
    ADD COLUMN `address` VARCHAR(255) NULL COMMENT '联系地址' AFTER `contact_wechat`,
    ADD COLUMN `remark` TEXT NULL COMMENT '租户备注' AFTER `address`;
