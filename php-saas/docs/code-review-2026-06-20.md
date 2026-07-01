# php-saas 代码审查报告

- **系统**：西阵订单 SaaS PHP 重构版（PHP 8.4，无框架，JSON/MySQL 双存储，多租户）
- **规模**：约 17,600 行 PHP
- **审查日期**：2026-06-20
- **方法**：5 个并行子智能体分模块审查 + 人工逐行核实高影响发现（已剔除误报、修正分级）

---

## 总体评价

代码**结构清晰、分层规范**（Core / Controllers / Services / Views）。基础安全两块做得扎实：

- ✅ 密码 Argon2id、`session_regenerate_id`、httponly + SameSite=Lax cookie、登录失败限流
- ✅ 视图层 200+ 处一致使用 `e()` 转义，**未发现实际可利用的 XSS**
- ✅ 数据库层全部 PDO 预处理绑定参数

两个结构性硬伤必须在上生产前解决：**JSON 存储无并发保护**、**全站无 CSRF**。

---

## CRITICAL（必须立即修复）

### C1. JSON 存储无文件锁、无原子写入 → 高并发丢数据
`app/Core/JsonStore.php:2048-2059`（`save()`）、`:34`（`all()`）

`save()` 直接 `file_put_contents` 全量写回，`all()` 直接 `file_get_contents` 全量读取，**都无 `flock`**。`app.json` 是单一大文件、所有租户共用。并发请求"读取→改内存→写回"互相覆盖，订单/积分账本/扣费会丢失或错乱；写入中途被读取还会读到半截 JSON 导致解析失败、回退种子数据（等于清空）。

**修复**：临时文件 + `rename` 原子替换；写加 `LOCK_EX`、读加 `LOCK_SH`；多步业务（如 `processDueTenantBilling` 扣分+改订阅）用"备份→失败回滚"模拟事务。JSON 方案仅适合单机低并发，正式多租户应尽快切 MySQL。

---

## HIGH（尽快修复）

### H1. 全站无 CSRF 防护
全部 POST 路由（充值扣费 `/admin/billing/adjust`、批量删单 `/orders/batch`、改密改权限、邮件删除等）均无 CSRF token。grep 全库确认无任何 token 生成/校验逻辑。

- 缓解：已设 `SameSite=Lax`，现代浏览器拦截大部分跨站 POST 携带 cookie，故定为 HIGH 而非 CRITICAL。但 Lax 不防 GET 改状态、不防同站子域、依赖浏览器版本，仍须补齐。
- **修复**：`AuthService` 生成 per-session token，加统一 POST 校验入口，模板表单加隐藏 `_token`。

### H2. 邮箱密码用 XOR + 硬编码默认密钥
`app/Services/MailService.php:1139-1167`

```php
$key = getenv('KEFU_MAIL_PWD_SALT') ?: 'kefu_mail';  // 默认密钥写死在源码
$out .= chr(ord($plain[$i]) ^ ord($key[$i % $k]));    // XOR ≈ 明文
```

IMAP/SMTP 密码须可逆存储（协议要明文登录），但 XOR + 公开默认 key 几乎等于明文。**修复**：改 `sodium_crypto_secretbox` 或 `openssl aes-256-gcm`，密钥强制从环境变量注入、无默认值。

### H3. IMAP 连接禁用 TLS 证书验证
`app/Services/MailService.php:616`

```php
$flags = '/imap' . (... ? '/ssl' : '/notls') . '/novalidate-cert'; // 关闭证书校验
```

中间人可用伪造证书窃取邮箱账密。**修复**：去掉 `/novalidate-cert`，改 `/validate-cert`。

### H4. 邮件发送存在头注入风险
`app/Services/MailService.php`（`buildMessage()`）：收件人/抄送/主题拼进邮件头前未过滤 `\r\n`，可注入额外收件人或头。**修复**：地址、主题 `str_replace(["\r","\n"], '', ...)`。

### H5. CSV 导入无公式注入防护
`app/Services/CsvImportService.php:513-524`（`cleanCell()`）：未处理 `=` `+` `-` `@` 开头单元格，导出后被 Excel 打开会执行公式。**修复**：对这些前缀单元格加前导单引号。

### H6. 部分写操作缺少资源归属校验（IDOR）
- `app/Controllers/TenantController.php:730`（`deleteMailAccount`）：未校验 `account_id` 属于当前租户
- `app/Controllers/TenantController.php:453`（`updateStore`）：校验了权限但未校验店铺归属/店铺范围

订单子项已有 `ensureItemAccess` 做归属校验（正确，只是 O(n·m) 偏慢）。**修复**：删除/更新前先按 `tenantKey` 查出资源确认存在且归属。

---

## MEDIUM

| 编号 | 位置 | 问题 |
|---|---|---|
| M1 | `app/Core/Permission.php:61` | `canAccessStore`：员工 `stores` **为空时返回 true**（可见全部店铺）。需确认是否预期；若否，空列表应拒绝 |
| M2 | `app/Controllers/TenantController.php:1735` | 上传图片存于 web 根下 `storage/`，未确认禁脚本执行。上传内容已用 `getimagesizefromstring` 校验、无法传 php，故非 CRITICAL |
| M3 | `app/Controllers/TenantController.php:198` | `batchOrders` 无批量条数上限，可一次删大量订单 |
| M4 | `app/Services/AuthService.php:253-291` | 登录失败计数存 session，攻击者丢弃 cookie 即可绕过限流。应改按 IP/账号服务端存储 |
| M5 | `app/Services/AuthService.php:220-226` | `legacy_password`/`password_reset` 明文字段回退登录，迁移完成后应清理 |
| M6 | `app/Services/AppService.php`（利润计算） | 金额用浮点累积误差，财务场景建议整数分或 bcmath |
| M7 | `MysqlStore::connect()` | PDO 异常未捕获，可能把 DSN/库结构透出前端 |

---

## LOW（择机）

- 列名/表名拼接当前安全（见误报澄清），仍建议提取白名单常量做防御加固
- `json_encode`/`json_decode` 失败未校验返回值（`JsonStore.php:35,2055`）
- `LegacySettingsService` 路径校验建议改 `realpath` 前缀比对
- CSV 大文件无大小/行数上限，可能 OOM
- 关键操作（删单、改权限）缺审计日志

---

## 误报澄清（重要，避免无效工时）

自动审查曾标为 CRITICAL/HIGH，人工核实后判定**不成立**：

1. **"SQL 列名/表名拼接 = SQL 注入 CRITICAL"** ❌
   核实 `togglePlatform`（`MysqlStore.php:948` 用 `in_array($field,['enabled','locked'])` 白名单）、`updateOrderItemColumn`、`saveCompany`（`:2350` 内部 `$columnMap` 映射）等所有点：**动态列名全部来自代码内硬编码数组/白名单，无任何用户可控字符串进入列名**。用户只能控制已用 `?` 绑定的值。→ 实为 LOW（防御加固）。

2. **"乐天 cURL 未验证证书 CRITICAL"** ❌
   `RakutenOrderService.php:174-185`：代码**没有**关闭 `CURLOPT_SSL_VERIFYPEER`，cURL 该项**默认即为 true**，证书是验证的。→ 实为 LOW（建议显式声明）。

3. **"邮件 srcdoc / 附件名 XSS HIGH"** ❌
   `srcdoc` 已加 `sandbox`（无 `allow-scripts`），内容经 `e()` 转义，脚本无法执行。→ 非漏洞。

---

## 修复优先级建议

1. **本周**：C1（JSON 加锁/原子写）、H3（IMAP 证书）、H4（邮件头注入）
2. **两周内**：H1（CSRF）、H2（密码加密）、H5（CSV 公式）、H6（IDOR 归属校验）
3. **一月内**：M 系列；推进 JSON → MySQL 正式迁移

---

## 进度跟踪

| 项 | 状态 | 备注 |
|---|---|---|
| C1 JSON 并发 | ☐ 未修复 | |
| H1 CSRF | ☐ 未修复 | |
| H2 密码加密 | ☐ 未修复 | |
| H3 IMAP 证书 | ☐ 未修复 | |
| H4 邮件头注入 | ☐ 未修复 | |
| H5 CSV 公式 | ☐ 未修复 | |
| H6 IDOR 归属 | ☐ 未修复 | |

> 修复某项后请在此表打勾并注明 commit，保持文档与代码同步。
