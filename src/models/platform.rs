//! `Platform` 枚举（y/r/w/m/q/yp）与平台元数据，含 `item_code_field()`。
//!
//! 对应设计：Data Models · 3.3.1 平台字段映射、3.8 主库表结构（platform_catalog）、
//! 8.3 商品编码归一。一个平台对应一个 `code`（侧栏菜单与 `platform_catalog.code` 一致），
//! 不同平台「商品编码」字段名不同：`y/r`=`ItemId`、`w/q`=`itemCode`、`m/yp`=`lotnumber`。

use serde::{Deserialize, Serialize};

/// 平台标识。取代旧系统「6 个目录 + 6 张表」，统一用单一枚举标识。
///
/// `code`：`y/r/w/m/q/yp`（与 `platform_catalog.code` / 侧栏菜单一致）。
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, Serialize, Deserialize)]
pub enum Platform {
    /// `y` — Yahoo购物
    Yahoo,
    /// `r` — 乐天（Rakuten）
    Rakuten,
    /// `w` — Wowma
    Wowma,
    /// `m` — Mercari
    Mercari,
    /// `q` — Qoo10
    Qoo10,
    /// `yp` — 雅虎拍卖（Yahoo Auction）
    YahooAuction,
}

impl Platform {
    /// 所有平台，按侧栏排序（见设计 3.8 名称序：Yahoo购物/乐天/Wowma/Mercari/Qoo10/雅虎拍卖）。
    pub const ALL: [Platform; 6] = [
        Platform::Yahoo,
        Platform::Rakuten,
        Platform::Wowma,
        Platform::Mercari,
        Platform::Qoo10,
        Platform::YahooAuction,
    ];

    /// 平台代码（`y/r/w/m/q/yp`），与 `platform_catalog.code` 一致。
    pub fn code(&self) -> &'static str {
        match self {
            Platform::Yahoo => "y",
            Platform::Rakuten => "r",
            Platform::Wowma => "w",
            Platform::Mercari => "m",
            Platform::Qoo10 => "q",
            Platform::YahooAuction => "yp",
        }
    }

    /// 展示名称（见设计 3.8 `platform_catalog.name`）。
    pub fn display_name(&self) -> &'static str {
        match self {
            Platform::Yahoo => "Yahoo购物",
            Platform::Rakuten => "乐天",
            Platform::Wowma => "Wowma",
            Platform::Mercari => "Mercari",
            Platform::Qoo10 => "Qoo10",
            Platform::YahooAuction => "雅虎拍卖",
        }
    }

    /// 侧栏排序（从 1 开始，见设计 3.8）。
    pub fn sort_order(&self) -> u8 {
        match self {
            Platform::Yahoo => 1,
            Platform::Rakuten => 2,
            Platform::Wowma => 3,
            Platform::Mercari => 4,
            Platform::Qoo10 => 5,
            Platform::YahooAuction => 6,
        }
    }

    /// 平台特有的「商品编码」字段名（见设计 8.3 / 3.3.1）。
    ///
    /// - `y`/`r` => `ItemId`
    /// - `w`/`q` => `itemCode`
    /// - `m`/`yp` => `lotnumber`
    ///
    /// 迁移工具与运行时导入映射器共用此规则，把对应列归一进 `order_items.item_code`。
    pub fn item_code_field(&self) -> &'static str {
        match self {
            Platform::Yahoo | Platform::Rakuten => "ItemId",
            Platform::Wowma | Platform::Qoo10 => "itemCode",
            Platform::Mercari | Platform::YahooAuction => "lotnumber",
        }
    }

    /// 由平台代码（`y/r/w/m/q/yp`）解析为 `Platform`，未知代码返回 `None`。
    pub fn from_code(code: &str) -> Option<Platform> {
        match code {
            "y" => Some(Platform::Yahoo),
            "r" => Some(Platform::Rakuten),
            "w" => Some(Platform::Wowma),
            "m" => Some(Platform::Mercari),
            "q" => Some(Platform::Qoo10),
            "yp" => Some(Platform::YahooAuction),
            _ => None,
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn item_code_field_matches_design_8_3() {
        assert_eq!(Platform::Yahoo.item_code_field(), "ItemId");
        assert_eq!(Platform::Rakuten.item_code_field(), "ItemId");
        assert_eq!(Platform::Wowma.item_code_field(), "itemCode");
        assert_eq!(Platform::Qoo10.item_code_field(), "itemCode");
        assert_eq!(Platform::Mercari.item_code_field(), "lotnumber");
        assert_eq!(Platform::YahooAuction.item_code_field(), "lotnumber");
    }

    #[test]
    fn code_and_from_code_round_trip() {
        for p in Platform::ALL {
            assert_eq!(Platform::from_code(p.code()), Some(p));
        }
    }

    #[test]
    fn from_code_known_values() {
        assert_eq!(Platform::from_code("y"), Some(Platform::Yahoo));
        assert_eq!(Platform::from_code("r"), Some(Platform::Rakuten));
        assert_eq!(Platform::from_code("w"), Some(Platform::Wowma));
        assert_eq!(Platform::from_code("m"), Some(Platform::Mercari));
        assert_eq!(Platform::from_code("q"), Some(Platform::Qoo10));
        assert_eq!(Platform::from_code("yp"), Some(Platform::YahooAuction));
    }

    #[test]
    fn from_code_unknown_returns_none() {
        assert_eq!(Platform::from_code(""), None);
        assert_eq!(Platform::from_code("Y"), None);
        assert_eq!(Platform::from_code("x"), None);
        assert_eq!(Platform::from_code("ym"), None);
    }

    #[test]
    fn display_name_matches_catalog() {
        assert_eq!(Platform::Yahoo.display_name(), "Yahoo购物");
        assert_eq!(Platform::Rakuten.display_name(), "乐天");
        assert_eq!(Platform::Wowma.display_name(), "Wowma");
        assert_eq!(Platform::Mercari.display_name(), "Mercari");
        assert_eq!(Platform::Qoo10.display_name(), "Qoo10");
        assert_eq!(Platform::YahooAuction.display_name(), "雅虎拍卖");
    }

    #[test]
    fn sort_order_is_unique_and_sequential() {
        let mut orders: Vec<u8> = Platform::ALL.iter().map(|p| p.sort_order()).collect();
        orders.sort_unstable();
        assert_eq!(orders, vec![1, 2, 3, 4, 5, 6]);
    }

    #[test]
    fn serde_round_trip() {
        for p in Platform::ALL {
            let json = serde_json::to_string(&p).unwrap();
            let back: Platform = serde_json::from_str(&json).unwrap();
            assert_eq!(back, p);
        }
    }
}
