//! 9 个定时任务（与 old 系统 cron 语义一一对应）+ 调度器。
//!
//! 占位模块（Task 1.1）。

pub mod caigou_status_stats;
pub mod cleanup_old_images;
pub mod daily_maintain;
pub mod mail_sync;
pub mod order_archive;
pub mod order_monitor;
pub mod scheduler;
pub mod update_1688_logistics;
pub mod update_jpship_logistics;
pub mod zhutu_downloader;
