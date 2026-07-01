# 服务器 Rust 旧系统清理执行单

> 用途：填写服务器连接信息和旧 Rust 系统清理范围。填写完成后，Codex 将通过 SSH 登录服务器，先做清点和备份，再按确认范围删除旧 Rust 系统相关内容。
>
> 安全建议：尽量不要在本文档中填写明文密码或私钥正文。推荐填写 SSH 主机、端口、用户名，并使用服务器已有的 SSH key / 本机 SSH 配置连接。若必须使用临时密码，请在执行完成后立即修改密码，并从本文档中删除敏感信息。

## 1. SSH 连接信息

- 服务器 IP / 域名：49.212.189.64
- SSH 端口：22828
- SSH 用户名：debian
- 认证方式：
  - [√] 本机 SSH key
  - [ ] 宝塔面板终端
  - [ ] 临时密码
  - [ ] 其他：
- SSH key 本机路径（如适用）： E:\西阵订单系统\新订单系统\php-saas\docs\project_id_ed25519
- 是否需要 sudo：
  - [√] 否
  - [ ] 是，sudo 用户：

## 2. 宝塔 / 网站信息

- 宝塔面板地址：
- 宝塔站点名称：
- 当前 Rust 系统域名：
- 新 PHP SaaS 计划域名：
  - SaaS 管理端：saas.xizhends.com
  - 租户入口：*.xizhends.com
- 当前网站根目录：
- 当前反向代理 / 进程监听端口：
- 当前是否有线上用户正在使用：
  - [ ] 否
  - [ ] 是，允许维护时间：

## 3. 旧 Rust 系统疑似目录

请填写服务器上旧 Rust 系统可能所在目录，未确认的也可以写上，我会先只读核验。

- 项目目录：/www/wwwroot/xizhends
  -
- 编译产物目录：
  -
- systemd 服务文件：
  -
- Nginx / 宝塔反向代理配置：
  -
- 日志目录：
  -
- 上传文件 / 图片目录：
  -
- 数据库名：
  -
- 其他相关路径：
  -

## 4. 必须保留的内容

以下内容不允许删除，除非你后续再次明确确认。

- `/old` 旧 PHP 线上业务系统
- 当前新的 `php-saas` 项目
- 订单图片、附件、上传文件
- MySQL 数据库数据
- 宝塔面板自身文件
- SSL 证书
- Nginx / PHP / MySQL 主程序
- 其他必须保留：
  -

## 5. 允许清理的内容

请勾选允许清理的类型。

- [ ] Rust 项目源码目录
- [ ] Rust 编译产物，例如 `target/`
- [ ] Rust 二进制程序
- [ ] Rust systemd 服务
- [ ] Rust 进程启动脚本
- [ ] Rust 反向代理配置
- [ ] Rust 项目文档
- [ ] Rust 临时日志
- [ ] Rust 备份包
- [ ] 其他：

## 6. 清理前备份要求

- 是否需要先打包备份：
  - [x] 是
  - [ ] 否
- 备份保存目录：
  - 默认建议：`/root/xizhen-rust-cleanup-backup-YYYYmmdd-HHMMSS/`
- 需要备份的内容：
  - [x] 待删除目录清单
  - [x] systemd 服务文件
  - [x] Nginx / 宝塔站点配置
  - [x] Rust 项目源码
  - [ ] 数据库 dump
  - [ ] 上传图片 / 附件
- 备份后是否需要保留压缩包：
  - [x] 是
  - [ ] 否

## 7. 执行步骤约定

Codex 执行时按以下顺序处理：

1. SSH 连接服务器。
2. 只读检查服务器系统、宝塔目录、站点配置、进程、端口、systemd 服务。
3. 输出疑似 Rust 旧系统文件和服务清单。
4. 对待删除内容做备份。
5. 停止旧 Rust 服务，但不影响 `/old` 和 `php-saas`。
6. 删除你确认允许清理的 Rust 内容。
7. 重载 Nginx / systemd。
8. 检查端口、站点状态、宝塔网站配置。
9. 输出清理结果和剩余待确认项。

## 8. 明确授权

请在执行前填写：

- 我确认允许 Codex 通过 SSH 登录上述服务器进行 Rust 旧系统清理：是 / 否
- 我确认允许 Codex 停止旧 Rust 服务：是 / 否
- 我确认允许 Codex 删除第 5 节勾选的内容：是 / 否
- 我确认当前已避开线上业务高峰期：是 / 否

## 9. 备注

-

## 10. 执行结果记录

- 执行时间：2026-06-19 00:50-00:54（服务器时间）
- 实际 SSH 用户：root
- 服务器主机名：os3-330-54810
- 备份目录：`/root/xizhen-rust-cleanup-backup-20260619-005019`
- 备份大小：约 `964M`

已清理内容：

- 已停止并禁用 `xizhends.service`
- 已移除 `/etc/systemd/system/xizhends.service`
- 已移除 `/etc/xizhends`
- 已删除 `/www/wwwroot/xizhends/app`
- 已删除 `/www/wwwroot/xizhends/app.backup-20260617044250`
- 已删除 `/www/wwwroot/xizhends/backups`
- 已清理 `/www/wwwroot/xizhends/uploads/tenant-edit-ui-20260616.tgz`
- 已清理 `/www/wwwroot/xizhends/uploads/tenant-edit-ui-20260616.tgz.b64`
- 已移走宝塔 Nginx 中 `erp.xizhends.com`、`saas.xizhends.com` 反向代理到 `127.0.0.1:8080` 的 proxy 子配置
- 已执行 `systemctl daemon-reload`
- 已执行 `nginx -t`，结果通过
- 已重载 Nginx

保留内容：

- `/www/wwwroot/xizhends/public`
- `/www/wwwroot/xizhends/uploads` 目录本身
- `/www/wwwroot/xizhends/index.html`
- `/www/wwwroot/xizhends/404.html`
- 宝塔、Nginx、PHP、MySQL、SSL 等服务器基础环境

验证结果：

- 未发现 `order-system-rewrite` 进程
- 未发现 `xizhends.service` systemd 服务
- 未发现 `127.0.0.1:8080` 监听
- 未发现 Nginx 继续反向代理到 `127.0.0.1:8080`
- `/www/wwwroot/xizhends` 下未发现 `Cargo.toml`、`Cargo.lock`、`target`、`*.rs` 等 Rust 残留
- `saas.xizhends.com`、`erp.xizhends.com` 当前返回静态 `index.html`，后续可改为 PHP SaaS 的 `public` 入口
