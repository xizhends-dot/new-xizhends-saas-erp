//! SQLx 数据访问层（租户库统一用 `query_as` + 显式 SQL）。
//!
//! 占位模块（Task 1.1）。

pub mod announcement_repo;
pub mod billing_repo;
pub mod order_repo;
pub mod session_repo;
pub mod store_repo;
pub mod tenant_repo;
pub mod user_repo;
