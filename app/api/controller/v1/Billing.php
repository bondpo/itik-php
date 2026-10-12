<?php

// +----------------------------------------------------------------------
// | API v1 —— 订阅与订单
// +----------------------------------------------------------------------

namespace app\api\controller\v1;

use think\facade\Db;
use app\common\service\app\SubscriptionService;

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
 */
class Billing extends Base
{
    protected $noNeedLogin = ['notify'];
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
            $ret = $this->service->createOrder($this->userId(), $planCode, $idemKey, $coupon);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), self::CODE_BIZ_FAIL);
        }

        $this->success($ret['repeated'] ? '订单已存在' : '下单成功', [
            'order'        => $this->formatOrder($ret['order']),
            'subscription' => $ret['subscription'],
            'repeated'     => $ret['repeated'],
            'pay_hint'     => '支付渠道尚未开放，订单已创建并进入待支付状态',
        ]);
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
            $this->fail('关闭失败：' . $e->getMessage(), self::CODE_BIZ_FAIL);
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

        try {
            $ret = $this->service->createOrder($this->userId(), $planCode, $idemKey);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), self::CODE_BIZ_FAIL);
        }

        $this->success('续费订单已创建', [
            'order'    => $this->formatOrder($ret['order']),
            'repeated' => $ret['repeated'],
            'pay_hint' => '支付成功后有效期将在原到期时间基础上顺延',
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
}
