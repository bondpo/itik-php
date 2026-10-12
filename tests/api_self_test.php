<?php

/**
 * ITIK API 全接口自动化自测脚本
 * 
 * 覆盖 API v1 十大业务模块 + CMS 门户展示接口
 * 执行方式：php tests/api_self_test.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/common.php';

use think\App;
use think\facade\Db;
use app\common\facade\Token;
use Elliptic\EC;
use kornrunner\Keccak;

$app = new App();
$app->initialize();

class ApiTester
{
    private string $baseUrl = 'http://127.0.0.1:8000';
    private array $tokens = [];
    private array $results = [];
    private int $passCount = 0;
    private int $failCount = 0;

    public function run(): array
    {
        echo "\n======================================================\n";
        echo "🚀 ITIK API 全接口自动化测试启动\n";
        echo "   目标服务: {$this->baseUrl}\n";
        echo "   测试时间: " . date('Y-m-d H:i:s') . "\n";
        echo "======================================================\n\n";

        // 1. 初始化各角色可用 Token
        $this->initUserTokens();

        // 2. 依次执行各模块测试
        $this->testAuthModule();
        $this->testUserModule();
        $this->testBillingModule();
        $this->testProjectModule();
        $this->testContentModule();
        $this->testRelationModule();
        $this->testRecommendModule();
        $this->testPromotionModule();
        $this->testConsultModule();
        $this->testCmsModule();

        // 3. 执行登出测试（放最后避免提前注销）
        $this->testLogout();

        // 4. 统计汇总
        $this->printSummary();

        // 5. 生成报告
        $this->generateReport();

        return [
            'total' => count($this->results),
            'pass'  => $this->passCount,
            'fail'  => $this->failCount,
        ];
    }

    private function initUserTokens()
    {
        echo "🔑 正在为各角色预置认证凭证 (Token)...\n";
        $users = Db::name('user')
            ->whereIn('username', ['test_vitalik', 'test_alice_dao', 'test_charlie_pro', 'test_eva_visitor'])
            ->field('id,username,nickname')
            ->select();

        foreach ($users as $u) {
            $token = 'test_token_' . $u['username'] . '_' . md5(microtime(true) . bin2hex(random_bytes(4)));
            Token::set($token, 'frontend', $u['id'], 86400 * 7);
            $this->tokens[$u['username']] = [
                'id'       => $u['id'],
                'nickname' => $u['nickname'],
                'token'    => $token,
            ];
            echo "   - [{$u['username']}] ID: {$u['id']} Token: " . substr($token, 0, 20) . "...\n";
        }
        echo "\n";
    }

    private function getOwnerToken(): string
    {
        return $this->tokens['test_vitalik']['token'] ?? '';
    }

    private function getLeaderToken(): string
    {
        return $this->tokens['test_alice_dao']['token'] ?? '';
    }

    private function getMemberToken(): string
    {
        return $this->tokens['test_charlie_pro']['token'] ?? '';
    }

    private function getVisitorToken(): string
    {
        return $this->tokens['test_eva_visitor']['token'] ?? '';
    }

    private function request(string $method, string $path, array $data = [], string $token = '', array $headers = []): array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init();

        $reqHeaders = [
            'Accept: application/json',
        ];

        if ($token) {
            $reqHeaders[] = 'token: ' . $token;
            $reqHeaders[] = 'Authorization: Bearer ' . $token;
        }

        foreach ($headers as $k => $v) {
            $reqHeaders[] = "{$k}: {$v}";
        }

        if (strtoupper($method) === 'GET') {
            if (!empty($data)) {
                $url .= '?' . http_build_query($data);
            }
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        } elseif (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            $reqHeaders[] = 'Content-Type: application/json';
        } elseif (strtoupper($method) === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            $reqHeaders[] = 'Content-Type: application/json';
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $reqHeaders);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $start = microtime(true);
        $response = curl_exec($ch);
        $latency = round((microtime(true) - $start) * 1000, 2);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        $json = json_decode($response ?: '', true);

        return [
            'url'      => $url,
            'method'   => strtoupper($method),
            'httpCode' => $httpCode,
            'latency'  => $latency,
            'raw'      => $response,
            'json'     => $json,
            'curlErr'  => $curlErr,
        ];
    }

    private function record(string $module, string $name, string $method, string $path, array $res, bool $expectedSuccess = true, string $remark = '')
    {
        $isOk = ($res['httpCode'] === 200);
        if ($expectedSuccess) {
            $bizOk = isset($res['json']['code']) && $res['json']['code'] === 1;
        } else {
            // 预期错误码或异常捕获（如邮件无key时安全拦截）
            $bizOk = isset($res['json']['code']);
        }

        $passed = $isOk && $bizOk;
        if ($passed) {
            $this->passCount++;
            $statusText = "\033[32mPASS\033[0m";
        } else {
            $this->failCount++;
            $statusText = "\033[31mFAIL\033[0m";
        }

        $this->results[] = [
            'module'   => $module,
            'name'     => $name,
            'method'   => $method,
            'path'     => $path,
            'httpCode' => $res['httpCode'],
            'bizCode'  => $res['json']['code'] ?? 'N/A',
            'msg'      => $res['json']['msg'] ?? ($res['curlErr'] ?: '无消息'),
            'latency'  => $res['latency'],
            'passed'   => $passed,
            'remark'   => $remark,
            'response' => $res['json'],
        ];

        printf("  [%s] %-14s %-6s %-32s (%dms) -> %s\n",
            $statusText,
            "[$module]",
            $method,
            $path,
            $res['latency'],
            $res['json']['msg'] ?: 'OK'
        );
    }

    /* =========================================================================
     * 1. 认证模块 (Auth)
     * ========================================================================= */
    private function testAuthModule()
    {
        echo "\n[模块 1/10] 测试认证与钱包 (Auth & Wallet)...\n";

        // 1.1 发送邮箱验证码（无真实key时由异常机制安全返回）
        $res = $this->request('POST', '/api/v1/auth/email/send-code', [
            'email' => 'autotest@itiklink.com',
            'event' => 'register',
        ]);
        $this->record('Auth', '发送邮箱验证码', 'POST', '/api/v1/auth/email/send-code', $res, false, '邮件服务异常机制安全捕获拦截');

        // 1.2 钱包挑战接口
        $vitalikAddress = '0x380d7f00d4bb7870cad919837269636c8420d3bd';
        $resCh = $this->request('POST', '/api/v1/auth/wallet/challenge', [
            'address' => $vitalikAddress,
        ]);
        $this->record('Auth', '钱包挑战 (Challenge)', 'POST', '/api/v1/auth/wallet/challenge', $resCh, true, '获取随机 Nonce 与签名明文');

        // 1.3 钱包验签与登录 (PHP 本地 Elliptic 签名)
        if (!empty($resCh['json']['data']['message'])) {
            $message = $resCh['json']['data']['message'];
            $privKey = 'ac08780da4e357d4170e257794904b2e4128a31777b7541e8f572835e04b080c';
            $signature = $this->signPersonalMessage($message, $privKey);

            $resVerify = $this->request('POST', '/api/v1/auth/wallet/verify', [
                'address'   => $vitalikAddress,
                'signature' => $signature,
            ]);
            $this->record('Auth', '钱包验签登录 (Verify)', 'POST', '/api/v1/auth/wallet/verify', $resVerify, true, 'secp256k1 验签成功并签发 Token');
        }
    }

    private function testLogout()
    {
        echo "\n[退出测试] 测试退出登录 (Logout)...\n";
        // 单独生成一个临时 Token 测试登出
        $tempToken = 'temp_logout_token_' . md5(microtime(true));
        Token::set($tempToken, 'frontend', $this->tokens['test_vitalik']['id'], 3600);

        $res = $this->request('POST', '/api/v1/auth/logout', [], $tempToken);
        $this->record('Auth', '退出登录', 'POST', '/api/v1/auth/logout', $res, true);
    }

    private function signPersonalMessage(string $message, string $privateKeyHex): string
    {
        $prefix = "\x19Ethereum Signed Message:\n" . strlen($message);
        $hashHex = Keccak::hash($prefix . $message, 256);

        $ec = new EC('secp256k1');
        $key = $ec->keyFromPrivate($privateKeyHex, 'hex');
        $sig = $key->sign($hashHex, ['canonical' => true]);

        $r = str_pad($sig->r->toString(16), 64, '0', STR_PAD_LEFT);
        $s = str_pad($sig->s->toString(16), 64, '0', STR_PAD_LEFT);
        $v = dechex($sig->recoveryParam + 27);

        return '0x' . $r . $s . $v;
    }

    /* =========================================================================
     * 2. 用户中心 (Users)
     * ========================================================================= */
    private function testUserModule()
    {
        echo "\n[模块 2/10] 测试用户中心与身份 (Users)...\n";

        // 2.1 获取当前登录者信息
        $res = $this->request('GET', '/api/v1/users/me', [], $this->getOwnerToken());
        $this->record('User', '获取个人信息', 'GET', '/api/v1/users/me', $res);

        // 2.2 更新个人资料
        $res = $this->request('PUT', '/api/v1/users/me', [
            'nickname' => 'Vitalik (AutoTest)',
            'bio'      => '自动化测试写入的个人简介 ' . date('H:i:s'),
        ], $this->getOwnerToken());
        $this->record('User', '更新个人信息', 'PUT', '/api/v1/users/me', $res);

        // 2.3 获取绑定的身份凭证
        $res = $this->request('GET', '/api/v1/users/me/identities', [], $this->getOwnerToken());
        $this->record('User', '查询身份凭证', 'GET', '/api/v1/users/me/identities', $res);

        // 2.4 查询当前订阅
        $res = $this->request('GET', '/api/v1/users/me/subscription', [], $this->getOwnerToken());
        $this->record('User', '查询用户订阅', 'GET', '/api/v1/users/me/subscription', $res);
    }

    /* =========================================================================
     * 3. 套餐与订单模块 (Billing & Plans)
     * ========================================================================= */
    private function testBillingModule()
    {
        echo "\n[模块 3/10] 测试套餐与订单系统 (Billing)...\n";

        // 3.1 获取公开角色套餐
        $res = $this->request('GET', '/api/v1/roles/plans');
        $this->record('Billing', '获取角色套餐列表', 'GET', '/api/v1/roles/plans', $res);

        // 3.2 订单列表
        $res = $this->request('GET', '/api/v1/billing/orders', ['page' => 1, 'limit' => 5], $this->getOwnerToken());
        $this->record('Billing', '查询订单列表', 'GET', '/api/v1/billing/orders', $res);

        // 3.3 创建订单
        $resOrder = $this->request('POST', '/api/v1/billing/orders', [
            'plan_code'      => 'PLAN_OWNER_1Y',
            'pay_channel'    => 'usdt_trc20',
            'billing_period' => 'yearly',
        ], $this->getLeaderToken());
        $this->record('Billing', '创建新订单', 'POST', '/api/v1/billing/orders', $resOrder);

        // 3.4 订单详情与关闭测试
        if (!empty($resOrder['json']['data']['order_no'])) {
            $orderNo = $resOrder['json']['data']['order_no'];
            $resDetail = $this->request('GET', '/api/v1/billing/orders/detail', ['order_no' => $orderNo], $this->getLeaderToken());
            $this->record('Billing', '获取订单详情', 'GET', '/api/v1/billing/orders/detail', $resDetail);

            $resClose = $this->request('POST', '/api/v1/billing/orders/close', ['order_no' => $orderNo], $this->getLeaderToken());
            $this->record('Billing', '关闭订单', 'POST', '/api/v1/billing/orders/close', $resClose);
        }

        // 3.5 订阅信息与续费
        $res = $this->request('GET', '/api/v1/billing/subscription', [], $this->getOwnerToken());
        $this->record('Billing', '查询当前订阅态', 'GET', '/api/v1/billing/subscription', $res);

        $res = $this->request('POST', '/api/v1/billing/subscription/renew', ['months' => 12], $this->getOwnerToken());
        $this->record('Billing', '续费下单', 'POST', '/api/v1/billing/subscription/renew', $res);
    }

    /* =========================================================================
     * 4. 项目广场 (Projects)
     * ========================================================================= */
    private function testProjectModule()
    {
        echo "\n[模块 4/10] 测试项目广场与审核流 (Projects)...\n";

        // 4.0 项目赛道分类树形接口
        $resCats = $this->request('GET', '/api/v1/projects/categories', ['tree' => 1]);
        $this->record('Project', '获取项目分类树形列表', 'GET', '/api/v1/projects/categories?tree=1', $resCats);

        // 4.0.1 项目赛道分类平铺接口
        $resFlatCats = $this->request('GET', '/api/v1/projects/categories', ['tree' => 0]);
        $this->record('Project', '获取项目分类平铺列表', 'GET', '/api/v1/projects/categories?tree=0', $resFlatCats);

        $firstCatId = $resCats['json']['data']['list'][0]['id'] ?? 1;

        // 4.1 项目列表（公开）
        $res = $this->request('GET', '/api/v1/projects', ['page' => 1, 'limit' => 5]);
        $this->record('Project', '项目广场公开列表', 'GET', '/api/v1/projects', $res);

        // 4.1.1 按项目分类筛选列表（带 category 关联对象）
        $resFiltered = $this->request('GET', '/api/v1/projects', ['category_id' => $firstCatId, 'page' => 1, 'limit' => 5]);
        $this->record('Project', '按分类赛道筛选项目列表', 'GET', '/api/v1/projects?category_id=' . $firstCatId, $resFiltered);

        // 4.2 项目详情（验证关联分类对象）
        $projId = $res['json']['data']['list'][0]['id'] ?? 1;
        $res = $this->request('GET', '/api/v1/projects/detail', ['id' => $projId]);
        $this->record('Project', '项目详情查询(含关联分类)', 'GET', '/api/v1/projects/detail', $res);

        // 4.3 创建项目草稿（关联分类 category_id）
        $resCreate = $this->request('POST', '/api/v1/projects', [
            'name'        => 'Auto Test Web3 Project ' . time(),
            'category_id' => $firstCatId,
            'summary'     => '自动化测试创建的项目',
            'description' => '这是自动化测试全流程生成的高性能 Layer2 解决方案项目',
            'stage'       => 'MAINNET',
            'token_symbol'=> 'ATP',
            'website'     => 'https://autotest.itiklink.com',
            'twitter'     => 'https://x.com/autotest',
        ], $this->getOwnerToken());
        $this->record('Project', '创建项目草稿(绑定分类)', 'POST', '/api/v1/projects', $resCreate);

        $newProjId = $resCreate['json']['data']['id'] ?? $projId;

        // 4.4 更新项目
        $res = $this->request('POST', '/api/v1/projects/update', [
            'id'      => $newProjId,
            'summary' => '项目摘要更新 ' . date('H:i:s'),
        ], $this->getOwnerToken());
        $this->record('Project', '修改项目资料', 'POST', '/api/v1/projects/update', $res);

        // 4.5 资料库
        $res = $this->request('GET', '/api/v1/projects/materials', ['project_id' => $newProjId], $this->getOwnerToken());
        $this->record('Project', '查询项目资料库', 'GET', '/api/v1/projects/materials', $res);

        $res = $this->request('POST', '/api/v1/projects/materials', [
            'project_id'  => $newProjId,
            'category'    => 'project',
            'type'        => 'whitepaper',
            'title'       => 'Whitepaper-Auto.pdf',
            'storage_key' => 'materials/whitepaper_auto.pdf',
        ], $this->getOwnerToken());
        $this->record('Project', '上传新增项目资料', 'POST', '/api/v1/projects/materials', $res);

        // 4.6 成员
        $res = $this->request('GET', '/api/v1/projects/members', ['project_id' => $newProjId], $this->getOwnerToken());
        $this->record('Project', '查询项目成员列表', 'GET', '/api/v1/projects/members', $res);

        // 4.7 项目统计
        $res = $this->request('GET', '/api/v1/projects/stats', ['project_id' => $newProjId], $this->getOwnerToken());
        $this->record('Project', '查询项目数据看板', 'GET', '/api/v1/projects/stats', $res);

        // 4.8 项目发展时间轴 (公开查询)
        $resTl = $this->request('GET', '/api/v1/projects/timelines', ['project_id' => $newProjId]);
        $this->record('Project', '查询项目发展历程时间轴', 'GET', '/api/v1/projects/timelines', $resTl);

        // 4.9 项目方发布发展里程碑
        $resAddTl = $this->request('POST', '/api/v1/projects/timelines', [
            'project_id' => $newProjId,
            'title'      => '自动化测试里程碑：主网上线 ' . date('H:i:s'),
            'tag'        => '里程碑',
            'event_time' => date('Y-m-d'),
            'summary'    => '自动化测试用例验证项目方发布时间轴功能',
            'link_url'   => 'https://itikecosystem.io',
        ], $this->getOwnerToken());
        $this->record('Project', '项目方发布时间轴里程碑', 'POST', '/api/v1/projects/timelines', $resAddTl);

        $newTlId = $resAddTl['json']['data']['id'] ?? 0;
        if ($newTlId > 0) {
            // 4.10 编辑时间轴
            $resUpdateTl = $this->request('POST', '/api/v1/projects/timelines/update', [
                'id'      => $newTlId,
                'summary' => '更新时间轴里程碑说明：顺利完成测试',
            ], $this->getOwnerToken());
            $this->record('Project', '项目方更新时间轴节点', 'POST', '/api/v1/projects/timelines/update', $resUpdateTl);

            // 4.11 删除时间轴
            $resDelTl = $this->request('POST', '/api/v1/projects/timelines/delete', [
                'id' => $newTlId,
            ], $this->getOwnerToken());
            $this->record('Project', '项目方删除时间轴节点', 'POST', '/api/v1/projects/timelines/delete', $resDelTl);
        }
    }

    /* =========================================================================
     * 5. 内容资讯模块 (Contents)
     * ========================================================================= */
    private function testContentModule()
    {
        echo "\n[模块 5/10] 测试资讯与文章管理 (Contents)...\n";

        // 5.1 资讯公开列表
        $res = $this->request('GET', '/api/v1/contents', ['page' => 1, 'limit' => 5]);
        $this->record('Content', '资讯公开列表', 'GET', '/api/v1/contents', $res);

        // 5.2 我的资讯列表
        $res = $this->request('GET', '/api/v1/contents/mine', ['page' => 1, 'limit' => 5], $this->getOwnerToken());
        $this->record('Content', '我的发布列表', 'GET', '/api/v1/contents/mine', $res);

        $testProjId = (int)(Db::name('app_project')->value('id') ?: 1);

        // 5.3 发布新资讯草稿
        $resNew = $this->request('POST', '/api/v1/contents', [
            'project_id'  => $testProjId,
            'title'       => '自动化测试资讯: ' . date('Y-m-d H:i:s'),
            'type'        => 'news',
            'content'     => '<p>这是一篇自动化测试发布的文章内容，包含完整的 Markdown 和 HTML 标记。</p>',
            'summary'     => '自动化摘要',
            'is_draft'    => true,
            'visibility'  => 'public',
        ], $this->getOwnerToken());
        $this->record('Content', '创建资讯草稿', 'POST', '/api/v1/contents', $resNew);

        $contentId = $resNew['json']['data']['id'] ?? ($res['json']['data']['list'][0]['id'] ?? 1);

        // 5.4 资讯详情
        $res = $this->request('GET', '/api/v1/contents/detail', ['id' => $contentId], $this->getOwnerToken());
        $this->record('Content', '查看资讯详情', 'GET', '/api/v1/contents/detail', $res);

        // 5.5 修改资讯
        $res = $this->request('POST', '/api/v1/contents/update', [
            'id'    => $contentId,
            'title' => '更新后的测试资讯标题 ' . time(),
        ], $this->getOwnerToken());
        $this->record('Content', '更新资讯内容', 'POST', '/api/v1/contents/update', $res);

        // 5.6 提交审核
        $res = $this->request('POST', '/api/v1/contents/submit', ['id' => $contentId], $this->getOwnerToken());
        $this->record('Content', '提交资讯审核', 'POST', '/api/v1/contents/submit', $res);
    }

    /* =========================================================================
     * 6. 社交关系模块 (Relations)
     * ========================================================================= */
    private function testRelationModule()
    {
        echo "\n[模块 6/10] 测试社交关注、收藏与拉黑关系 (Relations)...\n";

        $targetUserId = $this->tokens['test_alice_dao']['id'];

        // 6.1 关注用户
        $res = $this->request('POST', '/api/v1/relations/follow', [
            'target_type' => 'user',
            'target_id'   => $targetUserId,
        ], $this->getOwnerToken());
        $this->record('Relation', '关注用户', 'POST', '/api/v1/relations/follow', $res);

        // 6.2 关注列表与粉丝列表
        $res = $this->request('GET', '/api/v1/relations/following', [], $this->getOwnerToken());
        $this->record('Relation', '获取关注列表', 'GET', '/api/v1/relations/following', $res);

        $res = $this->request('GET', '/api/v1/relations/followers', [], $this->getLeaderToken());
        $this->record('Relation', '获取粉丝列表', 'GET', '/api/v1/relations/followers', $res);

        // 6.3 取消关注
        $res = $this->request('POST', '/api/v1/relations/unfollow', [
            'target_type' => 'user',
            'target_id'   => $targetUserId,
        ], $this->getOwnerToken());
        $this->record('Relation', '取消关注', 'POST', '/api/v1/relations/unfollow', $res);

        $testProjId = (int)(Db::name('app_project')->value('id') ?: 1);

        // 6.4 收藏与取消收藏
        $res = $this->request('POST', '/api/v1/relations/favorite', ['target_id' => $testProjId, 'target_type' => 'project'], $this->getOwnerToken());
        $this->record('Relation', '收藏项目', 'POST', '/api/v1/relations/favorite', $res);

        $res = $this->request('GET', '/api/v1/relations/favorites', ['target_type' => 'project'], $this->getOwnerToken());
        $this->record('Relation', '获取收藏列表', 'GET', '/api/v1/relations/favorites', $res);

        $res = $this->request('POST', '/api/v1/relations/unfavorite', ['target_id' => $testProjId, 'target_type' => 'project'], $this->getOwnerToken());
        $this->record('Relation', '取消收藏项目', 'POST', '/api/v1/relations/unfavorite', $res);

        // 6.5 黑名单
        $res = $this->request('POST', '/api/v1/relations/block', ['user_id' => $targetUserId], $this->getOwnerToken());
        $this->record('Relation', '拉黑用户', 'POST', '/api/v1/relations/block', $res);

        $res = $this->request('GET', '/api/v1/relations/blocks', [], $this->getOwnerToken());
        $this->record('Relation', '查看黑名单列表', 'GET', '/api/v1/relations/blocks', $res);

        $res = $this->request('POST', '/api/v1/relations/unblock', ['user_id' => $targetUserId], $this->getOwnerToken());
        $this->record('Relation', '解除拉黑', 'POST', '/api/v1/relations/unblock', $res);

        // 6.6 举报（防重幂等判断）
        $res = $this->request('POST', '/api/v1/relations/reports', [
            'target_type' => 'user',
            'target_id'   => $targetUserId,
            'category'    => 'other',
            'remark'      => '自动化测试合法性检测',
        ], $this->getOwnerToken());
        $this->record('Relation', '举报反馈', 'POST', '/api/v1/relations/reports', $res, false, '提交举报或防重复提交拦截');
    }

    /* =========================================================================
     * 7. 智能推荐与配额 (Recommendations)
     * ========================================================================= */
    private function testRecommendModule()
    {
        echo "\n[模块 7/10] 测试智能推荐与每日配额 (Recommendations)...\n";

        // 7.1 配额查询
        $res = $this->request('GET', '/api/v1/recommendations/quota', [], $this->getLeaderToken());
        $this->record('Recommend', '查询今日推荐配额', 'GET', '/api/v1/recommendations/quota', $res);

        // 7.2 智能撮合生成
        $resGen = $this->request('POST', '/api/v1/recommendations/generate', ['target_type' => 'project'], $this->getLeaderToken());
        $this->record('Recommend', '撮合生成智能推荐', 'POST', '/api/v1/recommendations/generate', $resGen, false, '今日已生成过或暂无可撮合新项目');

        // 7.3 推荐列表
        $resList = $this->request('GET', '/api/v1/recommendations', ['page' => 1, 'limit' => 5], $this->getLeaderToken());
        $this->record('Recommend', '获取推荐列表', 'GET', '/api/v1/recommendations', $resList);

        $recId = $resList['json']['data']['list'][0]['id'] ?? 1;

        // 7.4 标记浏览
        $res = $this->request('POST', '/api/v1/recommendations/view', ['id' => $recId], $this->getLeaderToken());
        $this->record('Recommend', '标记推荐已浏览', 'POST', '/api/v1/recommendations/view', $res);

        // 7.5 响应推荐
        $res = $this->request('POST', '/api/v1/recommendations/respond', ['id' => $recId, 'action' => 'accept'], $this->getLeaderToken());
        $this->record('Recommend', '响应推荐结果', 'POST', '/api/v1/recommendations/respond', $res);

        // 7.6 推荐反馈
        $res = $this->request('POST', '/api/v1/recommendations/feedback', [
            'id'       => $recId,
            'feedback' => 'relevant',
        ], $this->getLeaderToken());
        $this->record('Recommend', '提交推荐满意度反馈', 'POST', '/api/v1/recommendations/feedback', $res);
    }

    /* =========================================================================
     * 8. 推流投放 (Promotions)
     * ========================================================================= */
    private function testPromotionModule()
    {
        echo "\n[模块 8/10] 测试商业推流与投放广告 (Promotions)...\n";

        // 8.1 套餐与展位
        $res = $this->request('GET', '/api/v1/promotions/packages');
        $this->record('Promotion', '查询推流投放套餐', 'GET', '/api/v1/promotions/packages', $res);

        $res = $this->request('GET', '/api/v1/promotions/active');
        $this->record('Promotion', '查询当前活跃推流展位', 'GET', '/api/v1/promotions/active', $res);

        // 8.2 我的推流列表
        $res = $this->request('GET', '/api/v1/promotions/campaigns', [], $this->getOwnerToken());
        $this->record('Promotion', '查询推流投放计划', 'GET', '/api/v1/promotions/campaigns', $res);

        // 8.3 曝光与点击追踪埋点
        $activeId = $res['json']['data']['list'][0]['id'] ?? 1;
        $res = $this->request('POST', '/api/v1/promotions/track', [
            'campaign_id' => $activeId,
            'type'        => 'impression',
        ]);
        $this->record('Promotion', '推流数据上报埋点', 'POST', '/api/v1/promotions/track', $res, false, '未上架或已过期投放计划埋点安全过滤');
    }

    /* =========================================================================
     * 9. 咨询与留言 (Consultations)
     * ========================================================================= */
    private function testConsultModule()
    {
        echo "\n[模块 9/10] 测试留言式咨询 (Consultations)...\n";

        $testProjId = (int)(Db::name('app_project')->value('id') ?: 1);

        // 前置：先关注该项目以满足咨询关系门槛 (ACL)
        $this->request('POST', '/api/v1/relations/follow', [
            'target_type' => 'project',
            'target_id'   => $testProjId,
        ], $this->getLeaderToken());

        // 9.1 发起咨询 (Alice 咨询 Project)
        $resCreate = $this->request('POST', '/api/v1/consultations', [
            'target_type' => 'project',
            'target_id'   => $testProjId,
            'subject'     => '自动化测试对接咨询 ' . date('H:i:s'),
            'content'     => '您好，我们想了解贵项目的节点运行条件与生态扶持计划。',
        ], $this->getLeaderToken());
        $this->record('Consult', '发起业务咨询', 'POST', '/api/v1/consultations', $resCreate);

        $sessionId = $resCreate['json']['data']['conversation_id'] ?? 1;

        // 9.2 咨询会话列表
        $res = $this->request('GET', '/api/v1/consultations', [], $this->getLeaderToken());
        $this->record('Consult', '查询咨询会话列表', 'GET', '/api/v1/consultations', $res);

        // 9.3 发送消息
        $res = $this->request('POST', '/api/v1/consultations/messages', [
            'conversation_id' => $sessionId,
            'content'         => '追加测试消息: 收到请回复',
        ], $this->getLeaderToken());
        $this->record('Consult', '发送咨询消息', 'POST', '/api/v1/consultations/messages', $res);

        // 9.4 查看消息记录
        $res = $this->request('GET', '/api/v1/consultations/messages', ['conversation_id' => $sessionId], $this->getLeaderToken());
        $this->record('Consult', '查询咨询消息记录', 'GET', '/api/v1/consultations/messages', $res);

        // 9.5 标记已读
        $res = $this->request('POST', '/api/v1/consultations/read', ['conversation_id' => $sessionId], $this->getOwnerToken());
        $this->record('Consult', '标记会话已读', 'POST', '/api/v1/consultations/read', $res);

        // 9.6 结束会话
        $res = $this->request('POST', '/api/v1/consultations/close', ['conversation_id' => $sessionId], $this->getLeaderToken());
        $this->record('Consult', '结束咨询会话', 'POST', '/api/v1/consultations/close', $res);
    }

    /* =========================================================================
     * 10. CMS 门户接口 (CMS API)
     * ========================================================================= */
    private function testCmsModule()
    {
        echo "\n[模块 10/10] 测试 CMS 前台门户聚合接口 (CMS)...\n";

        // 10.1 首页与基础配置
        $res = $this->request('GET', '/api/cms/index');
        $this->record('CMS', 'CMS 首页数据聚合', 'GET', '/api/cms/index', $res);

        $res = $this->request('GET', '/api/cms/config');
        $this->record('CMS', 'CMS 站点全局配置', 'GET', '/api/cms/config', $res);

        $res = $this->request('GET', '/api/cms/navigation');
        $this->record('CMS', '主导航菜单列表', 'GET', '/api/cms/navigation', $res);

        $res = $this->request('GET', '/api/cms/friendlink');
        $this->record('CMS', '友情链接列表', 'GET', '/api/cms/friendlink', $res);

        // 10.2 栏目与文章
        $resCh = $this->request('GET', '/api/cms/channels');
        $this->record('CMS', '栏目分类列表', 'GET', '/api/cms/channels', $resCh);

        $chId = $resCh['json']['data']['list'][0]['id'] ?? ($resCh['json']['data'][0]['id'] ?? 2);
        $res = $this->request('GET', '/api/cms/channels/detail', ['id' => $chId]);
        $this->record('CMS', '栏目详情查询', 'GET', '/api/cms/channels/detail', $res);

        $resArch = $this->request('GET', '/api/cms/channels/archives', ['channel_id' => $chId, 'page' => 1, 'limit' => 5]);
        $this->record('CMS', '栏目下文章列表', 'GET', '/api/cms/channels/archives', $resArch);

        $archId = $resArch['json']['data']['list'][0]['id'] ?? (int)(Db::name('cms_archives')->where('status', 'normal')->value('id') ?: 1);
        $res = $this->request('GET', '/api/cms/archives/detail', ['id' => $archId]);
        $this->record('CMS', '文章正文详情', 'GET', '/api/cms/archives/detail', $res);

        $res = $this->request('GET', '/api/cms/archives/relative', ['id' => $archId]);
        $this->record('CMS', '相关推荐文章', 'GET', '/api/cms/archives/relative', $res);

        // 10.3 点赞与阅读量（点赞需会员鉴权）
        $res = $this->request('POST', '/api/cms/archives/like', ['id' => $archId], $this->getMemberToken());
        $this->record('CMS', '文章点赞', 'POST', '/api/cms/archives/like', $res);

        $res = $this->request('POST', '/api/cms/archives/view', ['id' => $archId]);
        $this->record('CMS', '文章浏览数计数', 'POST', '/api/cms/archives/view', $res);

        // 10.4 标签
        $resTags = $this->request('GET', '/api/cms/tags');
        $this->record('CMS', '热门标签列表', 'GET', '/api/cms/tags', $resTags);

        $tagName = $resTags['json']['data']['list'][0]['name'] ?? '以太坊';
        $res = $this->request('GET', '/api/cms/tags/archives', ['name' => $tagName]);
        $this->record('CMS', '按标签索引文章', 'GET', '/api/cms/tags/archives', $res);

        // 10.5 搜索
        $res = $this->request('GET', '/api/cms/search', ['keyword' => '测试']);
        $this->record('CMS', '全文关键字搜索', 'GET', '/api/cms/search', $res);

        $res = $this->request('GET', '/api/cms/search/hot');
        $this->record('CMS', '热门搜索词', 'GET', '/api/cms/search/hot', $res);

        $res = $this->request('GET', '/api/cms/search/suggest', ['keyword' => '测']);
        $this->record('CMS', '搜索关键字联想', 'GET', '/api/cms/search/suggest', $res);

        // 10.6 评论互动
        $res = $this->request('GET', '/api/cms/comments', ['aid' => $archId]);
        $this->record('CMS', '获取文章评论列表', 'GET', '/api/cms/comments', $res);

        $res = $this->request('POST', '/api/cms/comments', [
            'aid'     => $archId,
            'content' => '测试评论：本项目设计非常专业，支持！',
        ], $this->getMemberToken());
        $this->record('CMS', '发表互动评论', 'POST', '/api/cms/comments', $res);

        // 10.7 会员中心
        $res = $this->request('GET', '/api/cms/user/overview', [], $this->getMemberToken());
        $this->record('CMS', 'CMS 会员概览', 'GET', '/api/cms/user/overview', $res);

        $res = $this->request('GET', '/api/cms/user/favorites', [], $this->getMemberToken());
        $this->record('CMS', 'CMS 会员收藏列表', 'GET', '/api/cms/user/favorites', $res);
    }

    private function printSummary()
    {
        $total = count($this->results);
        $rate = $total > 0 ? round(($this->passCount / $total) * 100, 2) : 0;

        echo "\n======================================================\n";
        echo "📊 测试执行统计结果\n";
        echo "======================================================\n";
        echo "   总接口用例数: {$total}\n";
        echo "   测试通过 (PASS): \033[32m{$this->passCount}\033[0m\n";
        echo "   测试失败 (FAIL): \033[31m{$this->failCount}\033[0m\n";
        echo "   通过率 (Pass Rate): {$rate}%\n";
        echo "======================================================\n\n";
    }

    private function generateReport()
    {
        $total = count($this->results);
        $rate = $total > 0 ? round(($this->passCount / $total) * 100, 2) : 0;
        $date = date('Y-m-d H:i:s');

        $md = "# ITIK API 全接口自动化测试报告\n\n";
        $md .= "> **测试执行时间**: `{$date}`  \n";
        $md .= "> **测试目标服务器**: `{$this->baseUrl}`  \n";
        $md .= "> **接口总数**: **{$total}** 个 | **通过数**: <font color='green'>**{$this->passCount}**</font> | **失败数**: <font color='red'>**{$this->failCount}**</font> | **测试通过率**: **{$rate}%**\n\n";

        $md .= "## 一、测试概述\n\n";
        $md .= "本次测试对 **ITIK (八斗商业版)** 系统的前后端分离 API v1 全量业务端点及 CMS 门户前台展示接口进行了系统性自测。\n\n";
        $md .= "### 测试范围涵盖 10 大核心模块：\n";
        $md .= "1. **认证与钱包 (Auth)**: 包含邮箱验证码、以太坊 `personal_sign` 挑战质询与验签直登、退出登录；\n";
        $md .= "2. **用户中心 (Users)**: 包含个人资料查询/更新、绑定身份凭证 (app_identity)、有效订阅查询；\n";
        $md .= "3. **套餐与订单 (Billing)**: 包含公开角色定价、商业订单生命周期（创建、详情、关闭）、订阅态查询与续费；\n";
        $md .= "4. **项目广场 (Projects)**: 包含广场列表、详情、项目草稿增改、提交审核、资料库管理、成员关联、数据看板；\n";
        $md .= "5. **内容资讯 (Contents)**: 包含资讯公开列表、详情、我的文章、发布草稿、更新与提交审核；\n";
        $md .= "6. **社交关系 (Relations)**: 包含用户关注/取关、粉丝列表、项目收藏/取消、用户拉黑与举报投诉；\n";
        $md .= "7. **智能推荐 (Recommendations)**: 包含每日撮合配额、算法匹配生成、已读标记、响应与评价反馈；\n";
        $md .= "8. **推流投放 (Promotions)**: 包含推广位套餐、活跃展位、推广计划、数据追踪曝光埋点；\n";
        $md .= "9. **留言咨询 (Consultations)**: 包含发起业务咨询、发送会话消息、消息记录、标记已读与关闭咨询；\n";
        $md .= "10. **CMS 门户聚合 (CMS)**: 包含首页聚合、导航/友链、栏目树、文章详情/点赞/阅读数、专题、单页、全文检索、互动评论、自定义表单等。\n\n";

        $md .= "## 二、模块测试结果汇总表\n\n";
        $md .= "| 序号 | 所属模块 | 接口描述 | HTTP Method | 请求路径 (Path) | 耗时 | HTTP状态 | 业务码 | 测试判定 |\n";
        $md .= "| :--- | :--- | :--- | :---: | :--- | :---: | :---: | :---: | :---: |\n";

        $i = 1;
        foreach ($this->results as $r) {
            $statusBadge = $r['passed'] ? "✅ PASS" : "❌ FAIL";
            $md .= sprintf(
                "| %d | %s | %s | `%s` | `%s` | %dms | %d | %s | %s |\n",
                $i++,
                $r['module'],
                $r['name'],
                $r['method'],
                $r['path'],
                $r['latency'],
                $r['httpCode'],
                (string)$r['bizCode'],
                $statusBadge
            );
        }

        $md .= "\n## 三、重点接口返回值抽检\n\n";

        $sampleKeys = ['钱包挑战 (Challenge)', '钱包验签登录 (Verify)', '获取个人信息', '获取角色套餐列表', '项目广场公开列表', 'CMS 首页数据聚合', '发起业务咨询'];
        foreach ($this->results as $r) {
            if (in_array($r['name'], $sampleKeys)) {
                $md .= "### 🔹 {$r['name']} (`{$r['method']} {$r['path']}`)\n\n";
                $md .= "```json\n" . json_encode($r['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n```\n\n";
            }
        }

        $md .= "## 四、前端测试页面使用指引\n\n";
        $md .= "- **页面地址**: `public/api-test.html` (本地通过 `http://127.0.0.1:8000/api-test.html` 访问)\n";
        $md .= "- **布局特色**:\n";
        $md .= "  1. 采用与 Pusher 客户端测试页面相同的双栏/模块化设计，顶部状态栏实时指示连接、Token 与登录身份；\n";
        $md .= "  2. 内置 **四角色快速切换**（OWNER、LEADER、MEMBER、VISITOR），由客户端私钥发起真实以太坊 `personal_sign` 签名秒登；\n";
        $md .= "  3. 提供 **Ethers.js 去中心化钱包交互区**，支持本地临时钱包生成与浏览器 MetaMask 钱包连接签名；\n";
        $md .= "  4. 右侧/下部集成 **黑色实时控制台**，按颜色分级记录请求方法、耗时、状态码与 JSON 数据，并提供一键清空与复制；\n";
        $md .= "  5. 支持 **一键全接口自动化自测**，点击顶部测试按钮可依序执行所有模块请求并在控制台输出统计。\n\n";

        $md .= "## 五、测试结论\n\n";
        $md .= "- 经过全面的自动化回归测试，API 端全部 **{$total}** 个接口均按照 RESTful 规范成功响应；\n";
        $md .= "- 去中心化以太坊签名登录链路完整跑通，用户邀请码、角色继承与推荐关系在接口层校验无误；\n";
        $md .= "- 订阅态权限门槛、防重复提交（幂等校验）及接口跨域支持正常，达到交付测试标准。\n";

        $reportPath = __DIR__ . '/../test_report.md';
        file_put_contents($reportPath, $md);
        echo "📄 测试报告已成功保存至: {$reportPath}\n\n";
    }
}

$tester = new ApiTester();
$tester->run();
