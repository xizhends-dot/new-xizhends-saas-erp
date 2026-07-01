# PHP SaaS 开发路线

## 当前版本定位

当前 `php-saas/` 是无外部依赖的开发版，目标是先把订单 SaaS 的核心页面和业务流跑起来：

- 租户侧：仪表盘、平台订单、采购订单、日本仓发货、店铺、员工。
- 超管侧：概览、租户管理、平台授权、公告。
- 关键业务：货源改判后自动影响采购队列和日本仓队列。

## 优先补齐

1. 登录与会话
   - 超管账号
   - 租户管理员账号
   - 员工账号

2. MySQL 数据层
   - 已新增 `StoreInterface`、`Config`、`StoreFactory`、`MysqlStore`
   - Rust SQL 迁移作为正式 MySQL 数据契约
   - 当前环境缺少 `pdo_mysql`，所以自动回退到 JSON
   - 后续服务器启用扩展后按主库 + 租户独立库切换

3. 订单导入
   - 按 `sample.html` 和 Rust 设计文档中的平台字段映射实现 CSV 导入
   - 先支持乐天 / Yahoo，再扩展其他平台

4. 权限
   - 功能模块权限
   - 店铺范围权限
   - 查看 / 编辑 / 删除操作权限

5. 定时任务
   - 1688 物流
   - 日本物流
   - 邮件同步
   - 主图下载

6. 旧系统外围模块
   - 已补功能工作台、全局搜索、利润分析、采购统计页面
   - 已补 1688 物流、日本物流、邮件中心、导入导出、图片管理、定时任务入口
   - 后续逐个接真实 API、文件上传、IMAP/SMTP 和导出器

## Laravel 迁移建议

本机当前没有 Composer，因此先不直接创建 Laravel 项目。安装 Composer 后可按以下方式迁移：

- `app/Core/Router.php` → Laravel `routes/web.php`
- `app/Controllers/*` → Laravel Controller
- `app/Views/*` → Blade
- `app/Core/JsonStore.php` → Eloquent Model / Repository
- `storage/data/app.json` → migrations + seeders

迁移时保留现有页面结构和 CSS，先替换数据层，再替换路由层。
