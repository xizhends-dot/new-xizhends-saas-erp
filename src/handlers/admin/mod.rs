//! 超管 + 公司管理处理器（租户/员工/店铺/平台授权/公告/概览）。
//!
//! 占位模块（Task 1.1）。各子模块实现见 Task 11.x。

/// 超管后台「系统公告」发布与已发布列表（Task 11.4 / Requirements 10.3）。
pub mod announcements;

/// 超管后台「操作日志」页面（基于 sessions 的会话审计）。
pub mod audit_logs;

/// 超管后台「套餐 / 计费」页面（基于租户套餐档案的统计）。
pub mod billing;

/// 超管后台「数据库管理」页面（主库与租户连接池摘要）。
pub mod databases;

/// 超管后台「概览」聚合页 + 橙色身份界面（Task 11.5 / Requirements 10.1、10.4）。
pub mod overview;

/// 超管后台「服务器健康」诊断页。
pub mod health;

/// 超管后台「平台授权」页面（租户平台开通/锁定三态）。
pub mod platform_auth;

/// 超管后台「租户管理」CRUD（Task 11.1 / Requirements 10.2、7.4）。
pub mod tenants;
