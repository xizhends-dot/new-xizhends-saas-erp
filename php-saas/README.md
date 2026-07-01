# 西阵订单 SaaS PHP 重构版

这是新的主开发线。当前版本不依赖 Composer、npm、数据库扩展，使用 PHP 8.4 内置服务器与 JSON 文件存储，便于先把核心功能跑通。

Rust 重构版保留为备份和规格资料；其中 `migrations/master/*.sql` 与 `migrations/tenant/*.sql` 不是 Rust 专属，可以直接作为 PHP 新系统后续 MySQL 数据库契约。

## 启动

```powershell
cd php-saas
php -S 127.0.0.1:8090 -t public
```

然后访问：

- 租户订单系统：http://127.0.0.1:8090/?tenant=erp
- 超管后台：http://127.0.0.1:8090/admin

正式部署入口规划：

- SaaS 管理系统：`https://saas.xizhends.com`
- 租户订单系统：`https://{tenant}.xizhends.com`

正式租户入口会从子域名识别租户 key；本地开发环境继续使用 `?tenant=erp` 作为调试兜底。

## 当前已实现

- 超管后台概览
- 租户管理列表
- 平台授权开关
- 系统公告展示
- 租户仪表盘
- 平台订单 / 采购订单 / 日本仓发货三视图
- 货源改判并自动影响下游队列
- 操作日志展开
- 功能工作台、全局搜索
- 权限严格拦截与员工店铺范围过滤
- 利润分析、采购统计
- 1688 物流、日本物流、邮件中心入口
- 导入导出：CSV 导出、CSV 导入解析预览、任务日志
- 系统设置：公司资料、订单参数、利润参数、物流映射保存
- 图片管理、定时任务入口
- 数据驱动配置：`DATA_DRIVER=json|mysql`
- JSON 持久化数据

## 数据库路线

当前环境缺少 `pdo_mysql`，所以默认使用 JSON。服务器安装 MySQL 扩展后可配置：

```powershell
$env:DATA_DRIVER="mysql"
$env:MYSQL_DSN="mysql:host=127.0.0.1;dbname=xizhends_master;charset=utf8mb4"
$env:MYSQL_USER="root"
$env:MYSQL_PASSWORD="password"
$env:MYSQL_TENANT_DSN_ERP="mysql:host=127.0.0.1;dbname=xizhends_tenant_erp;charset=utf8mb4"
```

主库使用 `migrations/master/`，每个租户库使用 `migrations/tenant/`。如果 `pdo_mysql` 不存在或 DSN 未配置，系统会自动回退到 JSON，并在 `/admin/system` 显示原因。

## 目录

- `public/`：Web 入口、CSS、JS
- `app/Core/`：路由、视图、JSON 数据仓库
- `app/Controllers/`：页面控制器
- `app/Views/`：PHP 模板
- `storage/data/app.json`：开发期数据文件
- `config/app.php`：数据驱动与 MySQL 连接配置
- `docs/database-contract.md`：Rust SQL 与 PHP 数据层的对应说明

## 后续迁移到 Laravel 的对应关系

- `JsonStore` → Eloquent Model / Repository
- `Router` → Laravel routes
- `Controllers` → Laravel Controllers / Livewire Components
- `Views` → Blade
- `storage/data/app.json` → MySQL migrations + seeders
