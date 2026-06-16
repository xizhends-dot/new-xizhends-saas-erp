//! `MailGateway`（IMAP/SMTP 聚合）实现（Task 13.6，对应 Requirements 11.5）。
//!
//! 复刻 old `cron/mail_sync.php` + `kefu_mail/inc/mail_functions.php` 的核心语义：
//! - **增量只拉头**：以 `MailFolder.last_uid` 为游标，仅拉取 `UID > last_uid` 的邮件头；
//!   首次同步（`last_uid == 0`）时只取最近 `limit` 封，避免对大邮箱一次性拉取全部导致超时
//!   （对应 PHP `mail_fetch_new_messages()` 的 `$last_uid <= 0` 分支）。
//! - **懒加载缓存回库**：正文不随头一起拉取；`load_body` 命中缓存（`body_loaded`）直接返回，
//!   否则实时连 IMAP 拉取并写回库后再返回（对应 PHP `mail_load_message_body()`）。
//! - **回复**：先 SMTP 发送，再 IMAP `APPEND` 写回 `Sent` 文件夹。
//!
//! ## 依赖边界（offline-testable）
//! 仓库 `Cargo.toml` **刻意不引入** `imap`/`lettre`/`native-tls` 等重型依赖。为了让本模块可
//! 编译、可离线单测，IMAP/SMTP 的真实 I/O 被收敛到三个可注入的异步 trait：
//! [`ImapTransport`]、[`SmtpTransport`]、[`MailStore`]。生产环境注入基于真实网络库的实现；
//! 单元测试注入内存 Mock。[`MailGatewayImpl`] 只负责编排「增量游标 / 懒加载 / 发信+回写」这套
//! 与传输无关的业务逻辑。
//!
//! _Requirements: 11.5_

use async_trait::async_trait;

use crate::error::AppError;
use crate::integrations::traits::{MailAccount, MailFolder, MailGateway, ReplyResult, SyncReport};

// ============================================================================
// 注入边界：IMAP / SMTP / 存储
// ============================================================================

/// IMAP 传输边界。真实实现负责连接、登录、`SEARCH`/`FETCH`/`APPEND`；
/// Mock 实现用于离线单测。所有失败收敛为 [`AppError::ExternalApi`]。
#[async_trait]
pub trait ImapTransport: Send + Sync {
    /// 列出账户下所有文件夹（含各自的 `last_uid` 游标，若实现可得）。
    async fn list_folders(&self, account: &MailAccount) -> Result<Vec<MailFolder>, AppError>;

    /// 首次同步：返回该文件夹**最近**的若干封邮件 UID（实现侧应尽量只取最近 `limit` 封）。
    ///
    /// 注意：[`MailGatewayImpl::sync_folder`] 仍会再次按 `limit` 截断，以保证游标语义稳定，
    /// 即便传输层返回了超量结果也不会破坏「首次只进最近 N 封」的约束。
    async fn fetch_recent_uids(&self, folder: &str, limit: u32) -> Result<Vec<u32>, AppError>;

    /// 增量同步：返回 `UID > after_uid` 的邮件 UID 列表。
    async fn fetch_uids_after(&self, folder: &str, after_uid: u32) -> Result<Vec<u32>, AppError>;

    /// 懒加载：按 `folder + uid` 拉取单封正文（纯文本）。
    async fn fetch_body(&self, folder: &str, uid: u32) -> Result<String, AppError>;

    /// 将已发送邮件 `APPEND` 写回 `Sent` 文件夹。
    async fn append_to_sent(
        &self,
        account: &MailAccount,
        raw_message: &str,
    ) -> Result<(), AppError>;
}

/// SMTP 传输边界。真实实现负责连接、`STARTTLS`/认证、投递；Mock 实现用于离线单测。
#[async_trait]
pub trait SmtpTransport: Send + Sync {
    /// 发送一封邮件，成功后返回 `Message-ID`。
    async fn send(&self, account: &MailAccount, to: &str, body: &str) -> Result<String, AppError>;
}

/// 库内已存邮件（头）的元数据，懒加载与回复都依赖它来定位 IMAP 坐标与收件人。
#[derive(Debug, Clone, Default)]
pub struct StoredMessage {
    /// `ph_mail_message.id`。
    pub id: i64,
    /// 所属账户（IMAP/SMTP 连接所需）。
    pub account: MailAccount,
    /// 所属文件夹（IMAP 路径）。
    pub folder: String,
    /// IMAP UID。
    pub uid: u32,
    /// 发件人地址（回复的收件人）。
    pub from_addr: String,
    /// 正文是否已缓存（`body_loaded`）。
    pub body_loaded: bool,
    /// 已缓存的正文（`body_loaded == true` 时有效）。
    pub body: String,
}

/// 邮件存储边界：读取邮件头元数据 + 懒加载后的正文写回（缓存）。
#[async_trait]
pub trait MailStore: Send + Sync {
    /// 按主键读取邮件（头）元数据。
    async fn get_message(&self, msg_id: i64) -> Result<StoredMessage, AppError>;

    /// 懒加载命中后将正文写回库并置 `body_loaded = 1`。
    async fn save_body(&self, msg_id: i64, body: &str) -> Result<(), AppError>;
}

// ============================================================================
// MailGateway 实现
// ============================================================================

/// [`MailGateway`] 的传输无关实现：编排增量游标、懒加载缓存与「发信 + 回写 Sent」。
pub struct MailGatewayImpl<I, S, D>
where
    I: ImapTransport,
    S: SmtpTransport,
    D: MailStore,
{
    imap: I,
    smtp: S,
    store: D,
}

impl<I, S, D> MailGatewayImpl<I, S, D>
where
    I: ImapTransport,
    S: SmtpTransport,
    D: MailStore,
{
    /// 注入三类传输/存储边界，构造网关。
    pub fn new(imap: I, smtp: S, store: D) -> Self {
        Self { imap, smtp, store }
    }
}

/// 仅保留 `uids` 中**最新**的 `limit` 个（已假定升序）。`limit == 0` 视为不限。
fn cap_to_recent(mut uids: Vec<u32>, limit: u32) -> Vec<u32> {
    uids.sort_unstable();
    uids.dedup();
    if limit > 0 && uids.len() as u32 > limit {
        let start = uids.len() - limit as usize;
        uids = uids.split_off(start);
    }
    uids
}

/// 组装一封最小化的、可被 `APPEND` 的原始邮件（仅供回写 Sent 留痕之用）。
fn build_raw_reply(account: &MailAccount, to: &str, body: &str, message_id: &str) -> String {
    format!(
        "Message-ID: {message_id}\r\nFrom: {from}\r\nTo: {to}\r\n\
Subject: Re:\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{body}\r\n",
        from = account.email,
    )
}

#[async_trait]
impl<I, S, D> MailGateway for MailGatewayImpl<I, S, D>
where
    I: ImapTransport,
    S: SmtpTransport,
    D: MailStore,
{
    async fn list_folders(&self, account: &MailAccount) -> Result<Vec<MailFolder>, AppError> {
        self.imap.list_folders(account).await
    }

    async fn sync_folder(&self, folder: &MailFolder, limit: u32) -> Result<SyncReport, AppError> {
        // 增量只拉头：游标为 0 视为首次同步，仅取最近 `limit` 封，避免大邮箱超时；
        // 否则取 UID > last_uid 的部分（防御性再过滤一次，规避 IMAP `N:*` 边界回带）。
        let uids = if folder.last_uid == 0 {
            let recent = self.imap.fetch_recent_uids(&folder.name, limit).await?;
            cap_to_recent(recent, limit)
        } else {
            let mut after = self
                .imap
                .fetch_uids_after(&folder.name, folder.last_uid)
                .await?;
            after.retain(|&u| u > folder.last_uid);
            // 增量也按 limit 截断（单次最多处理 limit 封），游标仍前进到本批最大 UID。
            cap_to_recent(after, limit)
        };

        let new_count = uids.len() as u32;
        // 游标只进不退：取本批最大 UID；若本批为空则维持原游标。
        let last_uid = uids.iter().copied().max().unwrap_or(folder.last_uid);

        Ok(SyncReport {
            new_count,
            last_uid,
        })
    }

    async fn load_body(&self, msg_id: i64) -> Result<String, AppError> {
        let msg = self.store.get_message(msg_id).await?;

        // 命中缓存：直接返回库中正文，不触网。
        if msg.body_loaded {
            return Ok(msg.body);
        }

        // 未缓存：实时拉取正文，写回库（置 body_loaded），再返回。
        let body = self.imap.fetch_body(&msg.folder, msg.uid).await?;
        self.store.save_body(msg_id, &body).await?;
        Ok(body)
    }

    async fn reply(&self, msg_id: i64, body: &str) -> Result<ReplyResult, AppError> {
        let msg = self.store.get_message(msg_id).await?;

        // 1) SMTP 发送（失败直接向上抛，sent 永不会在失败时被置真）。
        let message_id = self.smtp.send(&msg.account, &msg.from_addr, body).await?;

        // 2) IMAP APPEND 写回 Sent。回写失败不应吞掉「已发送」事实：
        //    记日志并标记 appended_to_sent=false，发送本身仍算成功。
        let raw = build_raw_reply(&msg.account, &msg.from_addr, body, &message_id);
        let appended_to_sent = match self.imap.append_to_sent(&msg.account, &raw).await {
            Ok(()) => true,
            Err(e) => {
                tracing::warn!(error = %e, msg_id, "reply: APPEND 写回 Sent 失败（邮件已发送）");
                false
            }
        };

        Ok(ReplyResult {
            sent: true,
            appended_to_sent,
            message_id: Some(message_id),
        })
    }
}

// ============================================================================
// 单元测试（内存 Mock 传输，离线可跑）
// ============================================================================

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::Mutex;

    /// 内存 IMAP Mock：持有一组 UID（升序），记录 APPEND 调用。
    struct MockImap {
        folders: Vec<MailFolder>,
        uids: Vec<u32>,
        bodies: std::collections::HashMap<u32, String>,
        append_ok: bool,
        appended: Mutex<Vec<String>>,
    }

    impl MockImap {
        fn with_uids(uids: Vec<u32>) -> Self {
            Self {
                folders: vec![MailFolder {
                    name: "INBOX".into(),
                    last_uid: 0,
                }],
                uids,
                bodies: std::collections::HashMap::new(),
                append_ok: true,
                appended: Mutex::new(Vec::new()),
            }
        }
    }

    #[async_trait]
    impl ImapTransport for MockImap {
        async fn list_folders(&self, _account: &MailAccount) -> Result<Vec<MailFolder>, AppError> {
            Ok(self.folders.clone())
        }

        async fn fetch_recent_uids(
            &self,
            _folder: &str,
            _limit: u32,
        ) -> Result<Vec<u32>, AppError> {
            // 故意返回全部 UID，验证网关自身会按 limit 截断（首次只进最近 N 封）。
            Ok(self.uids.clone())
        }

        async fn fetch_uids_after(
            &self,
            _folder: &str,
            after_uid: u32,
        ) -> Result<Vec<u32>, AppError> {
            Ok(self
                .uids
                .iter()
                .copied()
                .filter(|&u| u > after_uid)
                .collect())
        }

        async fn fetch_body(&self, _folder: &str, uid: u32) -> Result<String, AppError> {
            Ok(self
                .bodies
                .get(&uid)
                .cloned()
                .unwrap_or_else(|| format!("body-of-uid-{uid}")))
        }

        async fn append_to_sent(
            &self,
            _account: &MailAccount,
            raw_message: &str,
        ) -> Result<(), AppError> {
            if !self.append_ok {
                return Err(AppError::ExternalApi {
                    provider: "imap".into(),
                    detail: "append failed".into(),
                });
            }
            self.appended.lock().unwrap().push(raw_message.to_string());
            Ok(())
        }
    }

    /// 内存 SMTP Mock：记录发送，返回固定 message-id。
    struct MockSmtp {
        sent: Mutex<Vec<(String, String)>>,
        fail: bool,
    }

    impl MockSmtp {
        fn ok() -> Self {
            Self {
                sent: Mutex::new(Vec::new()),
                fail: false,
            }
        }
    }

    #[async_trait]
    impl SmtpTransport for MockSmtp {
        async fn send(
            &self,
            _account: &MailAccount,
            to: &str,
            body: &str,
        ) -> Result<String, AppError> {
            if self.fail {
                return Err(AppError::ExternalApi {
                    provider: "smtp".into(),
                    detail: "send failed".into(),
                });
            }
            self.sent
                .lock()
                .unwrap()
                .push((to.to_string(), body.to_string()));
            Ok("<mock-message-id@xizhen>".to_string())
        }
    }

    /// 内存存储 Mock：单条邮件 + 记录 save_body 调用。
    struct MockStore {
        msg: StoredMessage,
        saved: Mutex<Vec<(i64, String)>>,
    }

    impl MockStore {
        fn new(msg: StoredMessage) -> Self {
            Self {
                msg,
                saved: Mutex::new(Vec::new()),
            }
        }
    }

    #[async_trait]
    impl MailStore for MockStore {
        async fn get_message(&self, msg_id: i64) -> Result<StoredMessage, AppError> {
            if msg_id == self.msg.id {
                Ok(self.msg.clone())
            } else {
                Err(AppError::NotFound)
            }
        }

        async fn save_body(&self, msg_id: i64, body: &str) -> Result<(), AppError> {
            self.saved.lock().unwrap().push((msg_id, body.to_string()));
            Ok(())
        }
    }

    fn account() -> MailAccount {
        MailAccount {
            account: "1".into(),
            email: "kefu@xizhen.jp".into(),
            imap_host: "imap.example.com".into(),
            smtp_host: "smtp.example.com".into(),
        }
    }

    fn gateway(
        imap: MockImap,
        smtp: MockSmtp,
        store: MockStore,
    ) -> MailGatewayImpl<MockImap, MockSmtp, MockStore> {
        MailGatewayImpl::new(imap, smtp, store)
    }

    fn dummy_msg() -> StoredMessage {
        StoredMessage {
            id: 42,
            account: account(),
            folder: "INBOX".into(),
            uid: 100,
            from_addr: "buyer@example.com".into(),
            body_loaded: false,
            body: String::new(),
        }
    }

    #[tokio::test]
    async fn list_folders_delegates_to_transport() {
        let gw = gateway(
            MockImap::with_uids(vec![]),
            MockSmtp::ok(),
            MockStore::new(dummy_msg()),
        );
        let folders = gw.list_folders(&account()).await.unwrap();
        assert_eq!(folders.len(), 1);
        assert_eq!(folders[0].name, "INBOX");
    }

    #[tokio::test]
    async fn first_sync_caps_to_limit_and_advances_cursor() {
        // 首次同步（last_uid == 0），邮箱里有 10 封，limit=3 → 只进最近 3 封，游标到最大 UID。
        let imap = MockImap::with_uids((1..=10).collect());
        let gw = gateway(imap, MockSmtp::ok(), MockStore::new(dummy_msg()));

        let folder = MailFolder {
            name: "INBOX".into(),
            last_uid: 0,
        };
        let report = gw.sync_folder(&folder, 3).await.unwrap();

        assert_eq!(report.new_count, 3, "首次同步应被截断到 limit=3");
        assert_eq!(report.last_uid, 10, "游标应前进到本批最大 UID");
    }

    #[tokio::test]
    async fn incremental_sync_advances_cursor_and_counts_new() {
        // 增量同步：last_uid=7，邮箱有 1..=10 → 新增 8,9,10 共 3 封，游标到 10。
        let imap = MockImap::with_uids((1..=10).collect());
        let gw = gateway(imap, MockSmtp::ok(), MockStore::new(dummy_msg()));

        let folder = MailFolder {
            name: "INBOX".into(),
            last_uid: 7,
        };
        let report = gw.sync_folder(&folder, 200).await.unwrap();

        assert_eq!(report.new_count, 3);
        assert_eq!(report.last_uid, 10);
    }

    #[tokio::test]
    async fn incremental_sync_with_no_new_keeps_cursor() {
        // 没有比游标更大的 UID → 不新增，游标保持不变。
        let imap = MockImap::with_uids((1..=10).collect());
        let gw = gateway(imap, MockSmtp::ok(), MockStore::new(dummy_msg()));

        let folder = MailFolder {
            name: "INBOX".into(),
            last_uid: 10,
        };
        let report = gw.sync_folder(&folder, 200).await.unwrap();

        assert_eq!(report.new_count, 0);
        assert_eq!(report.last_uid, 10);
    }

    #[tokio::test]
    async fn load_body_fetches_and_caches_when_not_loaded() {
        let mut msg = dummy_msg();
        msg.body_loaded = false;
        msg.uid = 100;
        let store = MockStore::new(msg);
        let imap = MockImap::with_uids(vec![]);

        // 用一个会被命中的 gateway，先持有 store 引用以便断言。
        let gw = MailGatewayImpl::new(imap, MockSmtp::ok(), store);
        let body = gw.load_body(42).await.unwrap();

        assert_eq!(body, "body-of-uid-100");
        // 写回缓存被调用一次。
        let saved = gw.store.saved.lock().unwrap();
        assert_eq!(saved.len(), 1);
        assert_eq!(saved[0], (42, "body-of-uid-100".to_string()));
    }

    #[tokio::test]
    async fn load_body_returns_cache_without_fetch() {
        let mut msg = dummy_msg();
        msg.body_loaded = true;
        msg.body = "cached-body".into();
        let gw = gateway(
            MockImap::with_uids(vec![]),
            MockSmtp::ok(),
            MockStore::new(msg),
        );

        let body = gw.load_body(42).await.unwrap();
        assert_eq!(body, "cached-body");
        // 命中缓存时不应写回。
        assert!(gw.store.saved.lock().unwrap().is_empty());
    }

    #[tokio::test]
    async fn reply_sends_and_appends_to_sent() {
        let gw = gateway(
            MockImap::with_uids(vec![]),
            MockSmtp::ok(),
            MockStore::new(dummy_msg()),
        );

        let result = gw.reply(42, "您好，已为您处理").await.unwrap();

        assert!(result.sent, "SMTP 应发送成功");
        assert!(result.appended_to_sent, "应已 APPEND 写回 Sent");
        assert_eq!(
            result.message_id.as_deref(),
            Some("<mock-message-id@xizhen>")
        );

        // SMTP 收到一封发往原发件人的回复。
        let sent = gw.smtp.sent.lock().unwrap();
        assert_eq!(sent.len(), 1);
        assert_eq!(sent[0].0, "buyer@example.com");
        // Sent 文件夹收到一封 APPEND。
        assert_eq!(gw.imap.appended.lock().unwrap().len(), 1);
    }

    #[tokio::test]
    async fn reply_marks_append_false_when_append_fails() {
        let mut imap = MockImap::with_uids(vec![]);
        imap.append_ok = false;
        let gw = gateway(imap, MockSmtp::ok(), MockStore::new(dummy_msg()));

        let result = gw.reply(42, "test").await.unwrap();

        // 发送成功但回写失败：sent=true，appended_to_sent=false。
        assert!(result.sent);
        assert!(!result.appended_to_sent);
        assert!(result.message_id.is_some());
    }
}
