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
            $output->writeln('<comment>[4/6] 生成商业订单与订阅状态数据...</comment>');
            [$orderCount, $subCount] = $this->seedOrdersAndSubscriptions($createdUsers);
            $output->writeln(sprintf('      已生成 %d 笔订单（涵盖各支付状态），%d 条订阅（涵盖各订阅生命周期）', $orderCount, $subCount));

            // 4. 项目广场数据
            $output->writeln('<comment>[5/6] 生成项目广场测试数据 (bd_app_project)...</comment>');
            $projectCount = $this->seedProjects($createdUsers);
            $output->writeln(sprintf('      已生成 %d 个测试项目（涵盖各审核状态与认证标识）', $projectCount));

            // 5. CMS 单页、专题与评论数据
            $output->writeln('<comment>[6/6] 生成 CMS 单页、专题与互动评论数据...</comment>');
            [$pageCount, $specialCount, $commentCount] = $this->seedCmsData($createdUsers);
            $output->writeln(sprintf('      已生成 %d 篇单页，%d 个专题，%d 条评论', $pageCount, $specialCount, $commentCount));

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

        Db::name('cms_page')->whereLike('diyname', 'fake_%')->delete();
        Db::name('cms_special')->whereLike('diyname', 'fake_%')->delete();
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
                'wallet_address' => '0xd8da6bf26964af9d7eed9e03e53415d37aa96045',
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
                'wallet_address' => '0x1db3439a222c519ab44bb1144fc28167b4fa6ee6',
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
                'wallet_address' => '0x90f79bf6eb2c4f870365e785982e1f101e93b906',
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
                'wallet_address' => '0x15d34aaf54267db7d7c367839aaf71a00a2c6a65',
                'group_id'       => $groupMember,
                'role'           => 'MEMBER',
                'money'          => 450.00,
                'score'          => 420,
                'invite_code'    => 'DAVID999',
                'inviter_code'   => 'CHARLIE6',
            ],
            [
                'username'       => 'test_eva_visitor',
                'nickname'       => 'Eva 0x9999...a1b2',
                'email'          => 'eva@itiklink.com',
                'mobile'         => '13800000007',
                'wallet_address' => '0x999999cf1046e68e36e1aa2e0e07105eddd8a1b2',
                'group_id'       => $groupVisitor,
                'role'           => 'VISITOR',
                'money'          => 0.00,
                'score'          => 50,
                'invite_code'    => 'EVAWEB33',
                'inviter_code'   => 'DAVID999',
            ],
            [
                'username'       => 'test_frank_web3',
                'nickname'       => 'Frank 0x7099...79c8',
                'email'          => 'frank@itiklink.com',
                'mobile'         => '13800000008',
                'wallet_address' => '0x70997970c51812dc3a010c7d01b50e0d17dc79c8',
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
     * 生成项目广场测试数据
     */
    protected function seedProjects(array $users): int
    {
        $now = time();
        $ownerId1 = $users[0]['id'] ?? 1;
        $ownerId2 = $users[1]['id'] ?? 1;

        $mockProjects = [
            [
                'name'          => 'UniLend Protocol (去中心化借贷协议)',
                'subtitle'      => '支持全资产抵押与跨链闪电借贷的高性能流动性协议',
                'category_l1'   => 'DeFi',
                'category_l2'   => 'Lending',
                'category_l3'   => 'CrossChain',
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
                'category_l1'   => 'Infrastructure',
                'category_l2'   => 'Layer2',
                'category_l3'   => 'ZK-Rollup',
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
                'category_l1'   => 'AI+Web3',
                'category_l2'   => 'Analytics',
                'category_l3'   => 'Predictive',
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
                'category_l1'   => 'Identity',
                'category_l2'   => 'DID',
                'category_l3'   => 'SBT',
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
                'category_l1'   => 'DePIN',
                'category_l2'   => 'Compute',
                'category_l3'   => 'GPU Cloud',
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
                'category_l1'   => 'RWA',
                'category_l2'   => 'Carbon',
                'category_l3'   => 'GreenFinance',
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
                'category_l1'   => 'GameFi',
                'category_l2'   => 'DAO',
                'category_l3'   => 'Esports',
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
                'category_l1'   => 'DeFi',
                'category_l2'   => 'DEX',
                'category_l3'   => 'AMM',
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
            $existing = Db::name('app_project')->where('name', $p['name'])->find();
            if ($existing) {
                Db::name('app_project')->where('id', $existing['id'])->update([
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
                    'name'            => $p['name'],
                    'subtitle'        => $p['subtitle'],
                    'cover'           => '/assets/img/project_default.png',
                    'category_l1'     => $p['category_l1'],
                    'category_l2'     => $p['category_l2'],
                    'category_l3'     => $p['category_l3'],
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
     * 生成 CMS 单页、专题与评论数据
     */
    protected function seedCmsData(array $users): array
    {
        $now = time();

        // 1. 单页测试数据
        $mockPages = [
            [
                'title'       => '关于 ITIK 生态平台',
                'diyname'     => 'fake_about',
                'content'     => '<h3>ITIK 社区信息交互平台</h3><p>ITIK 是面向 Web3 生态与前沿科技项目的去中心化商业交互枢纽，链接优质项目方、社区长与投资者。</p>',
                'description' => 'ITIK 社区平台定位与使命愿景',
            ],
            [
                'title'       => '用户服务与法律协议',
                'diyname'     => 'fake_terms',
                'content'     => '<h3>用户服务条款</h3><p>使用本平台服务前，请认真阅读本条款全部内容。用户使用钱包授权或邮箱注册即视为同意并遵守相关约定。</p>',
                'description' => '平台服务条款与法律规范',
            ],
            [
                'title'       => '隐私安全与数据保护政策',
                'diyname'     => 'fake_privacy',
                'content'     => '<h3>隐私与数据安全</h3><p>我们严格保护所有会员的链上隐私与账户安全，平台绝不保存任何用户的钱包私钥或助记词。</p>',
                'description' => '平台隐私保护与链上加密安全规范',
            ],
            [
                'title'       => 'Web3 钱包授权免责声明',
                'diyname'     => 'fake_disclaimer',
                'content'     => '<h3>去中心化钱包签名免责声明</h3><p>本平台签名质询（personal_sign）仅作为去中心化身份登录凭证，绝不会发起任何转账交易或资产授权授权请求。</p>',
                'description' => '去中心化钱包签名安全免责声明',
            ],
        ];

        $pageCount = 0;
        foreach ($mockPages as $pg) {
            $exist = Db::name('cms_page')->where('diyname', $pg['diyname'])->find();
            if (!$exist) {
                Db::name('cms_page')->insert([
                    'category_id' => 0,
                    'admin_id'    => 1,
                    'type'        => 'page',
                    'title'       => $pg['title'],
                    'seotitle'    => $pg['title'],
                    'keywords'    => 'ITIK,Web3,平台服务,协议',
                    'description' => $pg['description'],
                    'flag'        => 'recommend',
                    'image'       => '',
                    'content'     => $pg['content'],
                    'icon'        => 'fa fa-file-text-o',
                    'views'       => rand(200, 3000),
                    'likes'       => rand(10, 200),
                    'dislikes'    => 0,
                    'comments'    => rand(2, 20),
                    'diyname'     => $pg['diyname'],
                    'iscomment'   => 1,
                    'weigh'       => 50,
                    'status'      => 'normal',
                    'createtime'  => $now - rand(86400, 864000),
                    'updatetime'  => $now,
                ]);
                $pageCount++;
            }
        }

        // 2. 专题测试数据
        $mockSpecials = [
            [
                'title'       => '2026 Web3 优质去中心化生态项目精选展',
                'diyname'     => 'fake_web3_expo_2026',
                'label'       => '年度精选',
                'intro'       => '汇聚经过严格认证的优质 Web3 项目方与高活跃度社区，赋能下一代去中心化应用生态！',
            ],
            [
                'title'       => '从零开始：玩转去中心化钱包与 EIP 身份授权',
                'diyname'     => 'fake_wallet_guide',
                'label'       => '新手必读',
                'intro'       => '带你搞懂 MetaMask、OKX 钱包连接、personal_sign 签名原理及防诈骗全攻略。',
            ],
        ];

        $specialCount = 0;
        foreach ($mockSpecials as $sp) {
            $exist = Db::name('cms_special')->where('diyname', $sp['diyname'])->find();
            if (!$exist) {
                Db::name('cms_special')->insert([
                    'admin_id'    => 1,
                    'title'       => $sp['title'],
                    'tag_ids'     => '1,2,3',
                    'flag'        => 'hot,recommend',
                    'label'       => $sp['label'],
                    'image'       => '',
                    'banner'      => '',
                    'diyname'     => $sp['diyname'],
                    'seotitle'    => $sp['title'],
                    'keywords'    => 'Web3,专题,精选,去中心化',
                    'description' => $sp['intro'],
                    'intro'       => $sp['intro'],
                    'views'       => rand(1000, 5000),
                    'comments'    => rand(5, 30),
                    'iscomment'   => 1,
                    'status'      => 'normal',
                    'createtime'  => $now - rand(86400, 864000),
                    'updatetime'  => $now,
                ]);
                $specialCount++;
            }
        }

        // 3. 评论测试数据
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

        return [$pageCount, $specialCount, $commentCount];
    }
}
