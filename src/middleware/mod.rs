//! 中间件栈：租户识别 → 会话认证 → 权限校验。
//!
//! 占位模块（Task 1.1）。

pub mod permission;
pub mod session;
pub mod tenant;
