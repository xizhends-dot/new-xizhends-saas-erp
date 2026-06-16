# xizhends 部署资料目录

本目录用于记录 Debian 12 / 宝塔服务器部署资料。

建议约定：

- 代码目录：`/www/wwwroot/xizhends/app`
- 宝塔站点根目录：`/www/wwwroot/xizhends/public`
- 本地配置目录：`/etc/xizhends`
- systemd 服务名：`xizhends.service`
- 主数据库名：`xizhends_master`

真实 SSH 信息、服务器密码、私钥、API 密钥不要提交到 Git。可在本目录创建这些本地文件：

- `servers.local.md`：记录服务器地址、端口、用户、域名、数据库名等维护信息。
- `ssh_config.local`：记录可直接使用的 SSH Host 配置。

这些 `*.local*` 文件已被 `.gitignore` 忽略。

推荐只记录 SSH 私钥路径，不记录明文密码。若必须临时记录明文密码，部署完成后应删除或改密。

