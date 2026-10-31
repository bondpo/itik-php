<?php

// +----------------------------------------------------------------------
// | 项目社区信息交互平台 —— 订阅服务层
// +----------------------------------------------------------------------
// | 职责：套餐开通 / 订单幂等 / 支付回调 / 订阅六态流转 / 到期扫描 / 角色升降级
// | 说明：所有状态流转均写审计日志；金额与币种一律取自套餐配置，不硬编码。
// +----------------------------------------------------------------------

namespace app\common\service\app;

use app\common\service\Ds;
use think\facade\Db;
use think\facade\Log;

class SubscriptionService
{
    /** 订阅六态 */
    public const SUB_PENDING   = 'PENDING_PAYMENT';
    public const SUB_ACTIVE    = 'ACTIVE';
    public const SUB_GRACE     = 'GRACE';
    public const SUB_EXPIRED   = 'EXPIRED';
    public const SUB_SUSPENDED = 'SUSPENDED';
    public const SUB_CANCELLED = 'CANCELLED';

    /** 订单状态 */
    public const ORDER_PENDING  = 'pending';
    public const ORDER_BROADCAST= 'broadcast';
    public const ORDER_PAID     = 'paid';
    public const ORDER_CLOSED   = 'closed';
    public const ORDER_REFUNDED = 'refunded';

    /**
     * 创建订单（幂等）
     *
     * 幂等语义：同一 idempotent_key 重复调用直接返回原订单，不重复建单。
     * 同时预建一条 PENDING_PAYMENT 订阅记录，便于前端立即展示「待支付」状态。
     *
     * @param int    $userId
     * @param string $planCode
     * @param string $idempotentKey 幂等键（建议前端生成 UUID）
     * @param string $couponCode
     * @return array{order:array,subscription:array,repeated:bool}
     * @throws \Exception
     */
    public function createOrder(int $userId, string $planCode, string $idempotentKey = '', string $couponCode = '', $usd, $blc): array
    {
        if ($userId <= 0) {
            throw new \Exception('用户无效');
        }
        if ($idempotentKey !== '') {
            $exists = Db::name('app_order')->where('idempotent_key', $idempotentKey)->find();
            if ($exists) {
                $sub = Db::name('app_subscription')->where('source_order_id', $exists['id'])->find();
                return ['order' => $exists, 'subscription' => $sub ?: [], 'repeated' => true];
            }
        }

        $plan = Db::name('app_plan')->where('plan_code', $planCode)->where('status', 'normal')->find();
        if (!$plan) {
            throw new \Exception('套餐不存在或已下架');
        }

        // 游客以外角色再购买同类套餐时，按续费处理（此处仅做提示，续费走 renew）
        $now = time();
        $orderNo = $this->buildOrderNo();

        // 优惠码
        $discount = 0.00;
        if ($couponCode !== '') {
            [$discount] = $this->calcDiscount($couponCode, (float)$plan['price'], (string)$plan['currency'], (string)$plan['role_code']);
        }else{
            if($plan['dis'] > 0 && time() >= $plan['start_time'] && time() < $plan['end_time']){
                $discount = (float)$plan['dis'];
            }
        }
        $usd = (float)$usd;
        $blc = (float)$blc;
        if (((float)$plan['price'] - (float)$discount) != ($usd + $blc) || $usd < 0 || $blc < 0) {
            throw new \Exception('支付金额异常');
        }
        $user = Db::name('user')->where('id', $userId)->find();
        if($blc > $user['money']){
            throw new \Exception('余额不足');
        }
        if($usd > 0) $pay_type = 'USDT';
        else $pay_type = 'BLC';
        Db::startTrans();
        try {
            if($blc > 0) {
                $before = $user['money'];
                $after = function_exists('bcsub') ? bcsub($user['money'], $blc, 2) : ($user['money'] - $blc);
                \app\admin\model\User::where('id', $userId)->setDec('money', $blc);
                \app\common\model\MoneyLog::create([
                    'user_id' => $userId,
                    'money' => -$blc,
                    'before' => $before,
                    'after' => $after,
                    'memo' => '订阅'
                ]);
            }
            $orderId = Db::name('app_order')->insertGetId([
                'order_no'       => $orderNo,
                'user_id'        => $userId,
                'plan_code'      => $planCode,
                'amount'         => $plan['price'],
                'currency'       => $plan['currency'],
                'status'         => self::ORDER_PENDING,
                'pay_type'       => $pay_type,
                'pay_trade_no'   => '',
                'idempotent_key' => $idempotentKey,
                'coupon_code'    => $couponCode,
                'discount'       => $discount,
                'usd'            => $usd,
                'blc'            => $blc,
                'paid_at'        => 0,
                'expire_at'      => $now + 3600, // 1 小时内未支付自动关闭
                'create_time'    => $now,
                'update_time'    => $now,
            ]);

            $subId = Db::name('app_subscription')->insertGetId([
                'user_id'         => $userId,
                'plan_code'       => $planCode,
                'role_code'       => $plan['role_code'],
                'status'          => self::SUB_PENDING,
                'start_at'        => 0,
                'end_at'          => 0,
                'grace_end_at'    => 0,
                'source_order_id' => $orderId,
                'create_time'     => $now,
                'update_time'     => $now,
            ]);

            $this->audit('user', $userId, 'order.create', 'order', $orderId, [], [
                'order_no'  => $orderNo,
                'plan_code' => $planCode,
                'amount'    => $plan['price'],
                'currency'  => $plan['currency'],
            ]);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw new \Exception('订单创建失败：' . $e->getMessage());
        }

        return [
            'order'        => Db::name('app_order')->where('id', $orderId)->find(),
            'subscription' => Db::name('app_subscription')->where('id', $subId)->find(),
            'repeated'     => false,
        ];
    }

    /**
     * 支付成功处理
     *
     * 说明：支付结果只以服务器回调或链上确认为准，前端不得直接改状态。
     * 幂等：已 paid 的订单重复回调直接返回当前订阅。
     *
     * @param string $orderNo
     * @param string $payType   支付渠道标识（fiat / usdt / ...）
     * @param string $tradeNo   第三方或链上交易号
     * @param array  $payload   回调原始报文（留证）
     * @return array
     * @throws \Exception
     */
    public function paySuccess(string $orderNo, string $payType = '', string $tradeNo = '', array $payload = []): array
    {
        $order = Db::name('app_order')->where('order_no', $orderNo)->find();
        if (!$order) {
            throw new \Exception('订单不存在');
        }
        if ($order['status'] === self::ORDER_PAID || $order['status'] === self::ORDER_BROADCAST) {
            $sub = Db::name('app_subscription')->where('source_order_id', $order['id'])->find();
            return ['order' => $order, 'subscription' => $sub ?: [], 'repeated' => true];
        }
        if ($order['status'] !== self::ORDER_PENDING) {
            throw new \Exception('订单状态异常：' . $order['status']);
        }

        $plan = Db::name('app_plan')->where('plan_code', $order['plan_code'])->find();
        if (!$plan) {
            throw new \Exception('套餐不存在');
        }

        $now = time();
        $duration = max(1, (int)$plan['duration_days']) * 86400;

        Db::startTrans();
        try {
            if($order['usd'] > 0) {
                // 1) 订单置为已支付
                Db::name('app_order')->where('id', $order['id'])->update([
                    'status' => self::ORDER_BROADCAST,
//                'pay_type'     => $payType,
                    'pay_trade_no' => $tradeNo,
                    'pay_payload' => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
                    'paid_at' => $now,
                    'update_time' => $now,
                ]);
            }else{
                Db::name('app_order')->where('id', $order['id'])->update([
                    'status' => self::ORDER_PAID,
//                'pay_type'     => $payType,
                    'pay_trade_no' => $tradeNo,
                    'pay_payload' => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
                    'paid_at' => $now,
                    'update_time' => $now,
                ]);
                $ds = new Ds();
                $ds->orderAct($order['id']);
            }

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw new \Exception('支付处理失败：' . $e->getMessage());
        }

        $sub = Db::name('app_subscription')->where('source_order_id', $order['id'])->find();
        return [
            'order'        => Db::name('app_order')->where('id', $order['id'])->find(),
            'subscription' => $sub ?: [],
            'repeated'     => false,
        ];
    }

    /**
     * 到期扫描：ACTIVE → GRACE → EXPIRED，并做角色降级
     *
     * @param int $graceDays 宽限期天数（0 = 不启用宽限，直接到期）
     * @return array{grace:int,expired:int,closed_orders:int}
     */
    public function syncExpired(int $graceDays = 0): array
    {
        $now = time();
        $grace = 0;
        $expired = 0;

        // 1) ACTIVE 且已过期 → GRACE / EXPIRED
        $list = Db::name('app_subscription')
            ->where('status', self::SUB_ACTIVE)
            ->where('end_at', '>', 0)
            ->where('end_at', '<=', $now)
            ->select();

        foreach ($list as $sub) {
            if ($graceDays > 0) {
                Db::name('app_subscription')->where('id', $sub['id'])->update([
                    'status'       => self::SUB_GRACE,
                    'grace_end_at' => $now + $graceDays * 86400,
                    'update_time'  => $now,
                ]);
                $this->audit('system', 0, 'subscription.grace', 'subscription', (int)$sub['id'],
                    ['status' => self::SUB_ACTIVE], ['status' => self::SUB_GRACE, 'grace_days' => $graceDays]);
                $grace++;
            } else {
                $this->expireOne($sub, $now);
                $expired++;
            }
        }

        // 2) GRACE 且宽限期已过 → EXPIRED
        $graceList = Db::name('app_subscription')
            ->where('status', self::SUB_GRACE)
            ->where('grace_end_at', '>', 0)
            ->where('grace_end_at', '<=', $now)
            ->select();
        foreach ($graceList as $sub) {
            $this->expireOne($sub, $now);
            $expired++;
        }

        // 3) 超时未支付订单自动关闭
        $closed = Db::name('app_order')
            ->where('status', self::ORDER_PENDING)
            ->where('expire_at', '>', 0)
            ->where('expire_at', '<=', $now)
            ->update(['status' => self::ORDER_CLOSED, 'update_time' => $now]);

        // 关联的 PENDING_PAYMENT 订阅同步取消
        Db::name('app_subscription')
            ->where('status', self::SUB_PENDING)
            ->where('create_time', '<=', $now - 86400)
            ->update(['status' => self::SUB_CANCELLED, 'update_time' => $now]);

        return ['grace' => $grace, 'expired' => $expired, 'closed_orders' => (int)$closed];
    }

    /**
     * 单条订阅到期处理：置 EXPIRED 并降级角色（保留历史内容）
     */
    protected function expireOne(array $sub, int $now): void
    {
        Db::name('app_subscription')->where('id', $sub['id'])->update([
            'status'      => self::SUB_EXPIRED,
            'update_time' => $now,
        ]);
        $this->downgradeRole((int)$sub['user_id']);
        $this->audit('system', 0, 'subscription.expire', 'subscription', (int)$sub['id'],
            ['status' => $sub['status']], ['status' => self::SUB_EXPIRED]);
    }

    /**
     * 暂停订阅（风控）
     */
    public function suspend(int $subscriptionId, string $reason = ''): void
    {
        $sub = Db::name('app_subscription')->where('id', $subscriptionId)->find();
        if (!$sub) {
            throw new \Exception('订阅不存在');
        }
        Db::name('app_subscription')->where('id', $subscriptionId)->update([
            'status'         => self::SUB_SUSPENDED,
            'suspend_reason' => $reason,
            'update_time'    => time(),
        ]);
        $this->audit('system', 0, 'subscription.suspend', 'subscription', $subscriptionId,
            ['status' => $sub['status']], ['status' => self::SUB_SUSPENDED, 'reason' => $reason]);
    }

    /**
     * 恢复订阅
     */
    public function resume(int $subscriptionId): void
    {
        $sub = Db::name('app_subscription')->where('id', $subscriptionId)->find();
        if (!$sub) {
            throw new \Exception('订阅不存在');
        }
        $now = time();
        $status = ((int)$sub['end_at'] > $now) ? self::SUB_ACTIVE : self::SUB_EXPIRED;
        Db::name('app_subscription')->where('id', $subscriptionId)->update([
            'status'      => $status,
            'update_time' => $now,
        ]);
        if ($status === self::SUB_ACTIVE) {
            $this->assignRoleGroup((int)$sub['user_id'], (string)$sub['role_code']);
        }
        $this->audit('system', 0, 'subscription.resume', 'subscription', $subscriptionId,
            ['status' => $sub['status']], ['status' => $status]);
    }

    /**
     * 计算优惠码折扣
     * @return array{0:float,1:array} [折扣金额, 优惠码记录]
     */
    protected function calcDiscount(string $code, float $amount, string $currency, string $roleCode): array
    {
        $coupon = Db::name('app_coupon')->where('code', $code)->where('status', 'normal')->find();
        if (!$coupon) {
            throw new \Exception('优惠码无效');
        }
        $now = time();
        if ($coupon['start_at'] > 0 && $coupon['start_at'] > $now) {
            throw new \Exception('优惠码尚未生效');
        }
        if ($coupon['expire_at'] > 0 && $coupon['expire_at'] < $now) {
            throw new \Exception('优惠码已过期');
        }
        if ((int)$coupon['max_use'] > 0 && (int)$coupon['used_count'] >= (int)$coupon['max_use']) {
            throw new \Exception('优惠码已用尽');
        }
        if ($coupon['limit_role'] !== '' && $coupon['limit_role'] !== $roleCode) {
            throw new \Exception('该优惠码不适用于当前套餐');
        }

        $discount = $coupon['type'] === 'percent'
            ? round($amount * (1 - (float)$coupon['value'] / 100), 2)
            : (float)$coupon['value'];
        $discount = max(0, min($discount, $amount));

        return [$discount, $coupon];
    }

    /**
     * 分配角色组（按 role_code 找到对应 user_group 并切换）
     */
    public function assignRoleGroup(int $userId, string $roleCode): void
    {
        $groupId = (int)(Db::name('user_group')->where('rules', $roleCode)->value('id') ?: 0);
        if ($groupId <= 0) {
            Log::warning("未找到角色组：{$roleCode}");
            return;
        }
        Db::name('user')->where('id', $userId)->update([
            'group_id'    => $groupId,
            'update_time' => time(),
        ]);
    }

    /**
     * 降级角色：若有其他生效订阅则取最高角色，否则回落到游客
     */
    public function downgradeRole(int $userId): void
    {
        $now = time();
        $active = Db::name('app_subscription')
            ->where('user_id', $userId)
            ->whereIn('status', [self::SUB_ACTIVE, self::SUB_GRACE])
            ->where('end_at', '>', $now)
            ->order('end_at', 'desc')
            ->find();

        if ($active) {
            $this->assignRoleGroup($userId, (string)$active['role_code']);
            return;
        }

        $visitorGroupId = (int)(Db::name('user_group')->where('rules', 'VISITOR')->value('id') ?: 0);
        if ($visitorGroupId > 0) {
            Db::name('user')->where('id', $userId)->update([
                'group_id'    => $visitorGroupId,
                'update_time' => $now,
            ]);
        }
    }

    /**
     * 生成订单号：日期 + 随机 + 微秒片段
     */
    protected function buildOrderNo(): string
    {
        // 纯数字订单号：时间 + 随机 + 4 位序列，避免出现小数点等非数字字符
        return date('YmdHis') . mt_rand(1000, 9999) . sprintf('%04d', random_int(0, 9999));
    }

    /**
     * 写审计日志（仅 INSERT）
     */
    public function audit(string $actorType, int $actorId, string $action, string $targetType, int $targetId, array $before, array $after): void
    {
        try {
            Db::name('app_audit_log')->insert([
                'actor_type'  => $actorType,
                'actor_id'    => $actorId,
                'action'      => $action,
                'target_type' => $targetType,
                'target_id'   => $targetId,
                'before'      => $before ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
                'after'       => $after ? json_encode($after, JSON_UNESCAPED_UNICODE) : null,
                'ip'          => request()->ip() ?: '',
                'create_time' => time(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('审计日志写入失败：' . $e->getMessage());
        }
    }
}
