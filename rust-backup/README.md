# Rust 版本备份说明

本目录用于说明当前仓库根目录中的 Rust/Axum 版本已转为备份资料，不再作为后续主开发线。

## 保留范围

Rust 版本相关文件仍保留在仓库根目录：

- `Cargo.toml`
- `Cargo.lock`
- `src/`
- `tests/`
- `migrations/`
- `.kiro/specs/order-system-rewrite/`
- `DESIGN.md`
- `sample.html`
- `sample-admin.html`

这些文件作为二次重构的需求、数据库结构、业务规则、页面原型参考。

## 新开发线

新的 PHP SaaS 版本位于：

- `php-saas/`

当前环境没有 Composer，因此先采用无外部依赖的 PHP 8.4 + SQLite + 原生模板骨架实现核心功能。后续如安装 Composer，可继续迁移到 Laravel。

## 注意

- 不要删除 `/old`，它仍是当前线上旧系统参考。
- 不要删除 Rust 版本文件，除非新系统功能完全替代并完成数据迁移验证。
