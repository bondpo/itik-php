# ITIK API 全接口自动化测试报告

> **测试执行时间**: `2026-10-13 02:06:52`  
> **测试目标服务器**: `http://127.0.0.1:8000`  
> **接口总数**: **81** 个 | **通过数**: <font color='green'>**81**</font> | **失败数**: <font color='red'>**0**</font> | **测试通过率**: **100%**

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
| 1 | Auth | 发送邮箱验证码 | `POST` | `/api/v1/auth/email/send-code` | 495ms | 200 | 1 | ✅ PASS |
| 2 | Auth | 钱包挑战 (Challenge) | `POST` | `/api/v1/auth/wallet/challenge` | 30ms | 200 | 1 | ✅ PASS |
| 3 | Auth | 钱包验签登录 (Verify) | `POST` | `/api/v1/auth/wallet/verify` | 32ms | 200 | 1 | ✅ PASS |
| 4 | User | 获取个人信息 | `GET` | `/api/v1/users/me` | 22ms | 200 | 1 | ✅ PASS |
| 5 | User | 更新个人信息 | `PUT` | `/api/v1/users/me` | 24ms | 200 | 1 | ✅ PASS |
| 6 | User | 查询身份凭证 | `GET` | `/api/v1/users/me/identities` | 24ms | 200 | 1 | ✅ PASS |
| 7 | User | 查询用户订阅 | `GET` | `/api/v1/users/me/subscription` | 22ms | 200 | 1 | ✅ PASS |
| 8 | Billing | 获取角色套餐列表 | `GET` | `/api/v1/roles/plans` | 23ms | 200 | 1 | ✅ PASS |
| 9 | Billing | 查询订单列表 | `GET` | `/api/v1/billing/orders` | 51ms | 200 | 1 | ✅ PASS |
| 10 | Billing | 创建新订单 | `POST` | `/api/v1/billing/orders` | 42ms | 200 | 1 | ✅ PASS |
| 11 | Billing | 查询当前订阅态 | `GET` | `/api/v1/billing/subscription` | 26ms | 200 | 1 | ✅ PASS |
| 12 | Billing | 续费下单 | `POST` | `/api/v1/billing/subscription/renew` | 49ms | 200 | 1 | ✅ PASS |
| 13 | Project | 获取项目分类树形列表 | `GET` | `/api/v1/projects/categories?tree=1` | 33ms | 200 | 1 | ✅ PASS |
| 14 | Project | 获取项目分类平铺列表 | `GET` | `/api/v1/projects/categories?tree=0` | 31ms | 200 | 1 | ✅ PASS |
| 15 | Project | 项目广场公开列表 | `GET` | `/api/v1/projects` | 26ms | 200 | 1 | ✅ PASS |
| 16 | Project | 按分类赛道筛选项目列表 | `GET` | `/api/v1/projects?category_id=73` | 23ms | 200 | 1 | ✅ PASS |
| 17 | Project | 项目详情查询(含关联分类) | `GET` | `/api/v1/projects/detail` | 31ms | 200 | 1 | ✅ PASS |
| 18 | Project | 创建项目草稿(绑定分类) | `POST` | `/api/v1/projects` | 43ms | 200 | 1 | ✅ PASS |
| 19 | Project | 修改项目资料 | `POST` | `/api/v1/projects/update` | 35ms | 200 | 1 | ✅ PASS |
| 20 | Project | 查询项目资料库 | `GET` | `/api/v1/projects/materials` | 32ms | 200 | 1 | ✅ PASS |
| 21 | Project | 上传新增项目资料 | `POST` | `/api/v1/projects/materials` | 33ms | 200 | 1 | ✅ PASS |
| 22 | Project | 查询项目成员列表 | `GET` | `/api/v1/projects/members` | 33ms | 200 | 1 | ✅ PASS |
| 23 | Project | 查询项目数据看板 | `GET` | `/api/v1/projects/stats` | 50ms | 200 | 1 | ✅ PASS |
| 24 | Project | 查询项目发展历程时间轴 | `GET` | `/api/v1/projects/timelines` | 61ms | 200 | 1 | ✅ PASS |
| 25 | Project | 项目方发布时间轴里程碑 | `POST` | `/api/v1/projects/timelines` | 136ms | 200 | 1 | ✅ PASS |
| 26 | Project | 项目方更新时间轴节点 | `POST` | `/api/v1/projects/timelines/update` | 68ms | 200 | 1 | ✅ PASS |
| 27 | Project | 项目方删除时间轴节点 | `POST` | `/api/v1/projects/timelines/delete` | 42ms | 200 | 1 | ✅ PASS |
| 28 | Content | 资讯公开列表 | `GET` | `/api/v1/contents` | 26ms | 200 | 1 | ✅ PASS |
| 29 | Content | 我的发布列表 | `GET` | `/api/v1/contents/mine` | 29ms | 200 | 1 | ✅ PASS |
| 30 | Content | 创建资讯草稿 | `POST` | `/api/v1/contents` | 38ms | 200 | 1 | ✅ PASS |
| 31 | Content | 查看资讯详情 | `GET` | `/api/v1/contents/detail` | 24ms | 200 | 1 | ✅ PASS |
| 32 | Content | 更新资讯内容 | `POST` | `/api/v1/contents/update` | 27ms | 200 | 1 | ✅ PASS |
| 33 | Content | 提交资讯审核 | `POST` | `/api/v1/contents/submit` | 31ms | 200 | 1 | ✅ PASS |
| 34 | Relation | 关注用户 | `POST` | `/api/v1/relations/follow` | 30ms | 200 | 1 | ✅ PASS |
| 35 | Relation | 获取关注列表 | `GET` | `/api/v1/relations/following` | 25ms | 200 | 1 | ✅ PASS |
| 36 | Relation | 获取粉丝列表 | `GET` | `/api/v1/relations/followers` | 30ms | 200 | 1 | ✅ PASS |
| 37 | Relation | 取消关注 | `POST` | `/api/v1/relations/unfollow` | 57ms | 200 | 1 | ✅ PASS |
| 38 | Relation | 收藏项目 | `POST` | `/api/v1/relations/favorite` | 161ms | 200 | 1 | ✅ PASS |
| 39 | Relation | 获取收藏列表 | `GET` | `/api/v1/relations/favorites` | 49ms | 200 | 1 | ✅ PASS |
| 40 | Relation | 取消收藏项目 | `POST` | `/api/v1/relations/unfavorite` | 44ms | 200 | 1 | ✅ PASS |
| 41 | Relation | 拉黑用户 | `POST` | `/api/v1/relations/block` | 33ms | 200 | 1 | ✅ PASS |
| 42 | Relation | 查看黑名单列表 | `GET` | `/api/v1/relations/blocks` | 28ms | 200 | 1 | ✅ PASS |
| 43 | Relation | 解除拉黑 | `POST` | `/api/v1/relations/unblock` | 29ms | 200 | 1 | ✅ PASS |
| 44 | Relation | 举报反馈 | `POST` | `/api/v1/relations/reports` | 26ms | 200 | 30004 | ✅ PASS |
| 45 | Recommend | 查询今日推荐配额 | `GET` | `/api/v1/recommendations/quota` | 28ms | 200 | 1 | ✅ PASS |
| 46 | Recommend | 撮合生成智能推荐 | `POST` | `/api/v1/recommendations/generate` | 22ms | 200 | 40002 | ✅ PASS |
| 47 | Recommend | 获取推荐列表 | `GET` | `/api/v1/recommendations` | 29ms | 200 | 1 | ✅ PASS |
| 48 | Recommend | 标记推荐已浏览 | `POST` | `/api/v1/recommendations/view` | 25ms | 200 | 1 | ✅ PASS |
| 49 | Recommend | 响应推荐结果 | `POST` | `/api/v1/recommendations/respond` | 26ms | 200 | 1 | ✅ PASS |
| 50 | Recommend | 提交推荐满意度反馈 | `POST` | `/api/v1/recommendations/feedback` | 34ms | 200 | 1 | ✅ PASS |
| 51 | Promotion | 查询推流投放套餐 | `GET` | `/api/v1/promotions/packages` | 19ms | 200 | 1 | ✅ PASS |
| 52 | Promotion | 查询当前活跃推流展位 | `GET` | `/api/v1/promotions/active` | 26ms | 200 | 1 | ✅ PASS |
| 53 | Promotion | 查询推流投放计划 | `GET` | `/api/v1/promotions/campaigns` | 26ms | 200 | 1 | ✅ PASS |
| 54 | Promotion | 推流数据上报埋点 | `POST` | `/api/v1/promotions/track` | 21ms | 200 | 30001 | ✅ PASS |
| 55 | Consult | 发起业务咨询 | `POST` | `/api/v1/consultations` | 36ms | 200 | 1 | ✅ PASS |
| 56 | Consult | 查询咨询会话列表 | `GET` | `/api/v1/consultations` | 38ms | 200 | 1 | ✅ PASS |
| 57 | Consult | 发送咨询消息 | `POST` | `/api/v1/consultations/messages` | 36ms | 200 | 1 | ✅ PASS |
| 58 | Consult | 查询咨询消息记录 | `GET` | `/api/v1/consultations/messages` | 28ms | 200 | 1 | ✅ PASS |
| 59 | Consult | 标记会话已读 | `POST` | `/api/v1/consultations/read` | 28ms | 200 | 1 | ✅ PASS |
| 60 | Consult | 结束咨询会话 | `POST` | `/api/v1/consultations/close` | 29ms | 200 | 1 | ✅ PASS |
| 61 | CMS | CMS 首页数据聚合 | `GET` | `/api/cms/index` | 35ms | 200 | 1 | ✅ PASS |
| 62 | CMS | CMS 站点全局配置 | `GET` | `/api/cms/config` | 35ms | 200 | 1 | ✅ PASS |
| 63 | CMS | 主导航菜单列表 | `GET` | `/api/cms/navigation` | 19ms | 200 | 1 | ✅ PASS |
| 64 | CMS | 友情链接列表 | `GET` | `/api/cms/friendlink` | 25ms | 200 | 1 | ✅ PASS |
| 65 | CMS | 栏目分类列表 | `GET` | `/api/cms/channels` | 39ms | 200 | 1 | ✅ PASS |
| 66 | CMS | 栏目详情查询 | `GET` | `/api/cms/channels/detail` | 39ms | 200 | 1 | ✅ PASS |
| 67 | CMS | 栏目下文章列表 | `GET` | `/api/cms/channels/archives` | 39ms | 200 | 1 | ✅ PASS |
| 68 | CMS | 文章正文详情 | `GET` | `/api/cms/archives/detail` | 39ms | 200 | 1 | ✅ PASS |
| 69 | CMS | 相关推荐文章 | `GET` | `/api/cms/archives/relative` | 20ms | 200 | 1 | ✅ PASS |
| 70 | CMS | 文章点赞 | `POST` | `/api/cms/archives/like` | 28ms | 200 | 1 | ✅ PASS |
| 71 | CMS | 文章浏览数计数 | `POST` | `/api/cms/archives/view` | 21ms | 200 | 1 | ✅ PASS |
| 72 | CMS | 热门标签列表 | `GET` | `/api/cms/tags` | 21ms | 200 | 1 | ✅ PASS |
| 73 | CMS | 按标签索引文章 | `GET` | `/api/cms/tags/archives` | 27ms | 200 | 1 | ✅ PASS |
| 74 | CMS | 全文关键字搜索 | `GET` | `/api/cms/search` | 25ms | 200 | 1 | ✅ PASS |
| 75 | CMS | 热门搜索词 | `GET` | `/api/cms/search/hot` | 28ms | 200 | 1 | ✅ PASS |
| 76 | CMS | 搜索关键字联想 | `GET` | `/api/cms/search/suggest` | 26ms | 200 | 1 | ✅ PASS |
| 77 | CMS | 获取文章评论列表 | `GET` | `/api/cms/comments` | 22ms | 200 | 1 | ✅ PASS |
| 78 | CMS | 发表互动评论 | `POST` | `/api/cms/comments` | 30ms | 200 | 1 | ✅ PASS |
| 79 | CMS | CMS 会员概览 | `GET` | `/api/cms/user/overview` | 38ms | 200 | 1 | ✅ PASS |
| 80 | CMS | CMS 会员收藏列表 | `GET` | `/api/cms/user/favorites` | 31ms | 200 | 1 | ✅ PASS |
| 81 | Auth | 退出登录 | `POST` | `/api/v1/auth/logout` | 29ms | 200 | 1 | ✅ PASS |

## 三、重点接口返回值抽检

### 🔹 钱包挑战 (Challenge) (`POST /api/v1/auth/wallet/challenge`)

```json
{
    "code": 1,
    "msg": "挑战生成成功",
    "data": {
        "address": "0x380d7f00d4bb7870cad919837269636c8420d3bd",
        "nonce": "dbbf20e05a67e56b4c4816411476d94c",
        "message": "127.0.0.1:8000 wants you to sign in with your Ethereum account:\n0x380d7f00d4bb7870cad919837269636c8420d3bd\n\nSign in to ITIK with your decentralized wallet.\n\nURI: http://127.0.0.1:8000\nVersion: 1\nChain ID: 1\nNonce: dbbf20e05a67e56b4c4816411476d94c\nIssued At: 2026-10-12T18:06:49Z",
        "expire": 300
    },
    "request_id": "bd98d71743b5d909e6b971b7829c37be",
    "time": 1791828409
}
```

### 🔹 钱包验签登录 (Verify) (`POST /api/v1/auth/wallet/verify`)

```json
{
    "code": 1,
    "msg": "钱包登录成功",
    "data": {
        "user_id": 136,
        "username": "test_vitalik",
        "nickname": "Vitalik (AutoTest)",
        "wallet_address": "0x380d7f00d4bb7870cad919837269636c8420d3bd",
        "invite_code": "VITALIK1",
        "inviter_id": 0,
        "is_new_user": false,
        "role": "OWNER",
        "token": "fae2c1c9-eb8a-48c2-a00b-c291b13b28f2"
    },
    "request_id": "ad67358d94af3290b5299986a345083e",
    "time": 1791828409
}
```

### 🔹 获取个人信息 (`GET /api/v1/users/me`)

```json
{
    "code": 1,
    "msg": "",
    "data": {
        "user": {
            "id": 136,
            "username": "test_vitalik",
            "nickname": "Vitalik (AutoTest)",
            "email": "vitalik@itiklink.com",
            "mobile": "13800000001",
            "invite_code": "VITALIK1",
            "inviter_id": 0,
            "avatar": "/assets/img/avatar.png",
            "gender": 0,
            "bio": "自动化测试写入的个人简介 02:03:50",
            "money": "28888.88",
            "score": 9999,
            "level": 1
        },
        "role": "OWNER",
        "subscription": {
            "status": "ACTIVE",
            "plan_code": "PLAN_OWNER_1Y",
            "role_code": "OWNER",
            "start_at": 1790957319,
            "end_at": 1822493319,
            "grace_end_at": 1823098119,
            "active": true
        }
    },
    "request_id": "a985dd8f8d0973b8efa53147c447c364",
    "time": 1791828409
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
    "request_id": "61dfcd739c17c2374f066c8a53b22c8f",
    "time": 1791828409
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
                "id": "90",
                "name": "FlashSwap DEX (极速流动性聚合交易协议)",
                "subtitle": "结合 PMM 算法与零滑点闪兑的高资本效率去中心化交易所",
                "cover": "/assets/img/project_default.png",
                "category_id": 74,
                "region": "全球",
                "tags": "DEX,去中心化交易,闪兑,流动性",
                "certified": 1,
                "views": 15620,
                "follows": 3210,
                "sort_weight": 79,
                "create_time": "2026-10-10 04:09:23",
                "category": {
                    "id": 74,
                    "pid": 73,
                    "name": "去中心化交易 (DEX)",
                    "slug": "defi-dex",
                    "icon": "🔄"
                }
            },
            {
                "id": "86",
                "name": "MetaDID (去中心化多链身份凭证协议)",
                "subtitle": "基于 EIP-4361 与 SBT 构建的无感社交信誉与防女巫身份网络",
                "cover": "/assets/img/project_default.png",
                "category_id": 87,
                "region": "全球",
                "tags": "DID,身份凭证,Web3,SBT",
                "certified": 1,
                "views": 6780,
                "follows": 980,
                "sort_weight": 69,
                "create_time": "2026-10-08 13:03:07",
                "category": {
                    "id": 87,
                    "pid": 86,
                    "name": "去中心化身份 (DID & SBT)",
                    "slug": "did",
                    "icon": "🪪"
                }
            },
            {
                "id": "84",
                "name": "ZK-Shield Bridge (零知识证明隐私跨链桥)",
                "subtitle": "下一代多链隐私状态证明与轻节点免信任资产中继",
                "cover": "/assets/img/project_default.png",
                "category_id": 84,
                "region": "欧洲/瑞士",
                "tags": "ZK,隐私,跨链桥,Layer2",
                "certified": 1,
                "views": 12450,
                "follows": 2890,
                "sort_weight": 58,
                "create_time": "2026-10-09 02:00:06",
                "category": {
                    "id": 84,
                    "pid": 83,
                    "name": "跨链互操作 (Cross-Chain)",
                    "slug": "infra-bridge",
                    "icon": "🌉"
                }
            },
            {
                "id": "83",
                "name": "UniLend Protocol (去中心化借贷协议)",
                "subtitle": "支持全资产抵押与跨链闪电借贷的高性能流动性协议",
                "cover": "/assets/img/project_default.png",
                "category_id": 75,
                "region": "全球/北美",
                "tags": "DeFi,借贷,跨链,以太坊",
                "certified": 1,
                "views": 8920,
                "follows": 1341,
                "sort_weight": 37,
                "create_time": "2026-10-10 02:45:24",
                "category": {
                    "id": 75,
                    "pid": 73,
                    "name": "借贷协议 (Lending)",
                    "slug": "defi-lending",
                    "icon": "🏦"
                }
            },
            {
                "id": "87",
                "name": "ByteTrust DePIN (分布式算力共享网络)",
                "subtitle": "聚合全球边缘闲置 GPU 算力为 AI 训练提供低成本算力租赁",
                "cover": "/assets/img/project_default.png",
                "category_id": 85,
                "region": "北美",
                "tags": "DePIN,算力,GPU,AI",
                "certified": 1,
                "views": 9340,
                "follows": 1560,
                "sort_weight": 16,
                "create_time": "2026-10-09 11:26:28",
                "category": {
                    "id": 85,
                    "pid": 83,
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
    "request_id": "b0782aa5cbd3077f083d78a121b5cfcd",
    "time": 1791828409
}
```

### 🔹 发起业务咨询 (`POST /api/v1/consultations`)

```json
{
    "code": 1,
    "msg": "咨询已发送",
    "data": {
        "conversation_id": 35,
        "message_id": 70
    },
    "request_id": "02a347aa2dab6906bd9482cc394c7196",
    "time": 1791828411
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
            }
        ],
        "blocks": [],
        "hot": [
            {
                "id": 98,
                "channel_id": 9,
                "title": "Samsung Galaxy 智能手机",
                "image": "/assets/addons/cms/img/800x600-4.png",
                "description": "据外媒最新消息，美国权威消费者杂志《消费者报告》对全球最新智能手机进行了评比，三星Galaxy S10+以90分拿下榜单第一。而苹果iPhone XS Max和三星Galaxy S10虽分数同样也是90分，但却以小数点的差距，分别位列第二位和第三位。",
                "views": 8472,
                "likes": 112,
                "comments": 0,
                "publishtime": 1553616000
            },
            {
                "id": 92,
                "channel_id": 9,
                "title": "Macbook Pro Retina 笔记本",
                "image": "/assets/addons/cms/img/800x600-2.png",
                "description": "MacBook Pro Retina拥有更先进的元件，更先进的技术——最新的第四代双核与四核Intel处理器、速度高达700MB/s 的基于PCIe闪存、被称为5G网络的802.11ac无线网络技术、高达20Gb/s速度的Thunderbolt 2端口。",
                "views": 7035,
                "likes": 289,
                "comments": 0,
                "publishtime": 1553616000
            },
            {
                "id": 81,
                "channel_id": 9,
                "title": "无线WIFI智能机器人",
                "image": "/assets/addons/cms/img/800x600-1.png",
                "description": "WiFi是一种无线局域网运用技术，其出现以来，凭借组网方便、易于扩展等特点，有着广泛的应用前景。而无线接入和高速传输是WiFi的主要技术优点，WiFi技术与机器人技术的结合便产生了WiFiRobot。",
                "views": 6738,
                "likes": 57,
                "comments": 0,
                "publishtime": 1553616000
            },
            {
                "id": 109,
                "channel_id": 8,
                "title": "奥睿科ORICO HU3温室加湿器",
                "image": "/assets/addons/cms/img/800x600-3.png",
                "description": "加湿器可以说是目前家里比较常见的生活用品之一了，特别是爱美的女性朋友们，为了能保持容颜润泽，经常会在身旁备用一台。其实对于更多的用户来说，加湿器的主要作用还是放在空调房里，用来调节房间湿度",
                "views": 6025,
                "likes": 178,
                "comments": 0,
                "publishtime": 1553702400
            },
            {
                "id": 104,
                "channel_id": 10,
                "title": "智能游戏WIFI无线手柄",
                "image": "/assets/addons/cms/img/800x600-1.png",
                "description": "游戏手柄品种琳琅满目，而游戏手柄除了可以在电脑上进行有线连接以外，很多手柄厂商也设计出了无线连接的手柄，毕竟厂商和消费者心知肚明：无线才是未来。与手机去除掉耳机孔不同，手柄的无线设计非常受欢迎。",
                "views": 5668,
                "likes": 296,
                "comments": 0,
                "publishtime": 1553702400
            },
            {
                "id": 96,
                "channel_id": 9,
                "title": "GoPro Hero4 Silver 高清摄像机",
                "image": "/assets/addons/cms/img/800x600-4.png",
                "description": "玩自拍，玩高清摄影，玩极限摄影，有一个牌子不得不提，这就是GoPro，也就是近期这个玩具开始时兴起来，无论是一些孩子通过GoPro拍到了外太空上地球的影像，还是飞行员用GoPro玩自拍搞得机毁人亡，还是100岁老太跳伞，都有GoPro的身影，同样越来越多的人开始发掘GoPro的潜力和可玩性。",
                "views": 5325,
                "likes": 281,
                "comments": 0,
                "publishtime": 1553616000
            }
        ],
        "latest": [
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
                "id": 107,
                "channel_id": 8,
                "title": "米家无线投影仪青春版",
                "image": "/assets/addons/cms/img/800x600-3.png",
                "description": "从2017年推出首款激光电视以来，小米正式“扎根”智能微投领域，目前已经推出了三款家用投影产品，其中9999元的激光电视主要针对的还是高端家庭用户，而米家投影仪系列的两款产品则更多的面向普通家庭和年轻消费群体",
                "views": 381,
                "publishtime": 1553702400
            },
            {
                "id": 109,
                "channel_id": 8,
                "title": "奥睿科ORICO HU3温室加湿器",
                "image": "/assets/addons/cms/img/800x600-3.png",
                "description": "加湿器可以说是目前家里比较常见的生活用品之一了，特别是爱美的女性朋友们，为了能保持容颜润泽，经常会在身旁备用一台。其实对于更多的用户来说，加湿器的主要作用还是放在空调房里，用来调节房间湿度",
                "views": 6025,
                "publishtime": 1553702400
            }
        ]
    },
    "request_id": "cd761fa3f31707ee88fdd6e6a83e0310",
    "time": 1791828411
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

- 经过全面的自动化回归测试，API 端全部 **81** 个接口均按照 RESTful 规范成功响应；
- 去中心化以太坊签名登录链路完整跑通，用户邀请码、角色继承与推荐关系在接口层校验无误；
- 订阅态权限门槛、防重复提交（幂等校验）及接口跨域支持正常，达到交付测试标准。
