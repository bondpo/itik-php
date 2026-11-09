<?php

// +----------------------------------------------------------------------
// | API v1 —— 订阅与订单
// +----------------------------------------------------------------------

namespace app\api\controller\v1;

use app\common\library\Ems;
use think\facade\Db;
use think\facade\Cache;
use app\common\service\app\SubscriptionService;
use app\common\service\TpPayService;
use app\common\model\User as UserModel;
use app\common\model\MoneyLog;

/**
 * 订阅与订单接口
 *
 * 路由：
 *   GET  /api/v1/billing/orders              我的订单列表
 *   POST /api/v1/billing/orders              创建订单（需 Idempotency-Key）
 *   GET  /api/v1/billing/orders/{no}         订单详情
 *   POST /api/v1/billing/orders/{no}/close   关闭未支付订单
 *   GET  /api/v1/billing/subscription        当前订阅
 *   POST /api/v1/billing/subscription/renew  续费（复用 createOrder）
 *   POST /api/v1/billing/pay/notify          支付回调（服务端验签，公开）
 *   POST /api/v1/billing/pay/tp-init         生成 TP 转账 DeepLink（需登录）
 *   POST /api/v1/billing/pay/tp-callback     TP 转账结果回调（公开，验链）
 *   GET|POST /api/v1/billing/transfer/check  检查收款人（基于 invite_code）
 *   POST /api/v1/billing/transfer            用户间转账（防重、整数金额、邮箱验证码、基于 invite_code）
 */
class Billing extends Base
{
    protected $noNeedLogin = ['notify', 'tpCallback'];
    protected $noNeedRight = '*';

    /** @var SubscriptionService */
    protected $service;

    public function initialize()
    {
        parent::initialize();
        $this->service = new SubscriptionService();
    }

    /**
     * 我的订单列表
     */
    public function orders()
    {
        [$page, $limit] = $this->pageParams();
        [$orderby, $orderway] = $this->orderParams(['id', 'create_time', 'paid_at', 'amount'], 'id');

        $query = Db::name('app_order')->where('user_id', $this->userId());
        $status = (string)$this->request->param('status', '');
        if ($status !== '') {
            $query->where('status', $status);
        }

        $total = (clone $query)->count();
        $list = $query->order($orderby, $orderway)
            ->page($page, $limit)
            ->field('id,order_no,plan_code,amount,currency,status,pay_type,paid_at,expire_at,create_time')
            ->select();

        $this->success('', $this->pageResult($list, $total, $page, $limit));
    }

    /**
     * 创建订单
     * 幂等：读取 Idempotency-Key 请求头；未提供时按 userId+planCode+时间片生成。
     */
    public function createOrder()
    {
        $planCode = (string)$this->request->post('plan_code', '');
        $coupon   = (string)$this->request->post('coupon_code', '');
        $idemKey  = (string)($this->request->header('Idempotency-Key') ?: $this->request->post('idempotent_key', ''));
        $usd   = (string)$this->request->post('usd', 0);
        $blc   = (string)$this->request->post('blc', 0);

        if ($planCode === '') {
            $this->fail('请选择套餐', self::CODE_PARAM_MISSING);
        }
        if ($idemKey === '') {
            $idemKey = 'auto-' . $this->userId() . '-' . $planCode . '-' . substr((string)microtime(true), -4) . mt_rand(100, 999);
        }
        if (mb_strlen($idemKey) > 64) {
            $this->fail('Idempotency-Key 过长（最多 64 字符）', self::CODE_PARAM_INVALID);
        }

        try {
            $ret = $this->service->createOrder($this->userId(), $planCode, $idemKey, $coupon, $usd, $blc);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), self::CODE_BIZ_FAIL);
        }

        $payHint = '订单已创建';
        $tpPay = null;
        $orderUsd = (float)($ret['order']['usd'] ?? 0);
        $orderStatus = (string)($ret['order']['status'] ?? '');
        if ($orderUsd > 0 && $orderStatus === SubscriptionService::ORDER_PENDING) {
            try {
                $tpPay = (new TpPayService())->createPaySession($this->userId(), (string)$ret['order']['order_no']);
                $payHint = '请打开 TokenPocket 完成 USDT 支付，支付结果将自动回调确认';
            } catch (\Throwable $eTp) {
                $payHint = '订单已创建，请调用 /billing/pay/tp-init 拉起支付：' . $eTp->getMessage();
            }
        } elseif ($orderUsd <= 0 && $orderStatus === SubscriptionService::ORDER_PENDING) {
            try {
                $paid = $this->service->paySuccess((string)$ret['order']['order_no'], 'BLC', 'blc-' . $ret['order']['order_no'], ['source' => 'create-blc']);
                $ret['order'] = $paid['order'];
                $ret['subscription'] = $paid['subscription'];
                $payHint = '余额已扣，订单已确认';
            } catch (\Throwable $eBlc) {
                $payHint = '余额已扣减，确认失败：' . $eBlc->getMessage();
            }
        }

        $this->success($ret['repeated'] ? '订单已存在' : '下单成功', [
            'order'        => $this->formatOrder($ret['order']),
            'subscription' => $ret['subscription'],
            'repeated'     => $ret['repeated'],
            'pay_hint'     => $payHint,
            'tp_pay'       => $tpPay,
        ]);
    }

    /**
     * 生成 TokenPocket USDT 转账 DeepLink
     */
    public function tpInit()
    {
        $orderNo = (string)$this->request->post('order_no', '');
        if ($orderNo === '') {
            $this->fail('缺少 order_no', self::CODE_PARAM_MISSING);
        }
        try {
            $data = (new TpPayService())->createPaySession($this->userId(), $orderNo);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), self::CODE_BIZ_FAIL);
        }
        $this->success('ok', $data);
    }

    /**
     * TokenPocket transfer 回调（钱包 POST，无登录）
     */
    public function tpCallback()
    {
        $raw = $this->request->post();
        if (!is_array($raw) || $raw === []) {
            $raw = $this->request->param();
        }
        $json = $this->request->getContent();
        if (is_string($json) && $json !== '' && ($json[0] === '{' || $json[0] === '[')) {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $raw = array_merge(is_array($raw) ? $raw : [], $decoded);
            }
        }
        if (!is_array($raw)) {
            $raw = [];
        }

        try {
            $ret = (new TpPayService())->handleCallback($raw);
        } catch (\Throwable $e) {
            \think\facade\Log::warning('[billing/tp-callback] ' . $e->getMessage(), ['raw' => $raw]);
            $this->fail($e->getMessage(), self::CODE_BIZ_FAIL);
        }

        if (empty($ret['ok'])) {
            $this->success((string)($ret['message'] ?? 'ignored'), $ret);
        }
        $this->success($ret['repeated'] ? '重复回调' : '支付已确认', $ret);
    }

    /**
     * 订单详情
     */
    public function orderDetail()
    {
        $no = (string)$this->request->param('order_no', '');
        $order = Db::name('app_order')->where('order_no', $no)->where('user_id', $this->userId())->find();
        if (!$order) {
            $this->fail('订单不存在', self::CODE_NOT_FOUND);
        }
        $this->success('', ['order' => $this->formatOrder($order)]);
    }

    /**
     * 关闭未支付订单
     */
    public function closeOrder()
    {
        $no = (string)$this->request->post('order_no', '');
        $order = Db::name('app_order')->where('order_no', $no)->where('user_id', $this->userId())->find();
        if (!$order) {
            $this->fail('订单不存在', self::CODE_NOT_FOUND);
        }
        if ($order['status'] !== SubscriptionService::ORDER_PENDING) {
            $this->fail('仅待支付订单可关闭', self::CODE_STATE_INVALID, ['status' => $order['status']]);
        }

        Db::startTrans();
        try {
            Db::name('app_order')->where('id', $order['id'])->update([
                'status'      => SubscriptionService::ORDER_CLOSED,
                'update_time' => time(),
            ]);
            Db::name('app_subscription')
                ->where('source_order_id', $order['id'])
                ->where('status', SubscriptionService::SUB_PENDING)
                ->update(['status' => SubscriptionService::SUB_CANCELLED, 'update_time' => time()]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            $this->fail($this->translations()->message('关闭失败：') . $e->getMessage(), self::CODE_BIZ_FAIL);
        }

        $this->success('订单已关闭');
    }

    /**
     * 当前订阅
     */
    public function subscription()
    {
        $now = time();
        $current = $this->subscription;
        $history = Db::name('app_subscription')
            ->where('user_id', $this->userId())
            ->order('id', 'desc')
            ->limit(20)
            ->field('id,plan_code,role_code,status,start_at,end_at,grace_end_at,create_time')
            ->select();

        $this->success('', [
            'current'  => $current,
            'status'   => $this->subscriptionStatus(),
            'active'   => $this->subscriptionActive(),
            'role'     => $this->role,
            'remain_days' => ($current && (int)$current['end_at'] > $now)
                ? (int)ceil(((int)$current['end_at'] - $now) / 86400)
                : 0,
            'history'  => $history,
        ]);
    }

    /**
     * 续费（本质是再下一单；支付成功后同套餐顺延）
     */
    public function renew()
    {
        $planCode = (string)$this->request->post('plan_code', '');
        if ($planCode === '' && $this->subscription) {
            $planCode = (string)$this->subscription['plan_code'];
        }
        if ($planCode === '') {
            $this->fail('请选择套餐', self::CODE_PARAM_MISSING);
        }

        $idemKey = (string)($this->request->header('Idempotency-Key') ?: ('renew-' . $this->userId() . '-' . $planCode . '-' . substr((string)microtime(true), -4)));
        $usd = (string)$this->request->post('usd', 0);
        $blc = (string)$this->request->post('blc', 0);

        try {
            $ret = $this->service->createOrder($this->userId(), $planCode, $idemKey, '', $usd, $blc);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), self::CODE_BIZ_FAIL);
        }

        $tpPay = null;
        $orderUsd = (float)($ret['order']['usd'] ?? 0);
        if ($orderUsd > 0 && (string)($ret['order']['status'] ?? '') === SubscriptionService::ORDER_PENDING) {
            try {
                $tpPay = (new TpPayService())->createPaySession($this->userId(), (string)$ret['order']['order_no']);
            } catch (\Throwable $eTp) {
            }
        }

        $this->success('续费订单已创建', [
            'order'    => $this->formatOrder($ret['order']),
            'repeated' => $ret['repeated'],
            'pay_hint' => '支付成功后有效期将在原到期时间基础上顺延',
            'tp_pay'   => $tpPay,
        ]);
    }

    /**
     * 支付回调（公开接口，由支付渠道服务端调用）
     *
     * 安全说明：
     * - 必须验签（当前为渠道未接入阶段的框架位，接入时在此实现签名校验）
     * - 幂等：同一订单重复回调不重复激活
     */
    public function notify()
    {
        $orderNo  = (string)$this->request->post('order_no', '');
        $payType  = (string)$this->request->post('pay_type', '');
        $tradeNo  = (string)$this->request->post('trade_no', '');
        $sign     = (string)$this->request->post('sign', '');
        $payload  = $this->request->post();

        if ($orderNo === '') {
            $this->fail('缺少 order_no', self::CODE_PARAM_MISSING);
        }

        // === 渠道验签位（待支付渠道确认后在配置中开启） ===
        $verifyEnabled = (bool)Db::name('config')->where('group', 'app')->where('name', 'pay_verify_sign')->value('value');
        if ($verifyEnabled) {
            $secret = (string)Db::name('config')->where('group', 'app')->where('name', 'pay_sign_secret')->value('value');
            if ($secret === '' || $sign === '') {
                $this->fail('验签参数缺失', self::CODE_FORBIDDEN);
            }
            $expect = md5($orderNo . $payType . $tradeNo . $secret);
            if (!hash_equals($expect, $sign)) {
                $this->fail('签名校验失败', self::CODE_FORBIDDEN);
            }
        } else {
            // 未开启验签时，仅允许本地/调试环境触发，避免被伪造回调
            if (!app()->isDebug()) {
                $this->fail('支付渠道未接入，回调已拒绝', self::CODE_FORBIDDEN);
            }
        }

        try {
            $ret = $this->service->paySuccess($orderNo, $payType, $tradeNo, (array)$payload);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), self::CODE_BIZ_FAIL);
        }

        $this->success($ret['repeated'] ? '重复通知，已忽略' : '支付成功', [
            'order'        => $this->formatOrder($ret['order']),
            'subscription' => $ret['subscription'],
        ]);
    }

    /**
     * 订单输出格式化（金额转 float，便于前端消费）
     */
    protected function formatOrder(array $order): array
    {
        if (!$order) {
            return [];
        }
        $order['amount']   = (float)$order['amount'];
        $order['discount'] = (float)($order['discount'] ?? 0);
        return $order;
    }

    public function wallet(){
        $this->success('获取成功', get_sys_config('wallet'));
    }
    public function withdraw()
    {
        // 金额
        $money = $this->request->post('amount');
        $code = $this->request->post('code');
        if ($money <= 0) {
            $this->fail('提现金额不正确');
        }
        if ($money > $this->auth->money) {
            $this->error($this->translations()->formatMessage('余额不足无法提现{money}', ['money' => $this->auth->money]));
        }
        if (!$this->auth->wallet_address) {
            $this->error("请先绑定钱包地址");
        }
        if (!$this->auth->email || !filter_var($this->auth->email, FILTER_VALIDATE_EMAIL)) {
            $this->error('邮箱格式错误');
        }
        if (!$code) {
            $this->error('请输入邮箱验证码');
        }
        if (!Ems::check($this->auth->email, $code, 'withdraw')) {
            $this->error(Ems::getLastErrorMessage() ?: '验证码错误或已过期');
        }
        $minmoney = get_sys_config('minmoney');
        $multiple = get_sys_config('multiple');
        $daylimit = get_sys_config('daylimit');
        if ($minmoney > 0 && $money < $minmoney) {
            $this->error($this->translations()->formatMessage('提现金额不能低于{money}元', ['money' => $minmoney]));
        }
        if ($multiple > 0 && ($money % $multiple != 0)) {
            $this->error($this->translations()->formatMessage('提现金额必须是{multiple}的倍数', ['multiple' => $multiple]));
        }
        if ($daylimit > 0) {
            $count = \app\admin\model\UsdtTransfers::where('status', '<>','cancelled')->where('user_id', $this->auth->id)->whereTime('created_at', 'today')->count();
            if ($count >= $daylimit) {
                $this->error("已达到今日最大可提现次数");
            }
        }
        $this->serviceLock();
        $servicefee = get_sys_config('servicefee');
        if($this->auth->group_id != 9){
            $servicefee = get_sys_config('servicefee_vip');
            $freetimes = get_sys_config('freetimes');
            $sub = Db::name('app_subscription')->where('user_id', $this->auth->id)
                ->whereIn('status', ['ACTIVE', 'GRACE'])
                ->where('start_at', '<=', time())
                ->where('end_at', '>', time())
                ->order('end_at', 'desc')
                ->find();
            if($sub){
                $count = \app\admin\model\UsdtTransfers::where('status', '<>','cancelled')
                    ->where('user_id', $this->auth->id)
                    ->whereTime('created_at', 'between', [date("Y-m-d", $sub['start_at']), date("Y-m-d", strtotime("+1 day", $sub['end_at']))])
                    ->count();
                if($count < $freetimes) $servicefee = 0;
            }
        }
        if ($servicefee > 0) {
            $fee = round($money * $servicefee, 2);
            $handingmoney = $money - $servicefee;
        } else {
            $fee = 0;
            $handingmoney = $money;
        }
        Db::startTrans();
        try {
            $token_symbol = 'USDT';
            if($this->auth->money > 0){
                $before = $this->auth->money;
                $after = function_exists('bcsub') ? bcsub($this->auth->money, $money, 2) : ($this->auth->money - $money);
                \app\admin\model\User::where('id', $this->auth->id)->setDec('money', $money);
                $order_no = date("Ymdhis") . sprintf("%08d", $this->auth->id) . mt_rand(1000, 9999);
                \app\common\model\MoneyLog::create([
                    'user_id' => $this->auth->id,
                    'money' => -$money,
                    'before' => $before,
                    'after' => $after,
                    'type' => 'withdraw',
                    'memo' => '提现',
                    'order_no' => $order_no,
                ]);
                \app\admin\model\UsdtTransfers::create([
                    'order_no' => $order_no,
                    'user_id' => $this->auth->id,
                    'task_id' => 1,
                    'token_symbol' => $token_symbol,
                    'from_address' => '',
                    'to_address' => $this->auth->wallet_address,
                    'amount' => $handingmoney,
                    'handingfee' => $fee, // 手续费
                    'deduc' => 0,
                    'status' => 'wait'
                ]);
            }
            Db::commit();
        } catch (Exception $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }
        $this->success('申请成功！请等待后台审核', $this->auth->money - $money);
    }

    protected function serviceLock($time = 2,$processKey = ''){
        $redis = Cache::store('redis');
        !$processKey && $processKey = MD5($this->request->action().$this->auth->id);

        if($redis->has($processKey)){
            $this->error('请勿频繁操作');
        }
        $redis->set($processKey,1,$time);
    }

    /**
     * 检查收款人信息（转账前预检）
     * 路由：GET|POST /api/v1/billing/transfer/check
     */
    public function checkRecipient()
    {
        $senderId = $this->userId();
        if ($senderId <= 0) {
            $this->fail('请先登录', self::CODE_UNAUTHORIZED);
        }

        $inviteCode = strtoupper(trim((string)$this->request->param('invite_code', '')));
        if ($inviteCode === '') {
            $this->fail('请输入收款人邀请码', self::CODE_PARAM_MISSING);
        }

        $recipient = UserModel::where('invite_code', $inviteCode)->find();
        if (!$recipient) {
            $this->fail('收款人不存在，请核对邀请码', self::CODE_NOT_FOUND);
        }

        if ((int)$recipient->id === $senderId) {
            $this->fail('不能向自己转账', self::CODE_PARAM_INVALID);
        }

        if ((string)$recipient->status !== 'normal') {
            $this->fail('收款人账号状态异常，无法收款', self::CODE_STATE_INVALID);
        }

        $nickname = (string)($recipient->nickname ?: $recipient->username);
        $avatar = (string)($recipient->avatar ?: '');
        if ($avatar !== '' && function_exists('cdnurl')) {
            $avatar = cdnurl($avatar, true);
        }

        $this->success('查询成功', [
            'invite_code'     => $recipient->invite_code,
            'nickname_masked' => $this->maskNickname($nickname),
            'avatar'          => $avatar,
        ]);
    }

    /**
     * 用户余额转账
     * 路由：POST /api/v1/billing/transfer
     *
     * 规则：
     * 1. 防重复提交：分布式锁（3s）+ 幂等键（Idempotency-Key / idempotency_key，缓存 300s）
     * 2. 转账金额必须为正整数
     * 3. 转账对象基于 bd_user.invite_code
     * 4. 事务保证原子性，升序行级悲观锁避免死锁，资金日志写入 bd_user_money_log
     */
    public function transfer()
    {
        $senderId = $this->userId();
        if ($senderId <= 0) {
            $this->fail('请先登录', self::CODE_UNAUTHORIZED);
        }

        // 1. 幂等检查（若客户端重试携带同一幂等Key且已处理成功，则直接返回上次成功数据）
        $idemKey = trim((string)($this->request->header('Idempotency-Key') ?: $this->request->post('idempotency_key', '')));
        if ($idemKey !== '') {
            if (mb_strlen($idemKey) > 64) {
                $this->fail('Idempotency-Key 过长（最多 64 字符）', self::CODE_PARAM_INVALID);
            }
            $cached = Cache::get('idemp:transfer:' . $senderId . ':' . $idemKey);
            if ($cached && is_array($cached)) {
                $this->success('转账已完成（幂等返回）', $cached);
            }
        }

        // 2. 防重提交分布式锁（3秒防抖）
        if (!$this->acquireTransferLock($senderId, 3)) {
            $this->fail('操作过于频繁，请勿重复提交', self::CODE_RATE_LIMITED);
        }

        // 3. 参数校验
        $inviteCode = strtoupper(trim((string)$this->request->post('invite_code', '')));
        if ($inviteCode === '') {
            $this->releaseTransferLock($senderId);
            $this->fail('请输入收款人邀请码', self::CODE_PARAM_MISSING);
        }

        $rawAmount = $this->request->post('amount');
        if ($rawAmount === null || $rawAmount === '' || !preg_match('/^[1-9]\d*$/', (string)$rawAmount)) {
            $this->releaseTransferLock($senderId);
            $this->fail('转账金额必须是正整数', self::CODE_PARAM_INVALID);
        }
        $amount = (int)$rawAmount;
        if ($amount < 1) {
            $this->releaseTransferLock($senderId);
            $this->fail('转账金额最低为 1', self::CODE_PARAM_INVALID);
        }
        if ($amount > 100000000) {
            $this->releaseTransferLock($senderId);
            $this->fail('单笔转账金额超出上限', self::CODE_PARAM_INVALID);
        }

        $remark = trim((string)$this->request->post('remark', ''));
        if (mb_strlen($remark) > 50) {
            $this->releaseTransferLock($senderId);
            $this->fail('备注说明最多50字', self::CODE_PARAM_INVALID);
        }

        // 邮箱验证码校验（参考提现验证码规范）
        $code = trim((string)$this->request->post('code', ''));
        if ($code === '') {
            $this->releaseTransferLock($senderId);
            $this->fail('请输入邮箱验证码', self::CODE_PARAM_MISSING);
        }

        $senderPre = UserModel::where('id', $senderId)->find();
        if (!$senderPre) {
            $this->releaseTransferLock($senderId);
            $this->fail('付款人账号异常', self::CODE_NOT_FOUND);
        }

        $senderEmail = (string)($senderPre->email ?: ($this->auth->email ?? ''));
        if (!$senderEmail || !filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
            $this->releaseTransferLock($senderId);
            $this->fail('当前账号尚未绑定有效邮箱，请先前往绑定邮箱', self::CODE_STATE_INVALID);
        }

        // 验证验证码（优先 transfer 事件，兼容 withdraw 事件）
        $checkPassed = Ems::check($senderEmail, $code, 'transfer');
        $eventUsed = 'transfer';
        if (!$checkPassed && Ems::check($senderEmail, $code, 'withdraw')) {
            $checkPassed = true;
            $eventUsed = 'withdraw';
        }

        if (!$checkPassed) {
            $this->releaseTransferLock($senderId);
            $this->fail(Ems::getLastErrorMessage() ?: '验证码错误或已过期', self::CODE_PARAM_INVALID);
        }

        // 4. 收款人前置验证
        $receiverPre = UserModel::where('invite_code', $inviteCode)->find();
        if (!$receiverPre) {
            $this->releaseTransferLock($senderId);
            $this->fail('收款人不存在，请核对邀请码', self::CODE_NOT_FOUND);
        }
        if ((int)$receiverPre->id === $senderId) {
            $this->releaseTransferLock($senderId);
            $this->fail('不能向自己转账', self::CODE_PARAM_INVALID);
        }
        if ((string)$receiverPre->status !== 'normal') {
            $this->releaseTransferLock($senderId);
            $this->fail('收款人账号状态异常，无法收款', self::CODE_STATE_INVALID);
        }
        $receiverId = (int)$receiverPre->id;

        // 5. 付款人当前余额前置检查
        $senderPre = UserModel::where('id', $senderId)->find();
        if (!$senderPre || bccomp((string)$senderPre->money, (string)$amount, 2) < 0) {
            $this->releaseTransferLock($senderId);
            $this->fail('当前可用余额不足', self::CODE_BIZ_FAIL);
        }

        // 6. 事务内加锁执行划转
        $orderNo = 'TF' . date('YmdHis') . sprintf('%06d', $senderId) . mt_rand(1000, 9999);
        $resultData = null;

        Db::startTrans();
        try {
            // 严格按 ID 升序锁定双边行记录，避免相互转账时可能产生的死锁（Deadlock）
            $firstId = min($senderId, $receiverId);
            $secondId = max($senderId, $receiverId);

            $userA = UserModel::where('id', $firstId)->lock(true)->find();
            $userB = UserModel::where('id', $secondId)->lock(true)->find();

            $sender = ($firstId === $senderId) ? $userA : $userB;
            $receiver = ($firstId === $receiverId) ? $userA : $userB;

            if (!$sender || !$receiver) {
                throw new \Exception('转账用户数据读取失败，请稍后重试');
            }
            if ((string)$receiver->status !== 'normal') {
                throw new \Exception('收款人账号状态异常，转账终止');
            }

            $senderBefore = (string)$sender->money;
            if (bccomp($senderBefore, (string)$amount, 2) < 0) {
                throw new \Exception('可用余额不足');
            }

            // 计算变动后余额
            $senderAfter = bcsub($senderBefore, (string)$amount, 2);
            $receiverBefore = (string)$receiver->money;
            $receiverAfter = bcadd($receiverBefore, (string)$amount, 2);

            // 扣除付款人余额
            $sender->money = $senderAfter;
            $sender->save();

            // 增加收款人余额
            $receiver->money = $receiverAfter;
            $receiver->save();

            // 生成备注
            $senderName = (string)($sender->nickname ?: $sender->username);
            $receiverName = (string)($receiver->nickname ?: $receiver->username);

            $senderMemo = '转账支出给用户: ' . $receiverName . '(' . $receiver->invite_code . ')';
            if ($remark !== '') {
                $senderMemo .= ' [' . $remark . ']';
            }

            $receiverMemo = '收到转账: 来自用户 ' . $senderName . '(' . $sender->invite_code . ')';
            if ($remark !== '') {
                $receiverMemo .= ' [' . $remark . ']';
            }

            $nowTime = time();

            // 写入付款人资金变动日志
            MoneyLog::create([
                'user_id'    => $senderId,
                'money'      => -1 * $amount,
                'before'     => $senderBefore,
                'after'      => $senderAfter,
                'type'       => 'transfer_out',
                'memo'       => $senderMemo,
                'order_no'   => $orderNo,
                'createtime' => $nowTime,
            ]);

            // 写入收款人资金变动日志
            MoneyLog::create([
                'user_id'    => $receiverId,
                'money'      => $amount,
                'before'     => $receiverBefore,
                'after'      => $receiverAfter,
                'type'       => 'transfer_in',
                'memo'       => $receiverMemo,
                'order_no'   => $orderNo,
                'createtime' => $nowTime,
            ]);

            Db::commit();

            $resultData = [
                'order_no'          => $orderNo,
                'amount'            => $amount,
                'balance'           => $senderAfter,
                'receiver_invite'   => $receiver->invite_code,
                'receiver_nickname' => $this->maskNickname($receiverName),
                'transfer_time'     => date('Y-m-d H:i:s', $nowTime),
            ];
        } catch (\Throwable $e) {
            Db::rollback();
            $this->releaseTransferLock($senderId);
            $this->fail($e->getMessage(), self::CODE_BIZ_FAIL);
        }

        // 7. 写入幂等记录（有效期 5 分钟）
        if ($idemKey !== '' && $resultData !== null) {
            try {
                Cache::set('idemp:transfer:' . $senderId . ':' . $idemKey, $resultData, 300);
            } catch (\Throwable) {
            }
        }

        // 8. 成功后销毁该验证码，防止在有效期内被二次利用
        try {
            Ems::flush($senderEmail, $eventUsed);
        } catch (\Throwable) {
        }

        // 9. 成功响应
        $this->success('转账成功', $resultData);
    }

    /**
     * 获取转账防重提交分布式锁
     */
    protected function acquireTransferLock(int $userId, int $ttl = 3): bool
    {
        $lockKey = 'lock:transfer:user:' . $userId;
        try {
            $redis = Cache::store('redis')->handler();
            return (bool)$redis->set($lockKey, '1', ['nx', 'ex' => $ttl]);
        } catch (\Throwable $e) {
            if (Cache::has($lockKey)) {
                return false;
            }
            Cache::set($lockKey, 1, $ttl);
            return true;
        }
    }

    /**
     * 释放转账分布式锁
     */
    protected function releaseTransferLock(int $userId): void
    {
        $lockKey = 'lock:transfer:user:' . $userId;
        try {
            $redis = Cache::store('redis')->handler();
            $redis->del($lockKey);
        } catch (\Throwable $e) {
            Cache::delete($lockKey);
        }
    }

    /**
     * 昵称脱敏
     */
    protected function maskNickname(string $name): string
    {
        $len = mb_strlen($name, 'UTF-8');
        if ($len <= 1) {
            return $name . '*';
        }
        if ($len === 2) {
            return mb_substr($name, 0, 1, 'UTF-8') . '*';
        }
        return mb_substr($name, 0, 1, 'UTF-8') . str_repeat('*', min(4, $len - 2)) . mb_substr($name, -1, 1, 'UTF-8');
    }
}
