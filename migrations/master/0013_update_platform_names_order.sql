-- 主库迁移 0013：修正平台显示名称与排序

INSERT INTO `platforms` (`code`, `name`, `sort_order`, `default_enabled`) VALUES
    ('r',  'Rakuten',  10, 1),
    ('y',  'Yahoo',    20, 1),
    ('w',  'Wowma',    30, 1),
    ('m',  'Mercari',  40, 1),
    ('q',  'Qoo10',    50, 1),
    ('yp', '雅虎拍卖', 60, 1)
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `sort_order` = VALUES(`sort_order`),
    `default_enabled` = VALUES(`default_enabled`);
