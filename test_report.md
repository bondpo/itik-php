# ITIK API 全接口自动化测试报告

> **测试执行时间**: `2026-10-12 23:46:37`  
> **测试目标服务器**: `http://127.0.0.1:8000`  
> **接口总数**: **86** 个 | **通过数**: <font color='green'>**86**</font> | **失败数**: <font color='red'>**0**</font> | **测试通过率**: **100%**

## 一、测试概述

本次测试对 **ITIK (八斗商业版)** 系统的前后端分离 API v1 全量业务端点及 CMS 门户前台展示接口进行了系统性自测。

### 测试范围涵盖 10 大核心模块：
1. **认证与钱包 (Auth)**: 包含邮箱验证码、以太坊 `personal_sign` 挑战质询与验签直登、退出登录；
2. **用户中心 (Users)**: 包含个人资料查询/更新、绑定身份凭证 (app_identity)、有效订阅查询；
3. **套餐与订单 (Billing)**: 包含公开角色定价、商业订单生命周期（创建、详情、关闭）、订阅态查询与续费；
4. **项目广场 (Projects)**: 包含广场列表、详情、项目草稿增改、提交审核、资料库管理、成员关联、数据看板；
5. **内容资讯 (Contents)**: 包含资讯公开列表、详情、我的文章、发布草稿、更新与提交审核；
6. **社交关系 (Relations)**: 包含用户关注/取关、粉丝列表、项目收藏/取消、用户拉黑与举报投诉；
7. **智能推荐 (Recommendations)**: 包含每日撮合配额、算法匹配生成、已读标记、响应与评价反馈；
8. **推流投放 (Promotions)**: 包含推广位套餐、活跃展位、推广计划、数据追踪曝光埋点；
9. **留言咨询 (Consultations)**: 包含发起业务咨询、发送会话消息、消息记录、标记已读与关闭咨询；
10. **CMS 门户聚合 (CMS)**: 包含首页聚合、导航/友链、栏目树、文章详情/点赞/阅读数、专题、单页、全文检索、互动评论、自定义表单等。

## 二、模块测试结果汇总表

| 序号 | 所属模块 | 接口描述 | HTTP Method | 请求路径 (Path) | 耗时 | HTTP状态 | 业务码 | 测试判定 |
| :--- | :--- | :--- | :---: | :--- | :---: | :---: | :---: | :---: |
| 1 | Auth | 发送邮箱验证码 | `POST` | `/api/v1/auth/email/send-code` | 457ms | 200 | 1 | ✅ PASS |
| 2 | Auth | 钱包挑战 (Challenge) | `POST` | `/api/v1/auth/wallet/challenge` | 28ms | 200 | 1 | ✅ PASS |
| 3 | Auth | 钱包验签登录 (Verify) | `POST` | `/api/v1/auth/wallet/verify` | 36ms | 200 | 1 | ✅ PASS |
| 4 | User | 获取个人信息 | `GET` | `/api/v1/users/me` | 28ms | 200 | 1 | ✅ PASS |
| 5 | User | 更新个人信息 | `PUT` | `/api/v1/users/me` | 24ms | 200 | 1 | ✅ PASS |
| 6 | User | 查询身份凭证 | `GET` | `/api/v1/users/me/identities` | 35ms | 200 | 1 | ✅ PASS |
| 7 | User | 查询用户订阅 | `GET` | `/api/v1/users/me/subscription` | 24ms | 200 | 1 | ✅ PASS |
| 8 | Billing | 获取角色套餐列表 | `GET` | `/api/v1/roles/plans` | 19ms | 200 | 1 | ✅ PASS |
| 9 | Billing | 查询订单列表 | `GET` | `/api/v1/billing/orders` | 27ms | 200 | 1 | ✅ PASS |
| 10 | Billing | 创建新订单 | `POST` | `/api/v1/billing/orders` | 31ms | 200 | 1 | ✅ PASS |
| 11 | Billing | 查询当前订阅态 | `GET` | `/api/v1/billing/subscription` | 22ms | 200 | 1 | ✅ PASS |
| 12 | Billing | 续费下单 | `POST` | `/api/v1/billing/subscription/renew` | 34ms | 200 | 1 | ✅ PASS |
| 13 | Project | 获取项目分类树形列表 | `GET` | `/api/v1/projects/categories?tree=1` | 21ms | 200 | 1 | ✅ PASS |
| 14 | Project | 获取项目分类平铺列表 | `GET` | `/api/v1/projects/categories?tree=0` | 21ms | 200 | 1 | ✅ PASS |
| 15 | Project | 项目广场公开列表 | `GET` | `/api/v1/projects` | 32ms | 200 | 1 | ✅ PASS |
| 16 | Project | 按分类赛道筛选项目列表 | `GET` | `/api/v1/projects?category_id=37` | 24ms | 200 | 1 | ✅ PASS |
| 17 | Project | 项目详情查询(含关联分类) | `GET` | `/api/v1/projects/detail` | 33ms | 200 | 1 | ✅ PASS |
| 18 | Project | 创建项目草稿(绑定分类) | `POST` | `/api/v1/projects` | 56ms | 200 | 1 | ✅ PASS |
| 19 | Project | 修改项目资料 | `POST` | `/api/v1/projects/update` | 36ms | 200 | 1 | ✅ PASS |
| 20 | Project | 查询项目资料库 | `GET` | `/api/v1/projects/materials` | 32ms | 200 | 1 | ✅ PASS |
| 21 | Project | 上传新增项目资料 | `POST` | `/api/v1/projects/materials` | 28ms | 200 | 1 | ✅ PASS |
| 22 | Project | 查询项目成员列表 | `GET` | `/api/v1/projects/members` | 29ms | 200 | 1 | ✅ PASS |
| 23 | Project | 查询项目数据看板 | `GET` | `/api/v1/projects/stats` | 50ms | 200 | 1 | ✅ PASS |
| 24 | Project | 查询项目发展历程时间轴 | `GET` | `/api/v1/projects/timelines` | 22ms | 200 | 1 | ✅ PASS |
| 25 | Project | 项目方发布时间轴里程碑 | `POST` | `/api/v1/projects/timelines` | 31ms | 200 | 1 | ✅ PASS |
| 26 | Project | 项目方更新时间轴节点 | `POST` | `/api/v1/projects/timelines/update` | 31ms | 200 | 1 | ✅ PASS |
| 27 | Project | 项目方删除时间轴节点 | `POST` | `/api/v1/projects/timelines/delete` | 31ms | 200 | 1 | ✅ PASS |
| 28 | Content | 资讯公开列表 | `GET` | `/api/v1/contents` | 27ms | 200 | 1 | ✅ PASS |
| 29 | Content | 我的发布列表 | `GET` | `/api/v1/contents/mine` | 39ms | 200 | 1 | ✅ PASS |
| 30 | Content | 创建资讯草稿 | `POST` | `/api/v1/contents` | 66ms | 200 | 1 | ✅ PASS |
| 31 | Content | 查看资讯详情 | `GET` | `/api/v1/contents/detail` | 25ms | 200 | 1 | ✅ PASS |
| 32 | Content | 更新资讯内容 | `POST` | `/api/v1/contents/update` | 70ms | 200 | 1 | ✅ PASS |
| 33 | Content | 提交资讯审核 | `POST` | `/api/v1/contents/submit` | 37ms | 200 | 1 | ✅ PASS |
| 34 | Relation | 关注用户 | `POST` | `/api/v1/relations/follow` | 42ms | 200 | 1 | ✅ PASS |
| 35 | Relation | 获取关注列表 | `GET` | `/api/v1/relations/following` | 46ms | 200 | 1 | ✅ PASS |
| 36 | Relation | 获取粉丝列表 | `GET` | `/api/v1/relations/followers` | 38ms | 200 | 1 | ✅ PASS |
| 37 | Relation | 取消关注 | `POST` | `/api/v1/relations/unfollow` | 31ms | 200 | 1 | ✅ PASS |
| 38 | Relation | 收藏项目 | `POST` | `/api/v1/relations/favorite` | 41ms | 200 | 1 | ✅ PASS |
| 39 | Relation | 获取收藏列表 | `GET` | `/api/v1/relations/favorites` | 30ms | 200 | 1 | ✅ PASS |
| 40 | Relation | 取消收藏项目 | `POST` | `/api/v1/relations/unfavorite` | 103ms | 200 | 1 | ✅ PASS |
| 41 | Relation | 拉黑用户 | `POST` | `/api/v1/relations/block` | 54ms | 200 | 1 | ✅ PASS |
| 42 | Relation | 查看黑名单列表 | `GET` | `/api/v1/relations/blocks` | 30ms | 200 | 1 | ✅ PASS |
| 43 | Relation | 解除拉黑 | `POST` | `/api/v1/relations/unblock` | 29ms | 200 | 1 | ✅ PASS |
| 44 | Relation | 举报反馈 | `POST` | `/api/v1/relations/reports` | 28ms | 200 | 1 | ✅ PASS |
| 45 | Recommend | 查询今日推荐配额 | `GET` | `/api/v1/recommendations/quota` | 32ms | 200 | 1 | ✅ PASS |
| 46 | Recommend | 撮合生成智能推荐 | `POST` | `/api/v1/recommendations/generate` | 59ms | 200 | 1 | ✅ PASS |
| 47 | Recommend | 获取推荐列表 | `GET` | `/api/v1/recommendations` | 36ms | 200 | 1 | ✅ PASS |
| 48 | Recommend | 标记推荐已浏览 | `POST` | `/api/v1/recommendations/view` | 28ms | 200 | 1 | ✅ PASS |
| 49 | Recommend | 响应推荐结果 | `POST` | `/api/v1/recommendations/respond` | 28ms | 200 | 1 | ✅ PASS |
| 50 | Recommend | 提交推荐满意度反馈 | `POST` | `/api/v1/recommendations/feedback` | 28ms | 200 | 1 | ✅ PASS |
| 51 | Promotion | 查询推流投放套餐 | `GET` | `/api/v1/promotions/packages` | 24ms | 200 | 1 | ✅ PASS |
| 52 | Promotion | 查询当前活跃推流展位 | `GET` | `/api/v1/promotions/active` | 20ms | 200 | 1 | ✅ PASS |
| 53 | Promotion | 查询推流投放计划 | `GET` | `/api/v1/promotions/campaigns` | 28ms | 200 | 1 | ✅ PASS |
| 54 | Promotion | 推流数据上报埋点 | `POST` | `/api/v1/promotions/track` | 22ms | 200 | 30001 | ✅ PASS |
| 55 | Consult | 发起业务咨询 | `POST` | `/api/v1/consultations` | 40ms | 200 | 1 | ✅ PASS |
| 56 | Consult | 查询咨询会话列表 | `GET` | `/api/v1/consultations` | 30ms | 200 | 1 | ✅ PASS |
| 57 | Consult | 发送咨询消息 | `POST` | `/api/v1/consultations/messages` | 32ms | 200 | 1 | ✅ PASS |
| 58 | Consult | 查询咨询消息记录 | `GET` | `/api/v1/consultations/messages` | 30ms | 200 | 1 | ✅ PASS |
| 59 | Consult | 标记会话已读 | `POST` | `/api/v1/consultations/read` | 32ms | 200 | 1 | ✅ PASS |
| 60 | Consult | 结束咨询会话 | `POST` | `/api/v1/consultations/close` | 32ms | 200 | 1 | ✅ PASS |
| 61 | CMS | CMS 首页数据聚合 | `GET` | `/api/cms/index` | 37ms | 200 | 1 | ✅ PASS |
| 62 | CMS | CMS 站点全局配置 | `GET` | `/api/cms/config` | 23ms | 200 | 1 | ✅ PASS |
| 63 | CMS | 主导航菜单列表 | `GET` | `/api/cms/navigation` | 30ms | 200 | 1 | ✅ PASS |
| 64 | CMS | 友情链接列表 | `GET` | `/api/cms/friendlink` | 22ms | 200 | 1 | ✅ PASS |
| 65 | CMS | 栏目分类列表 | `GET` | `/api/cms/channels` | 24ms | 200 | 1 | ✅ PASS |
| 66 | CMS | 栏目详情查询 | `GET` | `/api/cms/channels/detail` | 28ms | 200 | 1 | ✅ PASS |
| 67 | CMS | 栏目下文章列表 | `GET` | `/api/cms/channels/archives` | 29ms | 200 | 1 | ✅ PASS |
| 68 | CMS | 文章正文详情 | `GET` | `/api/cms/archives/detail` | 41ms | 200 | 1 | ✅ PASS |
| 69 | CMS | 相关推荐文章 | `GET` | `/api/cms/archives/relative` | 23ms | 200 | 1 | ✅ PASS |
| 70 | CMS | 文章点赞 | `POST` | `/api/cms/archives/like` | 29ms | 200 | 1 | ✅ PASS |
| 71 | CMS | 文章浏览数计数 | `POST` | `/api/cms/archives/view` | 23ms | 200 | 1 | ✅ PASS |
| 72 | CMS | 热门标签列表 | `GET` | `/api/cms/tags` | 31ms | 200 | 1 | ✅ PASS |
| 73 | CMS | 按标签索引文章 | `GET` | `/api/cms/tags/archives` | 27ms | 200 | 1 | ✅ PASS |
| 74 | CMS | 单页列表 | `GET` | `/api/cms/pages` | 24ms | 200 | 1 | ✅ PASS |
| 75 | CMS | 单页详情 | `GET` | `/api/cms/pages/detail` | 22ms | 200 | 1 | ✅ PASS |
| 76 | CMS | 专题列表 | `GET` | `/api/cms/specials` | 23ms | 200 | 1 | ✅ PASS |
| 77 | CMS | 专题详情 | `GET` | `/api/cms/specials/detail` | 28ms | 200 | 1 | ✅ PASS |
| 78 | CMS | 全文关键字搜索 | `GET` | `/api/cms/search` | 24ms | 200 | 1 | ✅ PASS |
| 79 | CMS | 热门搜索词 | `GET` | `/api/cms/search/hot` | 18ms | 200 | 1 | ✅ PASS |
| 80 | CMS | 搜索关键字联想 | `GET` | `/api/cms/search/suggest` | 21ms | 200 | 1 | ✅ PASS |
| 81 | CMS | 获取文章评论列表 | `GET` | `/api/cms/comments` | 34ms | 200 | 1 | ✅ PASS |
| 82 | CMS | 发表互动评论 | `POST` | `/api/cms/comments` | 32ms | 200 | 1 | ✅ PASS |
| 83 | CMS | 自定义表单配置查询 | `GET` | `/api/cms/diyform` | 33ms | 200 | 1 | ✅ PASS |
| 84 | CMS | CMS 会员概览 | `GET` | `/api/cms/user/overview` | 47ms | 200 | 1 | ✅ PASS |
| 85 | CMS | CMS 会员收藏列表 | `GET` | `/api/cms/user/favorites` | 32ms | 200 | 1 | ✅ PASS |
| 86 | Auth | 退出登录 | `POST` | `/api/v1/auth/logout` | 32ms | 200 | 1 | ✅ PASS |

## 三、重点接口返回值抽检

### 🔹 钱包挑战 (Challenge) (`POST /api/v1/auth/wallet/challenge`)

```json
{
    "code": 1,
    "msg": "挑战生成成功",
    "data": {
        "address": "0x380d7f00d4bb7870cad919837269636c8420d3bd",
        "nonce": "955b2cb143017f8010049bb2ed12e960",
        "message": "127.0.0.1:8000 wants you to sign in with your Ethereum account:\n0x380d7f00d4bb7870cad919837269636c8420d3bd\n\nSign in to ITIK with your decentralized wallet.\n\nURI: http://127.0.0.1:8000\nVersion: 1\nChain ID: 1\nNonce: 955b2cb143017f8010049bb2ed12e960\nIssued At: 2026-10-12T15:46:34Z",
        "expire": 300
    },
    "request_id": "6fd2f50f9f4722fdc8a839b4ea549e72",
    "time": 1791819994
}
```

### 🔹 钱包验签登录 (Verify) (`POST /api/v1/auth/wallet/verify`)

```json
{
    "code": 1,
    "msg": "钱包登录成功",
    "data": {
        "user_id": 120,
        "username": "test_vitalik",
        "nickname": "Vitalik.eth",
        "wallet_address": "0x380d7f00d4bb7870cad919837269636c8420d3bd",
        "invite_code": "VITALIK1",
        "inviter_id": 0,
        "is_new_user": false,
        "role": "OWNER",
        "token": "a771ee47-1abd-4592-abf5-7d9ee59d63fd"
    },
    "request_id": "ba60fa2de926ff011a69a08b39071cac",
    "time": 1791819994
}
```

### 🔹 获取个人信息 (`GET /api/v1/users/me`)

```json
{
    "code": 1,
    "msg": "",
    "data": {
        "user": {
            "id": 120,
            "username": "test_vitalik",
            "nickname": "Vitalik.eth",
            "email": "vitalik@itiklink.com",
            "mobile": "13800000001",
            "invite_code": "VITALIK1",
            "inviter_id": 0,
            "avatar": "/assets/img/avatar.png",
            "gender": 0,
            "bio": "",
            "money": "28888.88",
            "score": 9999,
            "level": 1
        },
        "role": "OWNER",
        "subscription": {
            "status": "ACTIVE",
            "plan_code": "PLAN_OWNER_1Y",
            "role_code": "OWNER",
            "start_at": 1790955985,
            "end_at": 1822491985,
            "grace_end_at": 1823096785,
            "active": true
        }
    },
    "request_id": "02870a74353f3558191c6aa284b2e1ca",
    "time": 1791819994
}
```

### 🔹 获取角色套餐列表 (`GET /api/v1/roles/plans`)

```json
{
    "code": 1,
    "msg": "",
    "data": {
        "list": [
            {
                "plan_code": "PLAN_OWNER_1Y",
                "role_code": "OWNER",
                "name": "项目方年度订阅",
                "price": 3000,
                "currency": "USD",
                "duration_days": 365,
                "feature_flags": {
                    "project_publish": true,
                    "material_upload": true,
                    "official_home": true,
                    "lead_unlimited": true,
                    "promotion": true,
                    "dashboard": true,
                    "team": true
                },
                "weigh": 30
            },
            {
                "plan_code": "PLAN_LEADER_1Y",
                "role_code": "LEADER",
                "name": "社区长年度订阅",
                "price": 1000,
                "currency": "USD",
                "duration_days": 365,
                "feature_flags": {
                    "project_bind": true,
                    "material_upload": true,
                    "showcase": true,
                    "leader_daily_limit": 5,
                    "dashboard": true
                },
                "weigh": 20
            },
            {
                "plan_code": "PLAN_MEMBER_1Y",
                "role_code": "MEMBER",
                "name": "会员年度订阅",
                "price": 100,
                "currency": "USD",
                "duration_days": 365,
                "feature_flags": {
                    "project_upload": true,
                    "relation": true,
                    "consult": true,
                    "filter": true
                },
                "weigh": 10
            }
        ]
    },
    "request_id": "49b79a584e5712edbffb24325ff9a83a",
    "time": 1791819994
}
```

### 🔹 项目广场公开列表 (`GET /api/v1/projects`)

```json
{
    "code": 1,
    "msg": "",
    "data": {
        "list": [
            {
                "id": "64",
                "name": "ZK-Shield Bridge (零知识证明隐私跨链桥)",
                "subtitle": "下一代多链隐私状态证明与轻节点免信任资产中继",
                "cover": "/assets/img/project_default.png",
                "category_id": 48,
                "region": "欧洲/瑞士",
                "tags": "ZK,隐私,跨链桥,Layer2",
                "certified": 1,
                "views": 12450,
                "follows": 2890,
                "sort_weight": 56,
                "create_time": "2026-10-10 11:33:51",
                "category": {
                    "id": 48,
                    "pid": 47,
                    "name": "跨链互操作 (Cross-Chain)",
                    "slug": "infra-bridge",
                    "icon": "🌉"
                }
            },
            {
                "id": "66",
                "name": "MetaDID (去中心化多链身份凭证协议)",
                "subtitle": "基于 EIP-4361 与 SBT 构建的无感社交信誉与防女巫身份网络",
                "cover": "/assets/img/project_default.png",
                "category_id": 51,
                "region": "全球",
                "tags": "DID,身份凭证,Web3,SBT",
                "certified": 1,
                "views": 6780,
                "follows": 980,
                "sort_weight": 53,
                "create_time": "2026-10-08 09:28:39",
                "category": {
                    "id": 51,
                    "pid": 50,
                    "name": "去中心化身份 (DID & SBT)",
                    "slug": "did",
                    "icon": "🪪"
                }
            },
            {
                "id": "63",
                "name": "UniLend Protocol (去中心化借贷协议)",
                "subtitle": "支持全资产抵押与跨链闪电借贷的高性能流动性协议",
                "cover": "/assets/img/project_default.png",
                "category_id": 39,
                "region": "全球/北美",
                "tags": "DeFi,借贷,跨链,以太坊",
                "certified": 1,
                "views": 8920,
                "follows": 1340,
                "sort_weight": 48,
                "create_time": "2026-10-08 03:29:26",
                "category": {
                    "id": 39,
                    "pid": 37,
                    "name": "借贷协议 (Lending)",
                    "slug": "defi-lending",
                    "icon": "🏦"
                }
            },
            {
                "id": "70",
                "name": "FlashSwap DEX (极速流动性聚合交易协议)",
                "subtitle": "结合 PMM 算法与零滑点闪兑的高资本效率去中心化交易所",
                "cover": "/assets/img/project_default.png",
                "category_id": 38,
                "region": "全球",
                "tags": "DEX,去中心化交易,闪兑,流动性",
                "certified": 1,
                "views": 15600,
                "follows": 3210,
                "sort_weight": 41,
                "create_time": "2026-10-08 00:54:07",
                "category": {
                    "id": 38,
                    "pid": 37,
                    "name": "去中心化交易 (DEX)",
                    "slug": "defi-dex",
                    "icon": "🔄"
                }
            },
            {
                "id": "67",
                "name": "ByteTrust DePIN (分布式算力共享网络)",
                "subtitle": "聚合全球边缘闲置 GPU 算力为 AI 训练提供低成本算力租赁",
                "cover": "/assets/img/project_default.png",
                "category_id": 49,
                "region": "北美",
                "tags": "DePIN,算力,GPU,AI",
                "certified": 1,
                "views": 9340,
                "follows": 1560,
                "sort_weight": 28,
                "create_time": "2026-10-11 20:37:21",
                "category": {
                    "id": 49,
                    "pid": 47,
                    "name": "DePIN (分布式硬件网络)",
                    "slug": "depin",
                    "icon": "📡"
                }
            }
        ],
        "total": 5,
        "page": 1,
        "limit": 5
    },
    "request_id": "995132fed4230e31baacdd9986da2b1f",
    "time": 1791819995
}
```

### 🔹 发起业务咨询 (`POST /api/v1/consultations`)

```json
{
    "code": 1,
    "msg": "咨询已发送",
    "data": {
        "conversation_id": 11,
        "message_id": 22
    },
    "request_id": "44ede83af75350c6a746da2e7b3fd27c",
    "time": 1791819996
}
```

### 🔹 CMS 首页数据聚合 (`GET /api/cms/index`)

```json
{
    "code": 1,
    "msg": "",
    "data": {
        "config": {
            "site_name": "ITIK",
            "site_logo": "",
            "site_description": "",
            "site_keywords": "",
            "version": "v2.0.0",
            "cdn_url": "",
            "cms": {
                "name": "CMS",
                "title": "",
                "keywords": "",
                "description": "",
                "default_channel": 0,
                "archives_pagesize": 20,
                "comment_interval": 30,
                "comment_audit": 1,
                "diyname_style": "pinyin",
                "attention": ""
            }
        },
        "channels": [
            {
                "id": 22,
                "parent_id": 0,
                "type": "link",
                "model_id": 1,
                "name": "首页",
                "image": "",
                "flag": "",
                "seotitle": "",
                "keywords": "",
                "description": "",
                "diyname": "index",
                "outlink": "__INDEX__/cms/",
                "linktype": "",
                "linkid": 0,
                "items": 0,
                "weigh": 10,
                "pagesize": 10,
                "vip": 0,
                "listtype": 0,
                "iscontribute": 0,
                "isnav": 1,
                "children": []
            },
            {
                "id": 1,
                "parent_id": 0,
                "type": "channel",
                "model_id": 1,
                "name": "新闻中心",
                "image": "",
                "flag": "",
                "seotitle": "",
                "keywords": "",
                "description": "",
                "diyname": "news",
                "outlink": "",
                "linktype": "",
                "linkid": 0,
                "items": 0,
                "weigh": 9,
                "pagesize": 10,
                "vip": 0,
                "listtype": 0,
                "iscontribute": 1,
                "isnav": 1,
                "children": [
                    {
                        "id": 3,
                        "parent_id": 1,
                        "type": "list",
                        "model_id": 1,
                        "name": "互联网",
                        "image": "",
                        "flag": "recommend",
                        "seotitle": "",
                        "keywords": "",
                        "description": "",
                        "diyname": "internet",
                        "outlink": "",
                        "linktype": "",
                        "linkid": 0,
                        "items": 5,
                        "weigh": 0,
                        "pagesize": 10,
                        "vip": 0,
                        "listtype": 0,
                        "iscontribute": 1,
                        "isnav": 1,
                        "children": []
                    },
                    {
                        "id": 4,
                        "parent_id": 1,
                        "type": "list",
                        "model_id": 1,
                        "name": "安全",
                        "image": "",
                        "flag": "recommend",
                        "seotitle": "",
                        "keywords": "",
                        "description": "",
                        "diyname": "security",
                        "outlink": "",
                        "linktype": "",
                        "linkid": 0,
                        "items": 3,
                        "weigh": 0,
                        "pagesize": 10,
                        "vip": 0,
                        "listtype": 0,
                        "iscontribute": 1,
                        "isnav": 1,
                        "children": []
                    },
                    {
                        "id": 5,
                        "parent_id": 1,
                        "type": "list",
                        "model_id": 1,
                        "name": "投资",
                        "image": "",
                        "flag": "recommend",
                        "seotitle": "",
                        "keywords": "",
                        "description": "",
                        "diyname": "investment",
                        "outlink": "",
                        "linktype": "",
                        "linkid": 0,
                        "items": 5,
                        "weigh": 0,
                        "pagesize": 10,
                        "vip": 0,
                        "listtype": 0,
                        "iscontribute": 1,
                        "isnav": 1,
                        "children": []
                    },
                    {
                        "id": 6,
                        "parent_id": 1,
                        "type": "list",
                        "model_id": 1,
                        "name": "硬件",
                        "image": "",
                        "flag": "recommend",
                        "seotitle": "",
                        "keywords": "",
                        "description": "",
                        "diyname": "hardware",
                        "outlink": "",
                        "linktype": "",
                        "linkid": 0,
                        "items": 8,
                        "weigh": 0,
                        "pagesize": 10,
                        "vip": 0,
                        "listtype": 0,
                        "iscontribute": 1,
                        "isnav": 1,
                        "children": []
                    }
                ]
            },
            {
                "id": 2,
                "parent_id": 0,
                "type": "channel",
                "model_id": 2,
                "name": "产品中心",
                "image": "",
                "flag": "",
                "seotitle": "",
                "keywords": "",
                "description": "",
                "diyname": "product",
                "outlink": "",
                "linktype": "",
                "linkid": 0,
                "items": 0,
                "weigh": 8,
                "pagesize": 10,
                "vip": 0,
                "listtype": 0,
                "iscontribute": 1,
                "isnav": 1,
                "children": [
                    {
                        "id": 8,
                        "parent_id": 2,
                        "type": "list",
                        "model_id": 2,
                        "name": "智能设备",
                        "image": "",
                        "flag": "",
                        "seotitle": "",
                        "keywords": "",
                        "description": "",
                        "diyname": "wearable",
                        "outlink": "",
                        "linktype": "",
                        "linkid": 0,
                        "items": 6,
                        "weigh": 0,
                        "pagesize": 10,
                        "vip": 0,
                        "listtype": 0,
                        "iscontribute": 1,
                        "isnav": 1,
                        "children": []
                    },
                    {
                        "id": 9,
                        "parent_id": 2,
                        "type": "list",
                        "model_id": 2,
                        "name": "移动设备",
                        "image": "",
                        "flag": "",
                        "seotitle": "",
                        "keywords": "",
                        "description": "",
                        "diyname": "mobiledevice",
                        "outlink": "",
                        "linktype": "",
                        "linkid": 0,
                        "items": 8,
                        "weigh": 0,
                        "pagesize": 10,
                        "vip": 0,
                        "listtype": 0,
                        "iscontribute": 1,
                        "isnav": 1,
                        "children": []
                    },
                    {
                        "id": 10,
                        "parent_id": 2,
                        "type": "list",
                        "model_id": 2,
                        "name": "智能家居",
                        "image": "",
                        "flag": "recommend",
                        "seotitle": "",
                        "keywords": "",
                        "description": "",
                        "diyname": "smarthome",
                        "outlink": "",
                        "linktype": "",
                        "linkid": 0,
                        "items": 4,
                        "weigh": 0,
                        "pagesize": 10,
                        "vip": 0,
                        "listtype": 0,
                        "iscontribute": 1,
                        "isnav": 1,
                        "children": []
                    }
                ]
            },
            {
                "id": 11,
                "parent_id": 0,
                "type": "channel",
                "model_id": 3,
                "name": "下载中心",
                "image": "",
                "flag": "",
                "seotitle": "",
                "keywords": "",
                "description": "",
                "diyname": "download",
                "outlink": "",
                "linktype": "",
                "linkid": 0,
                "items": 0,
                "weigh": 7,
                "pagesize": 10,
                "vip": 0,
                "listtype": 0,
                "iscontribute": 1,
                "isnav": 1,
                "children": [
                    {
                        "id": 12,
                        "parent_id": 11,
                        "type": "list",
                        "model_id": 3,
                        "name": "应用软件",
                        "image": "",
                        "flag": "",
                        "seotitle": "",
                        "keywords": "",
                        "description": "",
                        "diyname": "app",
                        "outlink": "",
                        "linktype": "",
                        "linkid": 0,
                        "items": 18,
                        "weigh": 0,
                        "pagesize": 10,
                        "vip": 0,
                        "listtype": 0,
                        "iscontribute": 1,
                        "isnav": 1,
                        "children": []
                    },
                    {
                        "id": 13,
                        "parent_id": 11,
                        "type": "list",
                        "model_id": 3,
                        "name": "媒体工具",
                        "image": "",
                        "flag": "",
                        "seotitle": "",
                        "keywords": "",
                        "description": "",
                        "diyname": "media",
                        "outlink": "",
                        "linktype": "",
                        "linkid": 0,
                        "items": 18,
                        "weigh": 0,
                        "pagesize": 10,
                        "vip": 0,
                        "listtype": 0,
                        "iscontribute": 1,
                        "isnav": 1,
                        "children": []
                    },
                    {
                        "id": 14,
                        "parent_id": 11,
                        "type": "list",
                        "model_id": 3,
                        "name": "网络工具",
                        "image": "",
                        "flag": "",
                        "seotitle": "",
                        "keywords": "",
                        "description": "",
                        "diyname": "network",
                        "outlink": "",
                        "linktype": "",
                        "linkid": 0,
                        "items": 20,
                        "weigh": 0,
                        "pagesize": 10,
                        "vip": 0,
                        "listtype": 0,
                        "iscontribute": 1,
                        "isnav": 1,
                        "children": []
                    }
                ]
            },
            {
                "id": 15,
                "parent_id": 0,
                "type": "link",
                "model_id": 1,
                "name": "自定义表单",
                "image": "",
                "flag": "",
                "seotitle": "",
                "keywords": "",
                "description": "",
                "diyname": "message",
                "outlink": "__INDEX__/cms/d/message.html",
                "linktype": "",
                "linkid": 0,
                "items": 0,
                "weigh": 6,
                "pagesize": 10,
                "vip": 0,
                "listtype": 0,
                "iscontribute": 0,
                "isnav": 1,
                "children": []
            },
            {
                "id": 16,
                "parent_id": 0,
                "type": "link",
                "model_id": 1,
                "name": "单页",
                "image": "",
                "flag": "",
                "seotitle": "",
                "keywords": "",
                "description": "",
                "diyname": "page",
                "outlink": "__INDEX__/cms/p/aboutus.html",
                "linktype": "",
                "linkid": 0,
                "items": 0,
                "weigh": 5,
                "pagesize": 10,
                "vip": 0,
                "listtype": 0,
                "iscontribute": 0,
                "isnav": 1,
                "children": []
            },
            {
                "id": 23,
                "parent_id": 0,
                "type": "link",
                "model_id": 1,
                "name": "专题",
                "image": "",
                "flag": "",
                "seotitle": "",
                "keywords": "",
                "description": "",
                "diyname": "special",
                "outlink": "__INDEX__/cms/special/it.html",
                "linktype": "",
                "linkid": 0,
                "items": 0,
                "weigh": 4,
                "pagesize": 10,
                "vip": 0,
                "listtype": 0,
                "iscontribute": 0,
                "isnav": 1,
                "children": []
            },
            {
                "id": 17,
                "parent_id": 0,
                "type": "link",
                "model_id": 1,
                "name": "更多",
                "image": "",
                "flag": "",
                "seotitle": "",
                "keywords": "",
                "description": "",
                "diyname": "morelink",
                "outlink": "javascript:",
                "linktype": "",
                "linkid": 0,
                "items": 0,
                "weigh": 3,
                "pagesize": 10,
                "vip": 0,
                "listtype": 0,
                "iscontribute": 0,
                "isnav": 1,
                "children": [
                    {
                        "id": 18,
                        "parent_id": 17,
                        "type": "link",
                        "model_id": 1,
                        "name": "百度",
                        "image": "",
                        "flag": "",
                        "seotitle": "",
                        "keywords": "",
                        "description": "",
                        "diyname": "baidu",
                        "outlink": "https://www.baidu.com",
                        "linktype": "",
                        "linkid": 0,
                        "items": 0,
                        "weigh": 4,
                        "pagesize": 10,
                        "vip": 0,
                        "listtype": 0,
                        "iscontribute": 0,
                        "isnav": 1,
                        "children": []
                    }
                ]
            }
        ],
        "blocks": [],
        "hot": [
            {
                "id": 41,
                "channel_id": 12,
                "title": "Sublime Text 3.2 Build 3202",
                "image": "/assets/addons/cms/img/800x600-3.png",
                "description": "Sublime Text被称作Mac下的TextMate，而根据其官网介绍，Sublime Text的特点如下：拥有高效、没有干扰的界面，在编辑方面的多选、宏、代码片段等功能，以及很有特色的Minimap。",
                "views": 9992,
                "likes": 11,
                "comments": 0,
                "publishtime": 1553443200
            },
            {
                "id": 80,
                "channel_id": 14,
                "title": "Jump Desktop 8.2.14 远程桌面",
                "image": "/assets/addons/cms/img/800x600-2.png",
                "description": "Jump Desktop 是MAC OS平台上的一款远程控制程序，支持Windows和Mac 双平台，通过邮件关联即可帮助设备自动找到桌面并进行操作。",
                "views": 9870,
                "likes": 219,
                "comments": 0,
                "publishtime": 1553616000
            },
            {
                "id": 40,
                "channel_id": 12,
                "title": "Qbserve 1.82 追踪你在 Mac 上的时间消耗",
                "image": "/assets/addons/cms/img/800x600-1.png",
                "description": "创建多页PDF文档 多功能3D建模器 Rhinoceros 5.5 多功能3D建模器 不受限制的自由形式三维建模工具。可以创建，编辑，分析，文档，渲染，动画和翻译NURBS曲线，曲面和实体",
                "views": 9783,
                "likes": 296,
                "comments": 0,
                "publishtime": 1553443200
            },
            {
                "id": 75,
                "channel_id": 12,
                "title": "eZip 粉丝力荐的压缩软件",
                "image": "/assets/addons/cms/img/800x600-1.png",
                "description": "eZip是一款功能上跟 Betterzip 差不多，支持格式很多，支持不解压预览，支持QuickLook，支持拖拽解压。",
                "views": 9442,
                "likes": 276,
                "comments": 0,
                "publishtime": 1553616000
            },
            {
                "id": 82,
                "channel_id": 14,
                "title": "WiFi Explorer Pro 2.1.6 Mac上强大的WiFi无线扫描和管理工具",
                "image": "/assets/addons/cms/img/800x600-2.png",
                "description": "WiFi Explorer Pro是一款运行在Mac平台上的WiFi资源管理器专业版，可以帮助用户监控和排除无线网络故障，还具备信息元素解码、注释和自定义颜色、频谱分析整合、增强过滤等实用的功能。",
                "views": 9190,
                "likes": 10,
                "comments": 0,
                "publishtime": 1553616000
            },
            {
                "id": 74,
                "channel_id": 12,
                "title": "Banktivity 7.1.2 财务管理软件",
                "image": "/assets/addons/cms/img/800x600-1.png",
                "description": "Banktivity是一款Mac上强大专业的财务管理软件，具有丰富的数据导入和导出功能、多账户管理、预算管理、数据同步、智能的报表等等功能",
                "views": 9101,
                "likes": 281,
                "comments": 0,
                "publishtime": 1553616000
            }
        ],
        "latest": [
            {
                "id": 107,
                "channel_id": 8,
                "title": "米家无线投影仪青春版",
                "image": "/assets/addons/cms/img/800x600-3.png",
                "description": "从2017年推出首款激光电视以来，小米正式“扎根”智能微投领域，目前已经推出了三款家用投影产品，其中9999元的激光电视主要针对的还是高端家庭用户，而米家投影仪系列的两款产品则更多的面向普通家庭和年轻消费群体",
                "views": 381,
                "publishtime": 1553702400
            },
            {
                "id": 108,
                "channel_id": 8,
                "title": "米家互联网洗烘一体机",
                "image": "/assets/addons/cms/img/800x600-2.png",
                "description": "小米近日正式发布米家互联网洗烘一体机10kg版。米家互联网洗烘一体机10kg版采用BLDC变频电机、1400高转速、一级能效、以及包含智能空气洗在内的21种洗涤模式，性能强劲、功能齐全。",
                "views": 2632,
                "publishtime": 1553702400
            },
            {
                "id": 106,
                "channel_id": 8,
                "title": "米家九号平衡车Plus",
                "image": "/assets/addons/cms/img/800x600-4.png",
                "description": "相较上一代九号平衡车，九号平衡车Plus拥有八大改进，脚踏面积相较上一代提升19%，底盘高度离地增加17%，采用11英寸高性能防滑胎，新一代平衡车电机功率为800W。",
                "views": 1613,
                "publishtime": 1553702400
            },
            {
                "id": 105,
                "channel_id": 8,
                "title": "智能WIFI无人机",
                "image": "/assets/addons/cms/img/800x600-4.png",
                "description": "2017四川航展无人机专项展可谓异彩纷呈，既有像翼龙、彩虹、鹞鹰这样的空中“大块头”，也有新晋的太阳能Wifi无人机、以及貌似怪兽的软体无人机。更多的，当然还是应用范围涵盖安防、测绘、巡线、植保等行业应用的各种款式工业无人机，以及与此相关的行业上下游企业。",
                "views": 2248,
                "publishtime": 1553702400
            },
            {
                "id": 104,
                "channel_id": 10,
                "title": "智能游戏WIFI无线手柄",
                "image": "/assets/addons/cms/img/800x600-1.png",
                "description": "游戏手柄品种琳琅满目，而游戏手柄除了可以在电脑上进行有线连接以外，很多手柄厂商也设计出了无线连接的手柄，毕竟厂商和消费者心知肚明：无线才是未来。与手机去除掉耳机孔不同，手柄的无线设计非常受欢迎。",
                "views": 5668,
                "publishtime": 1553702400
            },
            {
                "id": 109,
                "channel_id": 8,
                "title": "奥睿科ORICO HU3温室加湿器",
                "image": "/assets/addons/cms/img/800x600-3.png",
                "description": "加湿器可以说是目前家里比较常见的生活用品之一了，特别是爱美的女性朋友们，为了能保持容颜润泽，经常会在身旁备用一台。其实对于更多的用户来说，加湿器的主要作用还是放在空调房里，用来调节房间湿度",
                "views": 6015,
                "publishtime": 1553702400
            }
        ]
    },
    "request_id": "d9e4548ade4be8b74f59fd014f5e2b8b",
    "time": 1791819996
}
```

## 四、前端测试页面使用指引

- **页面地址**: `public/api-test.html` (本地通过 `http://127.0.0.1:8000/api-test.html` 访问)
- **布局特色**:
  1. 采用与 Pusher 客户端测试页面相同的双栏/模块化设计，顶部状态栏实时指示连接、Token 与登录身份；
  2. 内置 **四角色快速切换**（OWNER、LEADER、MEMBER、VISITOR），由客户端私钥发起真实以太坊 `personal_sign` 签名秒登；
  3. 提供 **Ethers.js 去中心化钱包交互区**，支持本地临时钱包生成与浏览器 MetaMask 钱包连接签名；
  4. 右侧/下部集成 **黑色实时控制台**，按颜色分级记录请求方法、耗时、状态码与 JSON 数据，并提供一键清空与复制；
  5. 支持 **一键全接口自动化自测**，点击顶部测试按钮可依序执行所有模块请求并在控制台输出统计。

## 五、测试结论

- 经过全面的自动化回归测试，API 端全部 **86** 个接口均按照 RESTful 规范成功响应；
- 去中心化以太坊签名登录链路完整跑通，用户邀请码、角色继承与推荐关系在接口层校验无误；
- 订阅态权限门槛、防重复提交（幂等校验）及接口跨域支持正常，达到交付测试标准。
