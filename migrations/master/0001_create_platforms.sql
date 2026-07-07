-- 主库迁移 0001：平台目录表 platforms
-- 对应 design.md 3.8：平台目录（6 个平台的 code / name / 排序 / 默认开通）
-- 驱动：超管后台「平台授权」卡片、租户侧栏平台菜单的全集
-- Requirements: 7.2

CREATE TABLE IF NOT EXISTS `platforms` (
    `code`            VARCHAR(16)  NOT NULL COMMENT '平台代码：y/r/w/m/q/yp',
    `name`            VARCHAR(64)  NOT NULL COMMENT '平台名称：Rakuten/Yahoo/Wowma/Mercari/Qoo10/雅虎拍卖',
    `sort_order`      INT          NOT NULL DEFAULT 0 COMMENT '侧栏排序',
    `default_enabled` TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '新租户默认是否开通',
    PRIMARY KEY (`code`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = '平台目录（平台菜单全集与排序）';

-- 内置 6 个平台目录数据
INSERT INTO `platforms` (`code`, `name`, `sort_order`, `default_enabled`) VALUES
    ('r',  'Rakuten',    10, 1),
    ('y',  'Yahoo',      20, 1),
    ('w',  'Wowma',      30, 1),
    ('m',  'Mercari',    40, 1),
    ('q',  'Qoo10',      50, 1),
    ('yp', '雅虎拍卖',   60, 1)
ON DUPLICATE KEY UPDATE
    `name`            = VALUES(`name`),
    `sort_order`      = VALUES(`sort_order`),
    `default_enabled` = VALUES(`default_enabled`);
