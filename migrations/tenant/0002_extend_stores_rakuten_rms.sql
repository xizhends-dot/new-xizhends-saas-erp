-- ============================================================================
-- stores：乐天 RMS Order API 凭证与同步任务报告
--
-- RMS WEB SERVICE Order API 后续按 old/plugins/rakuten-rms-api 的
-- searchOrder/getOrder 流程接 provider，鉴权头为：
-- Authorization: ESA base64(serviceSecret:licenseKey)
-- 本地默认 provider 未接线时不会访问外网；服务器接入真实 HTTP provider 后，
-- 租户侧同步按钮按 searchOrder/getOrder/去重/落库流程执行。
-- ============================================================================

ALTER TABLE `stores`
    ADD COLUMN IF NOT EXISTS `rms_service_secret` VARCHAR(255) NULL COMMENT '乐天 RMS serviceSecret',
    ADD COLUMN IF NOT EXISTS `rms_license_key` VARCHAR(255) NULL COMMENT '乐天 RMS licenseKey',
    ADD COLUMN IF NOT EXISTS `rms_credentials_updated_at` DATETIME NULL COMMENT 'RMS 凭证更新时间',
    ADD COLUMN IF NOT EXISTS `last_sync_at` DATETIME NULL COMMENT '最近同步检查时间',
    ADD COLUMN IF NOT EXISTS `last_sync_status` VARCHAR(64) NULL COMMENT '最近同步检查状态',
    ADD COLUMN IF NOT EXISTS `last_sync_message` VARCHAR(1024) NULL COMMENT '最近同步检查报告';
