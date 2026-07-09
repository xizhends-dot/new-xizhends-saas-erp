# 平台订单字段与旧系统字段对照

本文记录新系统 `php-saas` 平台订单页与旧系统各平台订单页的字段对应关系。

对照来源：

- 新系统字段配置：`php-saas/app/Services/OrderPageConfigRegistry.php`
- 新系统平台订单列表：`php-saas/app/Views/tenant/partials/order_block.php`
- 旧系统平台订单页：
  - 乐天：`old/orderr/inc_list_default.php`
  - Yahoo购物：`old/ordery/inc_list_default.php`
  - Wowma：`old/orderw/inc_list_default.php`
  - Mercari：`old/orderm/inc_list_default.php`
  - 雅虎拍卖：`old/orderyp/inc_list_default.php`
  - Qoo10：`old/orderq/inc_list_default.php`

说明：

- “新系统 key”是新系统统一后的字段 key。
- “旧系统字段/参数”优先写旧系统表单参数；列表展示字段则写旧系统数据库字段或模板取值字段。
- 新系统为了统一六个平台，部分列表列是合并列，例如“订单ID / 店铺”“ItemId / lotNumber”“邮费/手续费”“总价/请求金额”。
- 旧系统有些字段只在列表出现，不一定有筛选项；有些筛选项在旧系统各平台命名不同。

## 乐天 Rakuten

旧系统目录：`old/orderr/`

### 筛选字段

| 新系统显示名 | 新系统 key | 旧系统标签 | 旧系统字段/参数 | 备注 |
|---|---|---|---|---|
| 订单号 | `order_no` | 订单号 | `orderId` | 列表数据字段为 `OrderId` |
| 1688订单号 | `tabaono` | 1688订单号 | `tabaono` | 采购单号 |
| ItemId查询 | `item_id` | ItemId查询 | `ItemId` | 乐天商品 ID |
| 国内发货单号 | `cn_ship_no` | 国内发货单号 | `shipno` | 国内快递单号 |
| 国际发货单号 | `intl_ship_no` | 国际发货单号 | `shipnumber` | 日本国际运单号 |
| 国内签收地 | `receipt_city` | 国内签收地 | `receipt_city` | 旧系统下拉 |
| 货源地 | `source` | 无 | 无 | 新系统统一字段，旧系统没有独立筛选 |
| 采购状态 | `status` | 采购状态设置 | `beizhu` | 旧系统默认排除一批已完成/取消状态 |
| 店铺 | `store` | 店铺选择 | `shop_select` / `user_name` | 旧系统按店铺账号过滤 |
| 每页显示 | `page_size` | 每页显示 | `npage` | 旧系统可选 200/500/1000/2000/5000 等 |
| 客人姓名 | `customer_name` | 客人姓名 / 收件人姓名 | `sendname` | 列表字段为 `ShipName` |
| 客人电话 | `phone` | 客人电话 | `sendphone` | 列表字段为 `ShipPhoneNumber` |
| 客人邮箱 | `mail` | 客人邮箱 | `mails` | 列表字段为 `BillMailAddress` |
| 片假名 | `kana` | 片假名查询 | `pianjiaming` | 列表字段为 `senderKana` |
| 运送方式 | `ship_method` | 运送方式 | `yunshu` | 列表显示取 `ShipAddress2` |
| 物流公司 | `carrier` | 无独立筛选 | `shipcompany` | 旧系统列表字段 |
| 订单备注 | `comment` | 订单备注查询 | `comment` | 旧系统订单备注 |
| 国际运单状态 | `intl_ship_empty` | 未出国际单号/已有国际单号 | `kong` | 新系统统一成下拉 |
| 飞兔推送 | `frb_push` | 无 | 无 | 新系统字段 |
| 邀评状态 | `review_invited` | 邀评状态 | `invite_review` | 乐天有邀评 |
| 评价状态 | `reviewed` | 评价状态 | `reviewed` | 乐天有评价 |
| 日期范围 | `date_range` | 按订单时间，查询 | `OrderTime` / `OrderTime2` | 旧系统也有按导入时间 `cdate` / `cdate2` |
| 超时发货 | `late_ship` | 超时发货 | `chaoshifahuo` | 旧系统按采购状态和采购时间判断 |
| 【日本】配達中 | `in_delivery` | 配達中 | `haitatsuchuu` | 物流状态筛选 |
| 【日本】配達完了 | `delivered` | 配達完了 | `haitatsukanryo` | 物流状态筛选 |

### 列表展示字段

| 新系统显示名 | 旧系统标签 | 旧系统字段 | 备注 |
|---|---|---|---|
| 导入时间 | 导入时间 | `cdate` | 订单头部 |
| 客人姓名/片假名 | `senderName` / `senderKana` | `ShipName` / `senderKana` | 新系统合并显示 |
| 地址 | 地址 | `ShipPrefecture` + `ShipCity` + `ShipAddress1` | 旧系统未合并运送方式字段 |
| 邮编 | 邮编 | `ShipZipCode` |  |
| 电话 | 电话 | `ShipPhoneNumber` |  |
| 邮箱 | 邮箱 | `BillMailAddress` |  |
| 支付方式 | 支付方式 | `PayMethodName` |  |
| 运送方式 | 运送方式 | `ShipAddress2` | 旧系统列表这样取值 |
| 邀评/评价 | 已邀评 / 已评价 | `invite_review` / `reviewed` | 新系统合并成“邀/评”标记 |
| 图片 | 采购图片 | `zhutu` | 旧系统图片下方还显示店铺全称链接 |
| 订单ID / 店铺 | 订单ID | `OrderId` + `dpid` / `dpquancheng` | 新系统合并订单号与店铺 |
| 订单时间 / 明细ID | 订单时间 | `OrderTime` / `LineId` | 旧系统 `LineId` 表头被注释，但字段存在 |
| 货源地 / 采购状态 | 采购状态 | `beizhu` | “货源地”是新系统统一字段 |
| ItemId / lotNumber | ItemId | `ItemId` | 乐天没有 lotNumber |
| 日本仓ID / 管理ID | 商品日本仓ID | `ItemManagerId` |  |
| 商品属性 | 商品属性 | `SubCodeOption` |  |
| 商品标题 / 项目选择 | 項目・選択肢 | `selectedChoice` | 新系统把项目选择放入商品标题列副信息 |
| 数量 | 数量 | `Quantity` |  |
| 单价 | 单价 | `UnitPrice` | 旧系统悬浮核价使用 `UnitPrice + ShipCharge` |
| 邮费/手续费 | 邮费 / 手续费 | `ShipCharge` / `PayCharge` | 新系统合并为一列 |
| 总价/请求金额 | 请求金额 | `requestPrice` | 乐天旧系统主显示请求金额 |
| 采购人 | 采购人 | `caigou_user` | 旧系统常和采购状态一起显示 |
| 采购时间 | 采购时间 | `caigoutime` |  |
| 采购链接 | 采购链接地址 | `caigoulink` |  |
| 订单备注 | 订单备注 | `comment` |  |
| 采购金额 | 采购金额 | `amount` |  |
| 1688订单号 | 1688订单号 | `tabaono` |  |
| 物流公司 | 物流公司 | `shipcompany` | 同格会显示 `logisticstatus` |
| 国内运单号 / 签收地 | 国内运单号 | `shipno` + `receipt_city` | 旧系统同格显示货运链接和签收地 |
| 国际运单号 | 国际运单号 | `shipnumber` |  |
| 国际运单状态 | 国际运单状态 | `jpshipdetails` + `jpship_completed_at` |  |
| 国际运费 | 国际运费 | `comamount` |  |
| 件数 | 件数 | `shipquantity` |  |
| 产品重量 | 产品重量 | `weight` |  |
| 利润(RMB) | 无明显列 | `cnamount` | 乐天旧国际区未稳定展示利润列 |

## Yahoo购物

旧系统目录：`old/ordery/`

### 筛选字段

| 新系统显示名 | 新系统 key | 旧系统标签 | 旧系统字段/参数 | 备注 |
|---|---|---|---|---|
| 订单号 | `order_no` | 订单号 | `orderId` | 列表数据字段为 `OrderId` |
| 1688订单号 | `tabaono` | 1688订单号 | `tabaono` |  |
| ItemId查询 | `item_id` | ItemId查询 | `ItemId` | Yahoo 商品 ID |
| 国内发货单号 | `cn_ship_no` | 国内发货单号 | `shipno` |  |
| 国际发货单号 | `intl_ship_no` | 国际发货单号 | `shipnumber` |  |
| 国内签收地 | `receipt_city` | 国内签收地 | `receipt_city` |  |
| 货源地 | `source` | 无 | 无 | 新系统统一字段 |
| 采购状态 | `status` | 采购状态设置 | `beizhu` |  |
| 店铺 | `store` | 店铺选择 | `shop_select` / `user_name` |  |
| 每页显示 | `page_size` | 每页显示 | `npage` |  |
| 客人姓名 | `customer_name` | 客人姓名 / 收件人姓名 | `sendname` | 列表字段为 `ShipName` |
| 客人电话 | `phone` | 客人电话 | `sendphone` | 列表字段为 `ShipPhoneNumber` |
| 客人邮箱 | `mail` | 客人邮箱 | `mails` | 列表字段为 `BillMailAddress` |
| 片假名 | `kana` | 片假名查询 | `senderKana` | 部分旧模板未作为列表头显示 |
| 运送方式 | `ship_method` | 运送方式 | `PayStatus` | 旧列表标签是“客人运送方式” |
| 采购链接 | `purchase_link` | 采购链接查询 | `caigoulink` | Yahoo 旧系统有该筛选 |
| 订单备注 | `comment` | 订单备注查询 | `comment` |  |
| 国际运单状态 | `intl_ship_empty` | 未出国际单号/已有国际单号 | `kong` |  |
| 飞兔推送 | `frb_push` | 无 | 无 | 新系统字段 |
| 邀评状态 | `review_invited` | 邀评状态 | `invite_review` | Yahoo 有邀评 |
| 评价状态 | `reviewed` | 评价状态 | `reviewed` | Yahoo 有评价 |
| 日期范围 | `date_range` | 按订单时间，查询 | `OrderTime` / `OrderTime2` | 旧系统也有按导入时间 `cdate` / `cdate2` |
| 超时发货 | `late_ship` | 超时发货 | `chaoshifahuo` |  |
| 【日本】配達中 | `in_delivery` | 配達中 | `haitatsuchuu` | 物流状态筛选 |
| 【日本】配達完了 | `delivered` | 配達完了 | `haitatsukanryo` | 物流状态筛选 |

### 列表展示字段

| 新系统显示名 | 旧系统标签 | 旧系统字段 | 备注 |
|---|---|---|---|
| 导入时间 | 导入时间 | `cdate` |  |
| 客人姓名/片假名 | ShipName | `ShipName` / `senderKana` | 新系统可合并片假名 |
| 地址 | 地址 | `ShipPrefecture` + `ShipCity` + `ShipAddress1` + `ShipAddress2` |  |
| 邮编 | 邮编 | `ShipZipCode` |  |
| 电话 | 电话 | `ShipPhoneNumber` |  |
| 邮箱 | 邮箱 | `BillMailAddress` |  |
| 支付方式 | 支付方式 | `PayMethodName` |  |
| 运送方式 | 客人运送方式 | `PayStatus` |  |
| 订单状态 | 订单状态 | `OrderStatus` |  |
| 邀评/评价 | 已邀评 / 已评价 | `invite_review` / `reviewed` |  |
| 图片 | 采购图片 | `zhutu` |  |
| 订单ID / 店铺 | 订单ID | `OrderId` + `dpid` / `user_name` | 新系统合并店铺 |
| 订单时间 / 明细ID | 订单时间 | `OrderTime` / `LineId` | 旧系统 `LineId` 表头被注释 |
| 货源地 / 采购状态 | 采购状态 | `beizhu` |  |
| ItemId / lotNumber | ItemId | `ItemId` | Yahoo 没有 lotNumber |
| 商品属性 | 商品属性 | `SubCodeOption` |  |
| 数量 | 数量 | `Quantity` |  |
| 单价 | 单价 | `UnitPrice` |  |
| 邮费/手续费 | 邮费 / 手续费 | `ShipCharge` / `PayCharge` |  |
| 总价/请求金额 | 总价 | `TotalPrice` 或 `UnitPrice * Quantity + ShipCharge + PayCharge` |  |
| 采购人 | 采购人 | `cg_comment` 在旧表头位置显示采购备注/采购人相关信息 | 旧系统此列语义不够统一 |
| 采购时间 | 采购时间 | `caigoutime` |  |
| 采购链接 | 采购链接地址 | `caigoulink` |  |
| 订单备注 | 订单备注 | `comment` |  |
| 采购金额 | 采购金额 | `amount` |  |
| 1688订单号 | 1688订单号 | `tabaono` |  |
| 物流公司 | 物流公司 | `shipcompany` | 同格会显示 `logisticstatus` |
| 国内运单号 / 签收地 | 国内运单号 | `shipno` + `receipt_city` |  |
| 国际运单号 | 国际运单号 | `shipnumber` |  |
| 国际运单状态 | 国际运单状态 | `jpshipdetails` + `jpship_completed_at` |  |
| 国际运费 | 国际运费 | `comamount` |  |
| 件数 | 件数 | `shipquantity` |  |
| 产品重量 | 产品重量(kgs) | `weight` |  |
| 利润(RMB) | 利润(RMB) | `cnamount` |  |

## Wowma

旧系统目录：`old/orderw/`

### 筛选字段

| 新系统显示名 | 新系统 key | 旧系统标签 | 旧系统字段/参数 | 备注 |
|---|---|---|---|---|
| 订单号 | `order_no` | 订单号 | `ziid` | 列表数据字段为 `orderId` |
| 1688订单号 | `tabaono` | 1688订单号 | `tabaono` |  |
| ItemId查询 | `item_id` | ItemId查询 | `itemManagementId` | 旧标签叫 ItemId查询，实际参数是 `itemManagementId` |
| 国内发货单号 | `cn_ship_no` | 国内发货单号 | `shipno` |  |
| 国际发货单号 | `intl_ship_no` | 国际发货单号 | `shipnumber` |  |
| 国内签收地 | `receipt_city` | 国内签收地 | `receipt_city` |  |
| 货源地 | `source` | 无 | 无 | 新系统统一字段 |
| 采购状态 | `status` | 采购状态设置 | `beizhu` |  |
| 店铺 | `store` | 店铺番号 / 店铺选择 | `user_name` / `dpid` | 旧系统列表显示店铺番号 |
| 每页显示 | `page_size` | 每页显示 | `npage` |  |
| 客人姓名 | `customer_name` | 收件人查询 | `sendname` | 列表字段为 `senderName` |
| 客人电话 | `phone` | 客人电话 | `sendphone` | 列表字段为 `senderPhoneNumber1` |
| 客人邮箱 | `mail` | 客人邮箱 | `mails` | 列表字段为 `mailAddress` |
| 订单明细ID | `order_detail_id` | 子订单ID | `orderDetailId` | 旧列表字段 |
| lotNumber | `lot_number` | lotNumber | `lotnumber` | 旧系统有筛选；新系统当前 Wowma 筛选不显示该 key |
| 片假名 | `kana` | 片假名查询 | `senderKana` |  |
| 支付方式 | `pay_method` | 支付方式查询 | `settlementName` | 新系统 Wowma 显示该筛选 |
| 运送方式 | `ship_method` | 运送方式 | `deliveryName` |  |
| 物流公司 | `carrier` | 无独立筛选 | `shipcompany` | 旧系统列表字段 |
| 订单备注 | `comment` | 订单备注查询 | `comment` |  |
| 国际运单状态 | `intl_ship_empty` | 未出国际单号/已有国际单号 | `kong` |  |
| 飞兔推送 | `frb_push` | 无 | 无 | 新系统字段 |
| 日期范围 | `date_range` | 按订单时间，查询 | `orderDate` / `orderDate2` | 旧系统也有按导入时间 `cdate` / `cdate2` |
| 超时发货 | `late_ship` | 超时发货 | `chaoshifahuo` |  |
| 【日本】配達中 | `in_delivery` | 配達中 | `haitatsuchuu` | 物流状态筛选 |
| 【日本】配達完了 | `delivered` | 配達完了 | `haitatsukanryo` | 物流状态筛选 |

### 列表展示字段

| 新系统显示名 | 旧系统标签 | 旧系统字段 | 备注 |
|---|---|---|---|
| 导入时间 | 导入时间 | `cdate` |  |
| 客人姓名/片假名 | `senderName` / `senderKana` | `senderName` / `senderKana` |  |
| 地址 | 地址 | `senderAddress` |  |
| 邮编 | 邮编 | `senderZipCode` |  |
| 电话 | 电话 | `senderPhoneNumber1` |  |
| 邮箱 | 邮箱 | `mailAddress` |  |
| 支付方式 | 支付方式 | `settlementName` |  |
| 运送方式 | 运送方式 | `deliveryName` |  |
| 订单状态 | 订单状态 | `orderStatus` |  |
| 图片 | 采购图片 | `zhutu` |  |
| 订单ID / 店铺 | 订单ID / 店铺番号 | `orderId` + `user_name` / `dpid` / `dpquancheng` | 新系统合并显示 |
| 订单时间 / 明细ID | 订单时间 / 子订单ID | `orderDate` / `orderDetailId` |  |
| 货源地 / 采购状态 | 采购状态 | `beizhu` |  |
| ItemId / lotNumber | ItemID / lotNumber | `itemManagementId` / `lotnumber` | 新系统合并显示 |
| 商品属性 | 商品属性 | `itemOption` |  |
| 商品标题 / 项目选择 | 商品加价属性 | `itemOptionCommission1` 到 `itemOptionCommission5` | 新系统放在副信息 |
| 数量 | 数量 | `unit` |  |
| 单价 | 单价 | `itemPrice` 或 `totalItemPrice` | 旧系统根据字段是否存在选择显示 |
| 邮费/手续费 | 邮费 | `postagePrice` | Wowma 旧系统无独立手续费列 |
| 总价/请求金额 | 总价 | `totalPrice` |  |
| 采购时间 | 采购时间 | `caigoutime` |  |
| 采购链接 | 采购链接地址 | `caigoulink` |  |
| 订单备注 | 订单备注 | `comment` |  |
| 采购金额 | 采购金额 | `amount` |  |
| 1688订单号 | 1688订单号 | `tabaono` |  |
| 物流公司 | 物流公司 | `shipcompany` | 同格会显示 `logisticstatus` |
| 国内运单号 / 签收地 | 国内运单号 | `shipno` + `receipt_city` |  |
| 国际运单号 | 国际运单号 | `shipnumber` |  |
| 国际运单状态 | 国际运单状态 | `jpshipdetails` + `jpship_completed_at` |  |
| 国际运费 | 国际运费 | `comamount` |  |
| 件数 | 件数 | `shipquantity` |  |
| 产品重量 | 产品重量(kgs) | `weight` |  |
| 利润(RMB) | 利润(RMB) | `cnamount` |  |

## Mercari

旧系统目录：`old/orderm/`

### 筛选字段

| 新系统显示名 | 新系统 key | 旧系统标签 | 旧系统字段/参数 | 备注 |
|---|---|---|---|---|
| 订单号 | `order_no` | 订单号 | `ziid` | 列表数据字段为 `orderId` |
| 1688订单号 | `tabaono` | 1688订单号 | `tabaono` |  |
| ItemId查询 | `item_id` | ItemId查询 | `itemManagementId` | 旧系统列表实际显示 `itemCode` |
| 国内发货单号 | `cn_ship_no` | 国内发货单号 | `shipno` |  |
| 国际发货单号 | `intl_ship_no` | 国际发货单号 | `shipnumber` |  |
| 国内签收地 | `receipt_city` | 国内签收地 | `receipt_city` |  |
| 货源地 | `source` | 无 | 无 | 新系统统一字段 |
| 采购状态 | `status` | 采购状态设置 | `beizhu` |  |
| 店铺 | `store` | 店铺番号 / 店铺选择 | `user_name` / `dpid` |  |
| 每页显示 | `page_size` | 每页显示 | `npage` |  |
| 客人姓名 | `customer_name` | 收件人查询 | `sendname` | 列表字段为 `senderName` |
| 客人电话 | `phone` | 客人电话 | `sendphone` | 列表字段为 `senderPhoneNumber1` |
| 客人邮箱 | `mail` | 客人邮箱 | `mails` | 列表字段为 `mailAddress` |
| lotNumber | `lot_number` | lotNumber | `lotnumber` | Mercari 新旧都重点使用 |
| lotNumber为空 | `lot_number_empty` | lotNumber 空 | `lotnumber_empty` | 旧系统复选框文字为“空” |
| 片假名 | `kana` | 片假名查询 | `senderKana` |  |
| 运送方式 | `ship_method` | 运送方式 | `deliveryName` |  |
| 物流公司 | `carrier` | 无独立筛选 | `shipcompany` |  |
| 订单备注 | `comment` | 订单备注查询 | `comment` |  |
| 国际运单状态 | `intl_ship_empty` | 未出国际单号/已有国际单号 | `kong` |  |
| 飞兔推送 | `frb_push` | 无 | 无 | 新系统字段 |
| 日期范围 | `date_range` | 按订单时间，查询 | `orderDate` / `orderDate2` | 旧系统也有按导入时间 `cdate` / `cdate2` |
| 超时发货 | `late_ship` | 超时发货 | `chaoshifahuo` |  |
| 【日本】配達中 | `in_delivery` | 配達中 | `haitatsuchuu` | 物流状态筛选 |
| 【日本】配達完了 | `delivered` | 配達完了 | `haitatsukanryo` | 物流状态筛选 |

### 列表展示字段

| 新系统显示名 | 旧系统标签 | 旧系统字段 | 备注 |
|---|---|---|---|
| 导入时间 | 导入时间 | `cdate` |  |
| 客人姓名/片假名 | `senderName` / senderKana | `senderName` / `senderKana` | 旧 Mercari 头部注释过 `senderKana` |
| 地址 | 地址 | `shipping_state` + `shipping_city` + `shipping_address_1` + `shipping_address_2` |  |
| 邮编 | 邮编 | `shipping_postal_code` |  |
| 电话 | 电话 | `senderPhoneNumber1` |  |
| 邮箱 | 邮箱 | `mailAddress` |  |
| 支付方式 | 支付方式 | `settlementName` |  |
| 运送方式 | 运送方式 | `deliveryName` |  |
| 订单状态 | 订单状态 | `orderStatus` |  |
| 图片 | 采购图片 | `zhutu` |  |
| 订单ID / 店铺 | 订单ID / 店铺番号 | `orderId` + `user_name` / `dpid` / `dpquancheng` |  |
| 订单时间 / 明细ID | 订单时间 | `orderDate` | Mercari 旧列表没有稳定子订单ID列 |
| 货源地 / 采购状态 | 采购状态 | `beizhu` |  |
| ItemId / lotNumber | ItemID / lotNumber | `itemCode` / `lotnumber` | 旧列表 ItemID 取 `itemCode` |
| 商品属性 | 商品属性 | `itemOption` |  |
| 商品标题 / 项目选择 | 商品标题 | `product_title` |  |
| 数量 | 数量 | `unit` |  |
| 单价 | 商品单价 | `totalItemPrice` |  |
| 邮费/手续费 | 邮费 | `postagePrice` |  |
| 总价/请求金额 | 无单独总价列 | `totalItemPrice + postagePrice` | 新系统统一显示总价 |
| 采购时间 | 采购时间 | `caigoutime` |  |
| 采购链接 | 采购链接地址 | `caigoulink` |  |
| 订单备注 | 订单备注 | `comment` |  |
| 采购金额 | 采购金额 | `amount` |  |
| 1688订单号 | 1688订单号 | `tabaono` |  |
| 物流公司 | 物流公司 | `shipcompany` | 同格会显示 `logisticstatus` |
| 国内运单号 / 签收地 | 国内运单号 | `shipno` + `receipt_city` |  |
| 国际运单号 | 国际运单号 | `shipnumber` |  |
| 国际运单状态 | 国际运单状态 | `jpshipdetails` + `jpship_completed_at` |  |
| 国际运费 | 国际运费 | `comamount` |  |
| 件数 | 件数 | `shipquantity` |  |
| 产品重量 | 产品重量(kgs) | `weight` |  |
| 利润(RMB) | 利润(RMB) | `cnamount` |  |

## 雅虎拍卖 Yahoo Auction

旧系统目录：`old/orderyp/`

### 筛选字段

| 新系统显示名 | 新系统 key | 旧系统标签 | 旧系统字段/参数 | 备注 |
|---|---|---|---|---|
| 订单号 | `order_no` | 订单号 | `ziid` | 旧系统同时搜索 `orderId` 和 `orderDetailId` |
| 1688订单号 | `tabaono` | 1688订单号 | `tabaono` |  |
| 国内发货单号 | `cn_ship_no` | 国内发货单号 | `shipno` |  |
| 国际发货单号 | `intl_ship_no` | 国际发货单号 | `shipnumber` |  |
| 国内签收地 | `receipt_city` | 国内签收地 | `receipt_city` |  |
| 货源地 | `source` | 无 | 无 | 新系统统一字段 |
| 采购状态 | `status` | 采购状态设置 | `beizhu` |  |
| 店铺 | `store` | 店铺番号 / 店铺选择 | `user_name` / `dpid` |  |
| 每页显示 | `page_size` | 每页显示 | `npage` |  |
| 客人姓名 | `customer_name` | 收件人查询 | `sendname` | 列表字段为 `senderName` |
| 客人电话 | `phone` | 客人电话 | `sendphone` | 列表字段为 `senderPhoneNumber1` |
| 客人邮箱 | `mail` | 客人邮箱 | `mails` | 旧头部没有稳定邮箱展示列 |
| 订单明细ID | `order_detail_id` | 订单号查询同时支持子订单ID | `orderDetailId` | 旧系统将订单号和子订单ID一起搜 |
| lotNumber | `lot_number` | lotNumber | `lotnumber` |  |
| lotNumber为空 | `lot_number_empty` | lotNumber 空 | `lotnumber_empty` | 旧系统复选框文字为“空” |
| 商品标题 | `product_name` | 标题查询 | `product_title` | 新系统雅拍显示该筛选 |
| 物流公司 | `carrier` | 无独立筛选 | `shipcompany` |  |
| 订单备注 | `comment` | 订单备注查询 | `comment` |  |
| 国际运单状态 | `intl_ship_empty` | 未出国际单号/已有国际单号 | `kong` |  |
| 飞兔推送 | `frb_push` | 无 | 无 | 新系统字段 |
| 日期范围 | `date_range` | 按订单时间，查询 | `orderDate` / `orderDate2` | 旧系统也有按导入时间 `cdate` / `cdate2` |
| 超时发货 | `late_ship` | 超时发货 | `chaoshifahuo` |  |
| 【日本】配達中 | `in_delivery` | 配達中 | `haitatsuchuu` | 物流状态筛选 |
| 【日本】配達完了 | `delivered` | 配達完了 | `haitatsukanryo` | 物流状态筛选 |

### 列表展示字段

| 新系统显示名 | 旧系统标签 | 旧系统字段 | 备注 |
|---|---|---|---|
| 导入时间 | 导入时间 | `cdate` |  |
| 客人姓名/片假名 | senderName | `senderName` | 旧雅拍头部注释了 `senderKana` |
| 地址 | 地址 | `shipping_address_1` | 旧系统主要显示地址1 |
| 邮编 | 邮编 | `shipping_postal_code` |  |
| 电话 | 电话 | `senderPhoneNumber1` |  |
| 支付方式 | 支付方式 | `settlementName` |  |
| 运送方式 | 运送方式 | `deliveryName` |  |
| 订单状态 | 订单状态 | `orderStatus` |  |
| 图片 | 采购图片 | `zhutu` |  |
| 订单ID / 店铺 | 订单ID / 店铺番号 | `orderId` + `user_name` / `dpid` / `dpquancheng` |  |
| 订单时间 / 明细ID | 订单时间 | `orderDate` | 旧雅拍列表没有稳定子订单ID列 |
| 货源地 / 采购状态 | 采购状态 | `beizhu` |  |
| ItemId / lotNumber | ItemID / lotNumber | `itemCode` / `lotnumber` | 新系统雅拍筛选隐藏 ItemId，但列表仍展示统一列 |
| 商品属性 | 商品属性 | `itemOption` |  |
| 商品标题 / 项目选择 | 商品标题 | `product_title` |  |
| 数量 | 数量 | `unit` |  |
| 单价 | 商品单价 | `totalItemPrice` |  |
| 邮费/手续费 | 邮费 | `postagePrice` |  |
| 总价/请求金额 | 总价 | `totalItemPrice + postagePrice` | 旧系统计算显示 |
| 采购时间 | 采购时间 | `caigoutime` |  |
| 采购链接 | 采购链接地址 | `caigoulink` |  |
| 订单备注 | 订单备注 | `comment` |  |
| 采购金额 | 采购金额 | `amount` |  |
| 1688订单号 | 1688订单号 | `tabaono` |  |
| 物流公司 | 物流公司 | `shipcompany` | 同格会显示 `logisticstatus` |
| 国内运单号 / 签收地 | 国内运单号 | `shipno` + `receipt_city` |  |
| 国际运单号 | 国际运单号 | `shipnumber` |  |
| 国际运单状态 | 国际运单状态 | `jpshipdetails` + `jpship_completed_at` |  |
| 国际运费 | 国际运费 | `comamount` |  |
| 件数 | 件数 | `shipquantity` |  |
| 产品重量 | 产品重量(kgs) | `weight` |  |
| 利润(RMB) | 利润(RMB) | `cnamount` |  |

## Qoo10

旧系统目录：`old/orderq/`

### 筛选字段

| 新系统显示名 | 新系统 key | 旧系统标签 | 旧系统字段/参数 | 备注 |
|---|---|---|---|---|
| 订单号 | `order_no` | 订单号 | `ziid` | 列表数据字段为 `orderId` |
| 1688订单号 | `tabaono` | 1688订单号 | `tabaono` |  |
| ItemId查询 | `item_id` | ItemId查询 | `itemManagementId` | 旧标签叫 ItemId查询，实际参数是 `itemManagementId` |
| 国内发货单号 | `cn_ship_no` | 国内发货单号 | `shipno` |  |
| 国际发货单号 | `intl_ship_no` | 国际发货单号 | `shipnumber` |  |
| 国内签收地 | `receipt_city` | 国内签收地 | `receipt_city` |  |
| 货源地 | `source` | 无 | 无 | 新系统统一字段 |
| 采购状态 | `status` | 采购状态设置 | `beizhu` |  |
| 店铺 | `store` | 店铺番号 / 店铺选择 | `user_name` / `dpid` |  |
| 每页显示 | `page_size` | 每页显示 | `npage` |  |
| 客人姓名 | `customer_name` | 收件人查询 | `sendname` | 列表字段为 `senderName` |
| 客人电话 | `phone` | 客人电话 | `sendphone` | 列表字段为 `senderPhoneNumber1` |
| 客人邮箱 | `mail` | 客人邮箱 | `mails` | 列表字段为 `mailAddress` |
| 订单明细ID | `order_detail_id` | 子订单ID | `orderDetailId` | 旧列表字段 |
| lotNumber | `lot_number` | lotnumber | `lotnumber` | 旧系统有筛选；新系统当前 Qoo10 筛选不显示该 key |
| 片假名 | `kana` | 片假名查询 | `senderKana` |  |
| 运送方式 | `ship_method` | 运送方式 | `deliveryName` |  |
| 物流公司 | `carrier` | 无独立筛选 | `shipcompany` |  |
| 订单备注 | `comment` | 订单备注查询 | `comment` |  |
| 国际运单状态 | `intl_ship_empty` | 未出国际单号/已有国际单号 | `intl_ship_empty` | 新系统对 Qoo10 使用该参数名 |
| 日期范围 | `date_range` | 按订单时间，查询 | `orderDate` / `orderDate2` | 旧系统也有按导入时间 `cdate` / `cdate2` |
| 超时发货 | `late_ship` | 超时发货 | `chaoshifahuo` |  |
| 【日本】配達中 | `in_delivery` | 配達中 | `haitatsuchuu` | 物流状态筛选 |
| 【日本】配達完了 | `delivered` | 配達完了 | `haitatsukanryo` | 物流状态筛选 |

### 列表展示字段

| 新系统显示名 | 旧系统标签 | 旧系统字段 | 备注 |
|---|---|---|---|
| 导入时间 | 导入时间 | `cdate` |  |
| 客人姓名/片假名 | `senderName` / `senderKana` | `senderName` / `senderKana` |  |
| 地址 | 地址 | `senderAddress` |  |
| 邮编 | 邮编 | `senderZipCode` |  |
| 电话 | 电话 | `senderPhoneNumber1` |  |
| 邮箱 | 邮箱 | `mailAddress` |  |
| 支付方式 | 支付方式 | `settlementName` |  |
| 运送方式 | 运送方式 | `deliveryName` |  |
| 订单状态 | 订单状态 | `orderStatus` |  |
| 图片 | 采购图片 | `zhutu` |  |
| 订单ID / 店铺 | 订单ID / 店铺番号 | `orderId` + `user_name` / `dpid` / `dpquancheng` |  |
| 订单时间 / 明细ID | 订单时间 / 子订单ID | `orderDate` / `orderDetailId` |  |
| 货源地 / 采购状态 | 采购状态 | `beizhu` |  |
| ItemId / lotNumber | ItemID / lotnumber | `itemManagementId` / `lotnumber` |  |
| 商品属性 | 商品属性 | `itemOption` |  |
| 商品标题 / 项目选择 | 商品加价属性 | `itemOptionCommission1` 到 `itemOptionCommission5` | 新系统放在副信息 |
| 数量 | 数量 | `unit` |  |
| 单价 | 商品单价 | `totalPrice` | Qoo10 旧系统商品单价列取 `totalPrice` |
| 邮费/手续费 | 邮费 | `postagePrice` |  |
| 总价/请求金额 | 无单独总价列 | `totalPrice` / `postagePrice` | 新系统统一显示总价 |
| 采购时间 | 采购时间 | `caigoutime` |  |
| 采购链接 | 采购链接地址 | `caigoulink` |  |
| 订单备注 | 订单备注 | `comment` |  |
| 采购金额 | 采购金额 | `amount` |  |
| 1688订单号 | 1688订单号 | `tabaono` |  |
| 物流公司 | 物流公司 | `shipcompany` | 同格会显示 `logisticstatus` |
| 国内运单号 / 签收地 | 国内运单号 | `shipno` + `receipt_city` |  |
| 国际运单号 | 国际运单号 | `shipnumber` |  |
| 国际运单状态 | 国际运单状态 | `jpshipdetails` |  |
| 国际运费 | 国际运费 | `comamount` |  |
| 件数 | 件数 | `shipquantity` |  |
| 产品重量 | 产品重量(kgs) | `weight` |  |
| 利润(RMB) | 利润(RMB) | `cnamount` |  |

## 跨平台合并字段说明

| 新系统字段/列 | 旧系统情况 | 处理方式 |
|---|---|---|
| 货源地 | 旧系统没有统一字段 | 新系统新增，用于区分国内采购、日本仓、待定 |
| 订单ID / 店铺 | 旧系统通常拆成“订单ID”“店铺番号” | 新系统合并展示 |
| 订单时间 / 明细ID | 旧系统常拆成“订单时间”“子订单ID/LineId” | 新系统合并展示 |
| ItemId / lotNumber | 乐天/Yahoo 主要是 `ItemId`；Wowma/Qoo10 是 `itemManagementId + lotnumber`；Mercari/雅拍是 `itemCode + lotnumber` | 新系统统一成一列 |
| 日本仓ID / 管理ID | 乐天有 `ItemManagerId`，其他平台更多是 `itemManagementId` 或新系统内部日本仓 ID | 新系统统一展示 |
| 商品标题 / 项目选择 | 旧系统平台差异大：乐天 `selectedChoice`，Mercari/雅拍 `product_title`，Wowma/Qoo10 `itemOptionCommission1-5` | 新系统合并主副信息 |
| 邮费/手续费 | 乐天/Yahoo 有 `ShipCharge` + `PayCharge`；其他平台多只有 `postagePrice` | 新系统统一列名 |
| 总价/请求金额 | 乐天常用 `requestPrice`；Yahoo/Wowma/Qoo10 有总价字段；Mercari/雅拍多为商品价+邮费计算 | 新系统统一列名 |
| 国际备注 | 旧系统没有稳定独立列 | 新系统合并 `intl_comment` / `tranship_comment` |

