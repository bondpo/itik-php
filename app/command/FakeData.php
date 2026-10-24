<?php

// +----------------------------------------------------------------------
// | 控制台指令：生成/重置全系统测试假数据
// +----------------------------------------------------------------------
// | 用法：
// |   php think seed:fake          生成或补充测试假数据
// |   php think seed:fake --clean  清空旧的假测试数据并重新生成
// +----------------------------------------------------------------------

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;
use app\common\model\User;

class FakeData extends Command
{
    protected function configure()
    {
        $this->setName('seed:fake')
            ->addOption('clean', null, Option::VALUE_NONE, '是否先清理已有的测试假数据')
            ->setDescription('生成覆盖会员、钱包、订单、订阅、项目、CMS等全业务场景的测试假数据');
    }

    protected function execute(Input $input, Output $output)
    {
        $clean = $input->getOption('clean');
        $now = time();

        $output->writeln('<info>========================================</info>');
        $output->writeln('<info>开始准备生成系统测试假数据...</info>');
        $output->writeln('<info>========================================</info>');

        if ($clean) {
            $output->writeln('<comment>[1/6] 正在清理历史测试数据...</comment>');
            $this->cleanOldData();
        }

        Db::startTrans();
        try {
            // 1. 用户与钱包假数据
            $output->writeln('<comment>[2/6] 生成会员与去中心化钱包测试数据...</comment>');
            $createdUsers = $this->seedUsers();
            $output->writeln(sprintf('      已生成/就绪 %d 位测试会员（含钱包地址与邀请层级）', count($createdUsers)));

            // 2. 身份凭证
            $output->writeln('<comment>[3/6] 生成身份凭证数据 (bd_app_identity)...</comment>');
            $identityCount = $this->seedIdentities($createdUsers);
            $output->writeln(sprintf('      已生成 %d 条钱包与邮箱身份关联凭证', $identityCount));

            // 3. 订单与订阅数据
            $output->writeln('<comment>[4/7] 生成商业订单与订阅状态数据...</comment>');
            [$orderCount, $subCount] = $this->seedOrdersAndSubscriptions($createdUsers);
            $output->writeln(sprintf('      已生成 %d 笔订单（涵盖各支付状态），%d 条订阅（涵盖各订阅生命周期）', $orderCount, $subCount));

            // 4. 项目分类数据
            $output->writeln('<comment>[5/7] 生成项目赛道分类数据 (bd_app_project_category)...</comment>');
            $categoryMap = $this->seedProjectCategories();
            $output->writeln(sprintf('      已生成 %d 个项目分类（涵盖一级与二级细分赛道）', count($categoryMap)));

            // 5. 项目广场数据
            $output->writeln('<comment>[6/8] 生成项目广场测试数据 (bd_app_project)...</comment>');
            $projectCount = $this->seedProjects($createdUsers, $categoryMap);
            $output->writeln(sprintf('      已生成 %d 个测试项目（已关联分类、审核状态与认证标识）', $projectCount));

            // 6. 项目时间轴与里程碑数据
            $output->writeln('<comment>[7/8] 生成项目发展时间轴里程碑 (bd_app_project_timeline)...</comment>');
            $timelineCount = $this->seedProjectTimelines($createdUsers);
            $output->writeln(sprintf('      已为各项目生成 %d 条发展历程节点（包含主网上线、融资、重大版本等）', $timelineCount));

            // 7. CMS 文章互动评论数据
            $output->writeln('<comment>[8/9] 生成 CMS 文章互动评论数据 (bd_cms_comment)...</comment>');
            $commentCount = $this->seedCmsComments($createdUsers);
            $output->writeln(sprintf('      已生成 %d 条文章互动评论', $commentCount));

            // 8. 推流套餐假数据
            $output->writeln('<comment>[9/9] 生成推流套餐/广告位测试数据 (bd_app_promotion_package)...</comment>');
            $packageCount = $this->seedPromotionPackages();
            $output->writeln(sprintf('      已生成 %d 个推流套餐（涵盖首页推荐、行业频道、热门榜单与定向投放）', $packageCount));

            Db::commit();

            $output->writeln('<info>========================================</info>');
            $output->writeln('<info>恭喜！所有测试假数据已成功写入数据库！</info>');
            $output->writeln('<info>测试用户默认登录密码统一为: 123456</info>');
            $output->writeln('<info>========================================</info>');
        } catch (\Throwable $e) {
            Db::rollback();
            $output->writeln('<error>数据生成失败: ' . $e->getMessage() . '</error>');
            $output->writeln('<error>' . $e->getTraceAsString() . '</error>');
            return 1;
        }

        return 0;
    }

    /**
     * 清理测试数据
     */
    protected function cleanOldData()
    {
        // 查找标记为测试的用户并级联清理
        $testUserIds = Db::name('user')
            ->whereLike('username', 'test_%')
            ->column('id');

        if (!empty($testUserIds)) {
            Db::name('app_identity')->whereIn('user_id', $testUserIds)->delete();
            Db::name('app_order')->whereIn('user_id', $testUserIds)->delete();
            Db::name('app_subscription')->whereIn('user_id', $testUserIds)->delete();
            Db::name('app_project')->whereIn('owner_user_id', $testUserIds)->delete();
            Db::name('user')->whereIn('id', $testUserIds)->delete();
        }

        Db::name('app_project_category')->where('id', '>', 0)->delete();
        Db::name('app_project_timeline')->where('id', '>', 0)->delete();
        Db::name('app_promotion_package')->where('id', '>', 0)->delete();
        Db::name('cms_comment')->whereLike('content', '%[测试评论]%')->delete();
    }

    /**
     * 生成测试会员
     */
    protected function seedUsers(): array
    {
        $defaultPassword = password_hash('123456', PASSWORD_DEFAULT);
        $now = time();

        $groupOwner  = (int)(Db::name('user_group')->where('rules', 'OWNER')->value('id') ?: 6);
        $groupLeader = (int)(Db::name('user_group')->where('rules', 'LEADER')->value('id') ?: 7);
        $groupMember = (int)(Db::name('user_group')->where('rules', 'MEMBER')->value('id') ?: 8);
        $groupVisitor = (int)(Db::name('user_group')->where('rules', 'VISITOR')->value('id') ?: 9);

        // 预定义测试会员矩阵：涵盖各角色、钱包地址及层级推荐关系
        $mockUsers = [
            [
                'username'       => 'test_vitalik',
                'nickname'       => 'Vitalik.eth',
                'email'          => 'vitalik@itiklink.com',
                'mobile'         => '13800000001',
                'wallet_address' => '0x380d7f00d4bb7870cad919837269636c8420d3bd',
                'group_id'       => $groupOwner,
                'role'           => 'OWNER',
                'money'          => 28888.88,
                'score'          => 9999,
                'invite_code'    => 'VITALIK1',
                'inviter_code'   => '', // 顶层无推荐人
            ],
            [
                'username'       => 'test_satoshi',
                'nickname'       => 'Satoshi Nakamoto',
                'email'          => 'satoshi@itiklink.com',
                'mobile'         => '13800000002',
                'wallet_address' => '0xab5801a7d398351b8be11c439e05c5b3259aec9b',
                'group_id'       => $groupOwner,
                'role'           => 'OWNER',
                'money'          => 99999.00,
                'score'          => 8888,
                'invite_code'    => 'SATOSHI8',
                'inviter_code'   => 'VITALIK1', // 被 Vitalik 推荐
            ],
            [
                'username'       => 'test_alice_dao',
                'nickname'       => 'Alice (DAO Leader)',
                'email'          => 'alice@itiklink.com',
                'mobile'         => '13800000003',
                'wallet_address' => '0x70997970c51812dc3a010c7d01b50e0d17dc79c8',
                'group_id'       => $groupLeader,
                'role'           => 'LEADER',
                'money'          => 5600.00,
                'score'          => 3200,
                'invite_code'    => 'ALICE888',
                'inviter_code'   => 'SATOSHI8', // 被 Satoshi 推荐
            ],
            [
                'username'       => 'test_bob_lead',
                'nickname'       => 'Bob 社区合伙人',
                'email'          => 'bob@itiklink.com',
                'mobile'         => '13800000004',
                'wallet_address' => '0x3c44cdddb6a900fa2b585dd299e03d12fa4293bc',
                'group_id'       => $groupLeader,
                'role'           => 'LEADER',
                'money'          => 3200.50,
                'score'          => 2100,
                'invite_code'    => 'BOBLEAD9',
                'inviter_code'   => 'ALICE888', // 被 Alice 推荐
            ],
            [
                'username'       => 'test_charlie_pro',
                'nickname'       => 'Charlie (VIP 会员)',
                'email'          => 'charlie@itiklink.com',
                'mobile'         => '13800000005',
                'wallet_address' => '0x5005fad715afc474ad5a232e3eee28858b083b1e',
                'group_id'       => $groupMember,
                'role'           => 'MEMBER',
                'money'          => 800.00,
                'score'          => 650,
                'invite_code'    => 'CHARLIE6',
                'inviter_code'   => 'BOBLEAD9', // 被 Bob 推荐
            ],
            [
                'username'       => 'test_david_trader',
                'nickname'       => 'David 链上套利者',
                'email'          => 'david@itiklink.com',
                'mobile'         => '13800000006',
                'wallet_address' => '0x1db3439a222c519ab44bb1144fc28167b4fa6ee6',
                'group_id'       => $groupMember,
                'role'           => 'MEMBER',
                'money'          => 450.00,
                'score'          => 420,
                'invite_code'    => 'DAVID999',
                'inviter_code'   => 'CHARLIE6',
            ],
            [
                'username'       => 'test_eva_visitor',
                'nickname'       => 'Eva (游客用户)',
                'email'          => 'eva@itiklink.com',
                'mobile'         => '13800000007',
                'wallet_address' => '0xf66d8330d1c9dc19d58e4d1113e9120c26f2dc75',
                'group_id'       => $groupVisitor,
                'role'           => 'VISITOR',
                'money'          => 0.00,
                'score'          => 50,
                'invite_code'    => 'EVAWEB33',
                'inviter_code'   => 'DAVID999',
            ],
            [
                'username'       => 'test_frank_web3',
                'nickname'       => 'Frank 0x9999...a1b2',
                'email'          => 'frank@itiklink.com',
                'mobile'         => '13800000008',
                'wallet_address' => '0x999999cf1046e68e36e1aa2e0e07105eddd8a1b2',
                'group_id'       => $groupVisitor,
                'role'           => 'VISITOR',
                'money'          => 0.00,
                'score'          => 0,
                'invite_code'    => 'FRANK007',
                'inviter_code'   => 'EVAWEB33',
            ],
        ];

        $codeToIdMap = [];
        $result = [];

        // 第一遍：创建或更新用户
        foreach ($mockUsers as $u) {
            $existing = Db::name('user')->where('username', $u['username'])->find();
            if ($existing) {
                $uid = (int)$existing['id'];
                Db::name('user')->where('id', $uid)->update([
                    'nickname'       => $u['nickname'],
                    'wallet_address' => strtolower($u['wallet_address']),
                    'group_id'       => $u['group_id'],
                    'money'          => $u['money'],
                    'score'          => $u['score'],
                    'invite_code'    => $u['invite_code'],
                    'status'         => 'normal',
                    'update_time'    => $now,
                ]);
            } else {
                $uid = (int)Db::name('user')->insertGetId([
                    'username'       => $u['username'],
                    'nickname'       => $u['nickname'],
                    'password'       => $defaultPassword,
                    'email'          => $u['email'],
                    'mobile'         => $u['mobile'],
                    'wallet_address' => strtolower($u['wallet_address']),
                    'group_id'       => $u['group_id'],
                    'invite_code'    => $u['invite_code'],
                    'inviter_id'     => 0,
                    'money'          => $u['money'],
                    'score'          => $u['score'],
                    'level'          => 1,
                    'avatar'         => '/assets/img/avatar.png',
                    'status'         => 'normal',
                    'jointime'       => $now - rand(86400, 864000),
                    'logintime'      => $now - rand(60, 3600),
                    'loginip'        => '127.0.0.1',
                    'create_time'    => $now - rand(86400, 864000),
                    'update_time'    => $now,
                ]);
            }

            $u['id'] = $uid;
            $codeToIdMap[$u['invite_code']] = $uid;
            $result[] = $u;
        }

        // 第二遍：建立推荐人外键绑定
        foreach ($result as $idx => $u) {
            if (!empty($u['inviter_code']) && isset($codeToIdMap[$u['inviter_code']])) {
                $inviterId = $codeToIdMap[$u['inviter_code']];
                Db::name('user')->where('id', $u['id'])->update(['inviter_id' => $inviterId]);
                $result[$idx]['inviter_id'] = $inviterId;
            }
        }

        return $result;
    }

    /**
     * 生成身份凭证数据
     */
    protected function seedIdentities(array $users): int
    {
        $now = time();
        $count = 0;

        foreach ($users as $u) {
            $uid = (int)$u['id'];

            // 1. 邮箱凭证
            if (!empty($u['email'])) {
                $exist = Db::name('app_identity')
                    ->where('user_id', $uid)
                    ->where('type', 'email')
                    ->find();
                if (!$exist) {
                    Db::name('app_identity')->insert([
                        'user_id'     => $uid,
                        'type'        => 'email',
                        'identifier'  => $u['email'],
                        'verified_at' => $now - 3600,
                        'create_time' => $now,
                        'update_time' => $now,
                    ]);
                    $count++;
                }
            }

            // 2. 钱包凭证
            if (!empty($u['wallet_address'])) {
                $walletLower = strtolower($u['wallet_address']);
                $exist = Db::name('app_identity')
                    ->where('user_id', $uid)
                    ->where('type', 'wallet')
                    ->find();
                if (!$exist) {
                    Db::name('app_identity')->insert([
                        'user_id'     => $uid,
                        'type'        => 'wallet',
                        'identifier'  => $walletLower,
                        'verified_at' => $now - 1800,
                        'create_time' => $now,
                        'update_time' => $now,
                    ]);
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * 生成订单与订阅数据
     */
    protected function seedOrdersAndSubscriptions(array $users): array
    {
        $now = time();
        $orderCount = 0;
        $subCount = 0;

        // 订单模版数据
        $orderTemplates = [
            // 已支付订单 -> 对应生成 ACTIVE 订阅
            [
                'plan_code'   => 'PLAN_OWNER_1Y',
                'role_code'   => 'OWNER',
                'user_index'  => 0, // Vitalik
                'status'      => 'paid',
                'amount'      => 3000.00,
                'pay_type'    => 'wallet',
                'sub_status'  => 'ACTIVE',
                'duration'    => 365,
            ],
            [
                'plan_code'   => 'PLAN_OWNER_1Y',
                'role_code'   => 'OWNER',
                'user_index'  => 1, // Satoshi
                'status'      => 'paid',
                'amount'      => 3000.00,
                'pay_type'    => 'usdt',
                'sub_status'  => 'ACTIVE',
                'duration'    => 365,
            ],
            [
                'plan_code'   => 'PLAN_LEADER_1Y',
                'role_code'   => 'LEADER',
                'user_index'  => 2, // Alice
                'status'      => 'paid',
                'amount'      => 1000.00,
                'pay_type'    => 'wallet',
                'sub_status'  => 'ACTIVE',
                'duration'    => 365,
            ],
            // 宽限期订单与订阅 (GRACE)
            [
                'plan_code'   => 'PLAN_LEADER_1Y',
                'role_code'   => 'LEADER',
                'user_index'  => 3, // Bob
                'status'      => 'paid',
                'amount'      => 1000.00,
                'pay_type'    => 'stripe',
                'sub_status'  => 'GRACE',
                'duration'    => -5, // 已到期5天但未超宽限期
            ],
            // 普通会员生效中 (ACTIVE)
            [
                'plan_code'   => 'PLAN_MEMBER_1Y',
                'role_code'   => 'MEMBER',
                'user_index'  => 4, // Charlie
                'status'      => 'paid',
                'amount'      => 100.00,
                'pay_type'    => 'wallet',
                'sub_status'  => 'ACTIVE',
                'duration'    => 200,
            ],
            // 已过期订阅 (EXPIRED)
            [
                'plan_code'   => 'PLAN_MEMBER_1Y',
                'role_code'   => 'MEMBER',
                'user_index'  => 5, // David
                'status'      => 'paid',
                'amount'      => 100.00,
                'pay_type'    => 'usdt',
                'sub_status'  => 'EXPIRED',
                'duration'    => -40, // 已过期40天
            ],
            // 待支付订单 (pending)
            [
                'plan_code'   => 'PLAN_OWNER_1Y',
                'role_code'   => 'OWNER',
                'user_index'  => 6, // Eva
                'status'      => 'pending',
                'amount'      => 3000.00,
                'pay_type'    => '',
                'sub_status'  => 'PENDING_PAYMENT',
                'duration'    => 0,
            ],
            // 已关闭订单 (closed)
            [
                'plan_code'   => 'PLAN_MEMBER_1Y',
                'role_code'   => 'MEMBER',
                'user_index'  => 7, // Frank
                'status'      => 'closed',
                'amount'      => 100.00,
                'pay_type'    => '',
                'sub_status'  => '',
                'duration'    => 0,
            ],
            // 已退款订单 (refunded)
            [
                'plan_code'   => 'PLAN_LEADER_1Y',
                'role_code'   => 'LEADER',
                'user_index'  => 2, // Alice
                'status'      => 'refunded',
                'amount'      => 1000.00,
                'pay_type'    => 'stripe',
                'sub_status'  => '',
                'duration'    => 0,
            ],
        ];

        foreach ($orderTemplates as $i => $tmpl) {
            $user = $users[$tmpl['user_index']] ?? null;
            if (!$user) {
                continue;
            }

            $orderNo = 'ORD' . date('YmdHis') . sprintf('%03d', $i + 1) . rand(10, 99);
            $isPaid = ($tmpl['status'] === 'paid');
            $paidAt = $isPaid ? ($now - rand(3600, 86400 * 30)) : 0;
            $tradeNo = $isPaid ? ('0x' . bin2hex(random_bytes(16))) : '';

            $orderId = (int)Db::name('app_order')->insertGetId([
                'order_no'       => $orderNo,
                'user_id'        => $user['id'],
                'plan_code'      => $tmpl['plan_code'],
                'amount'         => $tmpl['amount'],
                'currency'       => 'USD',
                'status'         => $tmpl['status'],
                'pay_type'       => $tmpl['pay_type'],
                'pay_trade_no'   => $tradeNo,
                'pay_payload'    => $isPaid ? json_encode(['network' => 'Ethereum', 'method' => $tmpl['pay_type']]) : '',
                'idempotent_key' => md5($orderNo),
                'coupon_code'    => '',
                'discount'       => 0.00,
                'paid_at'        => $paidAt,
                'expire_at'      => $now + 1800,
                'create_time'    => $now - rand(86400, 864000),
                'update_time'    => $now,
            ]);
            $orderCount++;

            // 如果有订阅状态需求，生成关联的订阅
            if (!empty($tmpl['sub_status'])) {
                $duration = $tmpl['duration'];
                if ($duration > 0) {
                    $startAt = $now - 86400 * 10;
                    $endAt = $startAt + 86400 * $duration;
                    $graceEndAt = $endAt + 86400 * 7;
                } else {
                    $endAt = $now + 86400 * $duration; // 过去的时间
                    $startAt = $endAt - 86400 * 365;
                    $graceEndAt = $endAt + 86400 * 7;
                }

                Db::name('app_subscription')->insert([
                    'user_id'         => $user['id'],
                    'plan_code'       => $tmpl['plan_code'],
                    'role_code'       => $tmpl['role_code'],
                    'status'          => $tmpl['sub_status'],
                    'start_at'        => $startAt,
                    'end_at'          => $endAt,
                    'grace_end_at'    => $graceEndAt,
                    'source_order_id' => $orderId,
                    'suspend_reason'  => '',
                    'create_time'     => $startAt,
                    'update_time'     => $now,
                ]);
                $subCount++;
            }
        }

        return [$orderCount, $subCount];
    }

    /**
     * 生成项目分类假数据
     */
    protected function seedProjectCategories(): array
    {
        $now = time();
        $categories = [
            // 1. DeFi
            [
                'name'        => 'DeFi 金融',
                'slug'        => 'defi',
                'icon'        => '💰',
                'description' => '去中心化金融协议，涵盖交易、借贷、收益聚合与衍生品',
                'weigh'       => 100,
                'children'    => [
                    ['name' => '去中心化交易 (DEX)', 'slug' => 'defi-dex', 'icon' => '🔄', 'description' => 'AMM 自动化做市商与订单簿交易协议', 'weigh' => 90],
                    ['name' => '借贷协议 (Lending)', 'slug' => 'defi-lending', 'icon' => '🏦', 'description' => '超额抵押与闪电贷去中心化借贷', 'weigh' => 80],
                    ['name' => '收益聚合 (Yield)', 'slug' => 'defi-yield', 'icon' => '📈', 'description' => '自动化收益耕作与流动性质押', 'weigh' => 70],
                ]
            ],
            // 2. Layer 2 & 扩容
            [
                'name'        => 'Layer 2 扩容',
                'slug'        => 'layer2',
                'icon'        => '⚡',
                'description' => '以太坊高性能二层扩容网络与侧链解决方案',
                'weigh'       => 90,
                'children'    => [
                    ['name' => 'ZK-Rollup (零知识证明)', 'slug' => 'l2-zk', 'icon' => '🛡️', 'description' => '基于 zk-SNARKs/STARKs 的超高 TPS 扩容', 'weigh' => 90],
                    ['name' => 'Optimistic Rollup', 'slug' => 'l2-op', 'icon' => '🚀', 'description' => '基于欺诈证明的成熟通用计算二层网络', 'weigh' => 80],
                ]
            ],
            // 3. AI + Web3
            [
                'name'        => 'AI + Web3',
                'slug'        => 'ai-web3',
                'icon'        => '🤖',
                'description' => '人工智能与链上智能合约、去中心化算力结合',
                'weigh'       => 80,
                'children'    => [
                    ['name' => 'AI Agents (智能体)', 'slug' => 'ai-agents', 'icon' => '🧠', 'description' => '自主链上交互与决策自治 Agent', 'weigh' => 90],
                    ['name' => '链上数据分析 (Analytics)', 'slug' => 'ai-analytics', 'icon' => '📊', 'description' => '大数据与机器学习链上行为预警', 'weigh' => 80],
                ]
            ],
            // 4. Infrastructure
            [
                'name'        => '底层基础设施',
                'slug'        => 'infrastructure',
                'icon'        => '🏗️',
                'description' => '去中心化网络底座，包含预言机、跨链桥、去中心化存储',
                'weigh'       => 70,
                'children'    => [
                    ['name' => '跨链互操作 (Cross-Chain)', 'slug' => 'infra-bridge', 'icon' => '🌉', 'description' => '免信任多链状态中继与跨链资产转移', 'weigh' => 90],
                    ['name' => 'DePIN (分布式硬件网络)', 'slug' => 'depin', 'icon' => '📡', 'description' => '去中心化物理基础设施与边缘硬件', 'weigh' => 80],
                ]
            ],
            // 5. 身份与社交 (SocialFi & DID)
            [
                'name'        => '社交与身份 (Social & DID)',
                'slug'        => 'social-did',
                'icon'        => '🌐',
                'description' => '去中心化身份识别、信誉系统与抗审查链上社交',
                'weigh'       => 60,
                'children'    => [
                    ['name' => '去中心化身份 (DID & SBT)', 'slug' => 'did', 'icon' => '🪪', 'description' => '自主可控的链上身份凭证与防女巫网络', 'weigh' => 90],
                ]
            ],
            // 6. GameFi & 元宇宙
            [
                'name'        => 'GameFi 游戏生态',
                'slug'        => 'gamefi',
                'icon'        => '🎮',
                'description' => '链上游戏、电子竞技与元宇宙公会资产管理',
                'weigh'       => 50,
                'children'    => [
                    ['name' => '游戏公会与 DAO', 'slug' => 'gamefi-dao', 'icon' => '⚔️', 'description' => '公会国库管理与 NFT 资产分账租赁', 'weigh' => 90],
                ]
            ],
            // 7. RWA 真实世界资产
            [
                'name'        => 'RWA 真实资产',
                'slug'        => 'rwa',
                'icon'        => '🏛️',
                'description' => '绿色碳资产、房地产、国债与大宗商品链上代币化',
                'weigh'       => 40,
                'children'    => []
            ],
        ];

        $slugToId = [];
        foreach ($categories as $cat) {
            $parentId = Db::name('app_project_category')->insertGetId([
                'pid'         => 0,
                'name'        => $cat['name'],
                'slug'        => $cat['slug'],
                'icon'        => $cat['icon'],
                'description' => $cat['description'],
                'weigh'       => $cat['weigh'],
                'status'      => 'normal',
                'create_time' => $now,
                'update_time' => $now,
            ]);
            $slugToId[$cat['slug']] = (int)$parentId;

            foreach ($cat['children'] as $sub) {
                $subId = Db::name('app_project_category')->insertGetId([
                    'pid'         => $parentId,
                    'name'        => $sub['name'],
                    'slug'        => $sub['slug'],
                    'icon'        => $sub['icon'],
                    'description' => $sub['description'],
                    'weigh'       => $sub['weigh'],
                    'status'      => 'normal',
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
                $slugToId[$sub['slug']] = (int)$subId;
            }
        }

        return $slugToId;
    }

    /**
     * 生成项目广场测试数据
     */
    protected function seedProjects(array $users, array $categoryMap = []): int
    {
        $now = time();
        $ownerId1 = $users[0]['id'] ?? 1;
        $ownerId2 = $users[1]['id'] ?? 1;

        $mockProjects = [
            [
                'name'          => 'UniLend Protocol (去中心化借贷协议)',
                'subtitle'      => '支持全资产抵押与跨链闪电借贷的高性能流动性协议',
                'cat_slug'      => 'defi-lending',
                'region'        => '全球/北美',
                'tags'          => 'DeFi,借贷,跨链,以太坊',
                'status'        => 'normal',
                'verify_status' => 'normal',
                'certified'     => 1,
                'views'         => 8920,
                'follows'       => 1340,
                'owner_id'      => $ownerId1,
            ],
            [
                'name'          => 'ZK-Shield Bridge (零知识证明隐私跨链桥)',
                'subtitle'      => '下一代多链隐私状态证明与轻节点免信任资产中继',
                'cat_slug'      => 'infra-bridge',
                'region'        => '欧洲/瑞士',
                'tags'          => 'ZK,隐私,跨链桥,Layer2',
                'status'        => 'normal',
                'verify_status' => 'normal',
                'certified'     => 1,
                'views'         => 12450,
                'follows'       => 2890,
                'owner_id'      => $ownerId1,
            ],
            [
                'name'          => 'NeuroGraph AI (链上大数据智能分析预测平台)',
                'subtitle'      => '利用大语言模型与知识图谱对鲸鱼钱包动向实时预警',
                'cat_slug'      => 'ai-analytics',
                'region'        => '亚太/新加坡',
                'tags'          => 'AI,链上数据,大数据,预警',
                'status'        => 'pending',
                'verify_status' => 'pending',
                'certified'     => 0,
                'views'         => 3420,
                'follows'       => 450,
                'owner_id'      => $ownerId2,
            ],
            [
                'name'          => 'MetaDID (去中心化多链身份凭证协议)',
                'subtitle'      => '基于 EIP-4361 与 SBT 构建的无感社交信誉与防女巫身份网络',
                'cat_slug'      => 'did',
                'region'        => '全球',
                'tags'          => 'DID,身份凭证,Web3,SBT',
                'status'        => 'normal',
                'verify_status' => 'normal',
                'certified'     => 1,
                'views'         => 6780,
                'follows'       => 980,
                'owner_id'      => $ownerId2,
            ],
            [
                'name'          => 'ByteTrust DePIN (分布式算力共享网络)',
                'subtitle'      => '聚合全球边缘闲置 GPU 算力为 AI 训练提供低成本算力租赁',
                'cat_slug'      => 'depin',
                'region'        => '北美',
                'tags'          => 'DePIN,算力,GPU,AI',
                'status'        => 'normal',
                'verify_status' => 'normal',
                'certified'     => 1,
                'views'         => 9340,
                'follows'       => 1560,
                'owner_id'      => $ownerId1,
            ],
            [
                'name'          => 'GreenCarbon RWA (链上绿色碳资产聚合协议)',
                'subtitle'      => '实物碳汇资产上链交易，赋能跨国企业碳中和结算',
                'cat_slug'      => 'rwa',
                'region'        => '欧洲',
                'tags'          => 'RWA,碳汇,碳中和,ESG',
                'status'        => 'rejected',
                'verify_status' => 'rejected',
                'certified'     => 0,
                'views'         => 820,
                'follows'       => 45,
                'owner_id'      => $ownerId2,
            ],
            [
                'name'          => 'CyberGuild DAO (元宇宙电竞公会资产管理平台)',
                'subtitle'      => '自动化链上分账与 GameFi 租借智能合约管理金库',
                'cat_slug'      => 'gamefi-dao',
                'region'        => '东南亚',
                'tags'          => 'DAO,GameFi,电竞,NFT',
                'status'        => 'draft',
                'verify_status' => 'none',
                'certified'     => 0,
                'views'         => 120,
                'follows'       => 12,
                'owner_id'      => $ownerId1,
            ],
            [
                'name'          => 'FlashSwap DEX (极速流动性聚合交易协议)',
                'subtitle'      => '结合 PMM 算法与零滑点闪兑的高资本效率去中心化交易所',
                'cat_slug'      => 'defi-dex',
                'region'        => '全球',
                'tags'          => 'DEX,去中心化交易,闪兑,流动性',
                'status'        => 'normal',
                'verify_status' => 'normal',
                'certified'     => 1,
                'views'         => 15600,
                'follows'       => 3210,
                'owner_id'      => $ownerId2,
            ],
        ];

        $count = 0;
        foreach ($mockProjects as $p) {
            $catId = $categoryMap[$p['cat_slug']] ?? 1;

            $existing = Db::name('app_project')->where('name', $p['name'])->find();
            if ($existing) {
                Db::name('app_project')->where('id', $existing['id'])->update([
                    'category_id'   => $catId,
                    'status'        => $p['status'],
                    'verify_status' => $p['verify_status'],
                    'certified'     => $p['certified'],
                    'views'         => $p['views'],
                    'follows'       => $p['follows'],
                    'update_time'   => $now,
                ]);
            } else {
                Db::name('app_project')->insert([
                    'owner_user_id'   => $p['owner_id'],
                    'category_id'     => $catId,
                    'name'            => $p['name'],
                    'subtitle'        => $p['subtitle'],
                    'cover'           => '/assets/img/project_default.png',
                    'region'          => $p['region'],
                    'tags'            => $p['tags'],
                    'summary'         => $p['subtitle'],
                    'content'         => '<p>' . $p['subtitle'] . '。欢迎与我们团队联系对接生态伙伴合作！</p>',
                    'status'          => $p['status'],
                    'verify_status'   => $p['verify_status'],
                    'certified'       => $p['certified'],
                    'risk_level'      => 'LOW',
                    'seo_title'       => $p['name'],
                    'seo_keywords'    => $p['tags'],
                    'seo_description' => $p['subtitle'],
                    'sort_weight'     => rand(10, 100),
                    'views'           => $p['views'],
                    'follows'         => $p['follows'],
                    'create_time'     => $now - rand(86400, 864000),
                    'update_time'     => $now,
                ]);
            }
            $count++;
        }

        return $count;
    }

    /**
     * 生成 CMS 文章互动评论数据
     */
    protected function seedCmsComments(array $users): int
    {
        $now = time();
        $firstArchiveId = (int)(Db::name('cms_archives')->order('id', 'asc')->value('id') ?: 1);
        $mockComments = [
            '这个项目的跨链借贷效率确实高，体验非常顺畅！[测试评论]',
            '去中心化钱包签名登录秒级响应，比传统邮箱验证码爽快多了！[测试评论]',
            '期待上线更多的 Layer2 和 DePIN 算力共享项目！[测试评论]',
            '推荐人邀请码功能很完善，下级团队数据一目了然！[测试评论]',
            '文档介绍得很详实，已收藏并分享给社区同仁。[测试评论]',
            '支持！希望能持续丰富 Web3 生态合作！[测试评论]',
        ];

        $commentCount = 0;
        foreach ($mockComments as $k => $commentText) {
            $user = $users[$k % count($users)];
            Db::name('cms_comment')->insert([
                'user_id'    => $user['id'],
                'type'       => 'archives',
                'aid'        => $firstArchiveId,
                'pid'        => 0,
                'content'    => $commentText,
                'comments'   => 0,
                'ip'         => '114.88.' . rand(1, 254) . '.' . rand(1, 254),
                'useragent'  => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
                'subscribe'  => 0,
                'status'     => 'normal',
                'createtime' => $now - rand(100, 86400),
                'updatetime' => $now,
            ]);
            $commentCount++;
        }

        return $commentCount;
    }

    /**
     * 生成项目发展时间轴与里程碑数据
     */
    protected function seedProjectTimelines(array $users): int
    {
        $projects = Db::name('app_project')->select()->toArray();
        if (empty($projects)) {
            return 0;
        }

        $now = time();
        $timelineTemplates = [
            [
                'title'   => '项目立项与白皮书 1.0 正式发布',
                'tag'     => '里程碑',
                'offset'  => 86400 * 360,
                'summary' => '团队正式确立去中心化架构蓝图，公开首版白皮书与技术架构设计说明。',
                'content' => '<p>由核心研发团队共同撰写的首版白皮书正式开源，确立了系统的经济模型与免信任协议基石。</p>',
                'link'    => 'https://github.com/ethereum/wiki',
            ],
            [
                'title'   => '完成种子轮与战略轮 600 万美元融资',
                'tag'     => '融资历程',
                'offset'  => 86400 * 270,
                'summary' => '由顶级 Web3 基金领投，获得 600 万美元战略注资，重点用于核心协议研发与生态开发者激励。',
                'content' => '<p>本轮融资将加速底层算法优化、安全审计及全球社区拓展，为测试网发布提供坚实后盾。</p>',
                'link'    => 'https://mirror.xyz',
            ],
            [
                'title'   => 'Alpha 测试网正式上线并开启公测',
                'tag'     => '技术发布',
                'offset'  => 86400 * 180,
                'summary' => '首个面向社区的公共测试网部署完成，超过 50,000 名独立链上地址参与交互测试。',
                'content' => '<p>测试网期间网络峰值 TPS 突破 2,800，完成压力测试与多轮极限吞吐量验证。</p>',
                'link'    => 'https://etherscan.io',
            ],
            [
                'title'   => '智能合约通过 CertiK 与 OpenZeppelin 双重安全审计',
                'tag'     => '安全审计',
                'offset'  => 86400 * 90,
                'summary' => '经权威安全机构多轮静态分析与形式化验证，所有核心业务逻辑合约零高危漏洞，正式出具审计报告。',
                'content' => '<p>安全是 Web3 的立身之本，审计报告全文本已链上存证并向全球社区完全公开。</p>',
                'link'    => 'https://certik.com',
            ],
            [
                'title'   => '主网 1.0 正式上线与生态发展计划启动',
                'tag'     => '里程碑',
                'offset'  => 86400 * 20,
                'summary' => '主网创世区块正式生成，开放质押、流动性做市与跨链桥梁，首批生态 DApp 全面接入。',
                'content' => '<p>标志着项目正式步入成熟商用阶段，全球社区长、开发者与投资者可无缝体验高效服务。</p>',
                'link'    => 'https://itikecosystem.io',
            ],
        ];

        $totalInserted = 0;
        foreach ($projects as $proj) {
            $projId = (int)$proj['id'];
            $ownerId = (int)$proj['owner_user_id'];

            // 为每个项目随机挑选 3~4 个时间轴节点
            $chosenNodes = array_slice($timelineTemplates, 0, rand(3, 5));
            foreach ($chosenNodes as $idx => $node) {
                Db::name('app_project_timeline')->insert([
                    'project_id'  => $projId,
                    'user_id'     => $ownerId,
                    'title'       => $node['title'],
                    'tag'         => $node['tag'],
                    'event_time'  => $now - $node['offset'],
                    'summary'     => $node['summary'],
                    'content'     => $node['content'],
                    'link_url'    => $node['link'],
                    'weigh'       => 100 - $idx * 10,
                    'status'      => 'normal',
                    'create_time' => $now - $node['offset'] + 3600,
                    'update_time' => $now,
                ]);
                $totalInserted++;
            }
        }

        return $totalInserted;
    }

    /**
     * 生成推流套餐测试假数据 (bd_app_promotion_package)
     */
    protected function seedPromotionPackages(): int
    {
        $now = time();
        $packages = [
            [
                'name'          => '首页焦点轮播 · 7天极速冲量包',
                'position'      => 'home',
                'price'         => 499.00,
                'currency'      => 'USD',
                'duration_days' => 7,
                'exposure_cap'  => 50000,
                'feature_flags' => json_encode(['首页首屏轮播Banner', '全域高光标识', '智能冷启动分发'], JSON_UNESCAPED_UNICODE),
                'status'        => 'normal',
                'create_time'   => $now - 86400 * 10,
                'update_time'   => $now,
            ],
            [
                'name'          => '首页黄金推荐位 · 14天全域曝光旗舰版',
                'position'      => 'home',
                'price'         => 999.00,
                'currency'      => 'USD',
                'duration_days' => 14,
                'exposure_cap'  => 150000,
                'feature_flags' => json_encode(['首页核心推荐信息流前三位', '金牌推荐徽章', '优先匹配推荐官推广'], JSON_UNESCAPED_UNICODE),
                'status'        => 'normal',
                'create_time'   => $now - 86400 * 8,
                'update_time'   => $now,
            ],
            [
                'name'          => '首页至尊霸屏 · 30天品牌全月统治包',
                'position'      => 'home',
                'price'         => 1899.00,
                'currency'      => 'USD',
                'duration_days' => 30,
                'exposure_cap'  => 500000,
                'feature_flags' => json_encode(['首页独占Banner大卡', '跨端推送强曝光', '专属1v1投放优化师支持', '全站弹窗推荐'], JSON_UNESCAPED_UNICODE),
                'status'        => 'normal',
                'create_time'   => $now - 86400 * 6,
                'update_time'   => $now,
            ],
            [
                'name'          => '行业频道头条 · 7天精准获客流',
                'position'      => 'channel',
                'price'         => 199.00,
                'currency'      => 'USD',
                'duration_days' => 7,
                'exposure_cap'  => 20000,
                'feature_flags' => json_encode(['垂直一级行业分类频道置顶', '垂类高意向用户触达', '频道内突出显示'], JSON_UNESCAPED_UNICODE),
                'status'        => 'normal',
                'create_time'   => $now - 86400 * 5,
                'update_time'   => $now,
            ],
            [
                'name'          => '行业频道专区 · 15天垂直深耕套餐',
                'position'      => 'channel',
                'price'         => 399.00,
                'currency'      => 'USD',
                'duration_days' => 15,
                'exposure_cap'  => 60000,
                'feature_flags' => json_encode(['所属赛道频道长期置顶', '行业资讯及案例联动推荐', '频道搜索权重加倍'], JSON_UNESCAPED_UNICODE),
                'status'        => 'normal',
                'create_time'   => $now - 86400 * 4,
                'update_time'   => $now,
            ],
            [
                'name'          => '热门项目榜单 · 7天飙升打榜特权',
                'position'      => 'rank',
                'price'         => 299.00,
                'currency'      => 'USD',
                'duration_days' => 7,
                'exposure_cap'  => 30000,
                'feature_flags' => json_encode(['热门榜前三位轮播展示', '飙升动态徽章', '投资者看板热推'], JSON_UNESCAPED_UNICODE),
                'status'        => 'normal',
                'create_time'   => $now - 86400 * 3,
                'update_time'   => $now,
            ],
            [
                'name'          => '热门项目榜单 · 30天全月霸榜荣誉包',
                'position'      => 'rank',
                'price'         => 1199.00,
                'currency'      => 'USD',
                'duration_days' => 30,
                'exposure_cap'  => 200000,
                'feature_flags' => json_encode(['榜单前排长期锁定', '官方生态周报置顶推荐', '高信誉认证专区光环'], JSON_UNESCAPED_UNICODE),
                'status'        => 'normal',
                'create_time'   => $now - 86400 * 2,
                'update_time'   => $now,
            ],
            [
                'name'          => 'AI定向推荐 · 7天关联竞品精准拦截',
                'position'      => 'target',
                'price'         => 349.00,
                'currency'      => 'USD',
                'duration_days' => 7,
                'exposure_cap'  => 40000,
                'feature_flags' => json_encode(['同赛道关联项目详情页推荐', '基于用户浏览行为AI预测匹配', '高潜投资人定向拦截'], JSON_UNESCAPED_UNICODE),
                'status'        => 'normal',
                'create_time'   => $now - 86400 * 2,
                'update_time'   => $now,
            ],
            [
                'name'          => '全网定向穿透 · 14天高转化追踪包',
                'position'      => 'target',
                'price'         => 699.00,
                'currency'      => 'USD',
                'duration_days' => 14,
                'exposure_cap'  => 100000,
                'feature_flags' => json_encode(['全站关联项目穿透投放', '浏览未关注用户二次挽留', '私域社群定向触达'], JSON_UNESCAPED_UNICODE),
                'status'        => 'normal',
                'create_time'   => $now - 86400 * 1,
                'update_time'   => $now,
            ],
            [
                'name'          => '新项目首发体验包 (新客特惠测试)',
                'position'      => 'home',
                'price'         => 99.00,
                'currency'      => 'USD',
                'duration_days' => 3,
                'exposure_cap'  => 10000,
                'feature_flags' => json_encode(['新手冷启动扶持', '首页新秀专区展现', '特惠试水测试'], JSON_UNESCAPED_UNICODE),
                'status'        => 'normal',
                'create_time'   => $now - 3600 * 12,
                'update_time'   => $now,
            ],
        ];

        $count = 0;
        foreach ($packages as $pkg) {
            $exists = Db::name('app_promotion_package')->where('name', $pkg['name'])->find();
            if (!$exists) {
                Db::name('app_promotion_package')->insert($pkg);
                $count++;
            }
        }

        return $count;
    }
}
