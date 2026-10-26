<?php

namespace app\common\service;
use app\common\service\app\SubscriptionService;
use think\facade\Db;

class Ds
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

    public function orderAct($order_id=1){
        $service = new SubscriptionService();
        $order = \app\admin\model\app\Order::where('id', $order_id)->find();
        if($order && $order->confirm == '0'){
            $plan = Db::name('app_plan')->where('plan_code', $order['plan_code'])->find();
            $now = time();
            $duration = max(1, (int)$plan['duration_days']) * 86400;
            // 2) 激活订阅（同套餐未到期则顺延）
            $sub = Db::name('app_subscription')->where('source_order_id', $order['id'])->find();
            $startAt = $now;

            $current = Db::name('app_subscription')
                ->where('user_id', $order['user_id'])
                ->where('plan_code', $order['plan_code'])
                ->whereIn('status', [self::SUB_ACTIVE, self::SUB_GRACE])
                ->where('end_at', '>', $now)
                ->order('end_at', 'desc')
                ->find();
            if ($current) {
                $startAt = (int)$current['end_at']; // 顺延
            }

            $subData = [
                'user_id'    => $order['user_id'],
                'plan_code'  => $order['plan_code'],
                'role_code'  => $plan['role_code'],
                'status'     => self::SUB_ACTIVE,
                'start_at'   => $startAt,
                'end_at'     => $startAt + $duration,
                'update_time' => $now,
            ];

            if ($sub) {
                Db::name('app_subscription')->where('id', $sub['id'])->update($subData);
                $subId = (int)$sub['id'];
            } else {
                $subData['source_order_id'] = $order['id'];
                $subData['create_time'] = $now;
                $subId = (int)Db::name('app_subscription')->insertGetId($subData);
            }

            $this->userAct($order_id);

            // 3) 角色升级（游客/会员 → 对应角色组）
            $service->assignRoleGroup((int)$order['user_id'], (string)$plan['role_code']);

            $service->audit('system', 0, 'order.pay', 'order', (int)$order['id'], ['status' => self::ORDER_PENDING], [
                'status'   => self::ORDER_PAID,
                'pay_type' => $payType,
                'trade_no' => $tradeNo,
                'sub_id'   => $subId,
                'end_at'   => $subData['end_at'],
            ]);

            \app\admin\model\app\Order::where('id', $order_id)->update(['confirm' => '1']);
        }
    }

    public function userAct($order_id=1){
        $order = \app\admin\model\app\Order::where('id', $order_id)->find();
        if($order && $order->status == 'paid' && ($order->amount == 100 || $order->amount == 3000)){
            $user = \app\admin\model\User::where('id', $order['user_id'])->find();
            if($user){
                $update = [];
                $active = false;
                if($user->group_id == 9) {
                    $update['act_time'] = time();
                    $active = true;
                }
                $update['perf_self'] = $user->perf_self+$order->amount;
                \app\admin\model\User::where('id', $user->id)->update($update);

                $pUser = \app\admin\model\User::where('id', $user->inviter_id)->find();
                $tLvs = \app\admin\model\UserLvTeam::where('status', '1')->column('name,perf,rate', 'id');
                $teamLv = 1;
                $pjRate = 0.01;
                $pjLv = 6;
                $loop = 1;
                while($pUser){
                    $update = [];
                    if($loop == 1){
                        $ivUser = \app\admin\model\User::where('inviter_id', $pUser->id)->where('act_time', '>', 0)->order('act_time asc')->field('id,act_time')->select()->toArray();
                        if(!empty($ivUser)){
                            $rankMap = [];
                            foreach ($ivUser as $i => $u) {
                                $rankMap[$u['id']] = $i + 1;
                            }
                            $sort = $rankMap[$user->id];
                            if ($sort & 1) {
                                $rate = 0.4;
                            } else {
                                $rate = 0.6;
                            }
                            $bonus = $order->amount * $rate;
                            $this->bonusAct($pUser, $bonus, '分享奖励');
                        }
                    }
                    if($pUser->lv_team > $teamLv){
                        $bonus = $order->amount * ($tLvs[$pUser->lv_team]['rate'] - $tLvs[$teamLv]['rate']);
                        $this->bonusAct($pUser, $bonus, '社区奖励');
                        $teamLv = $pUser->lv_team;
                        if($pUser->lv_team >= $pjLv) $pjRate = 0.01;
                    }else if($pUser->lv_team == $teamLv && $teamLv >= $pjLv && $pjRate > 0){
                        $bonus = $order->amount * $pjRate;
                        $this->bonusAct($pUser, $bonus, '社区平级');
                        $pjRate = 0;
                    }
                    if($active){
                        if($loop == 1)
                            $update['nums_iv'] = Db::raw("nums_iv+1");
                        $update['nums_team'] = Db::raw("nums_team+1");
                    }
                    $update['perf_team'] = Db::raw("perf_team+{$order->amount}");
                    foreach($tLvs as $lv => $tLv){
                        if($lv > $pUser->lv_team && ($pUser->perf_team+$order->amount) >= $tLv['perf']) {
                            $update['lv_team'] = $lv;
                        }
                    }
                    \app\admin\model\User::where('id', $pUser->id)->update($update);
                    $pUser = \app\admin\model\User::where('id', $pUser->inviter_id)->find();
                    $loop++;
                }
            }
        }
    }

    public function bonusAct($user, $bonus, $memo, $order_no=''){
        if($user->group_id != 9){
            $before = $user->money;
            $after = function_exists('bcadd') ? bcadd($user->money, $bonus, 2) : ($user->money + $bonus);
            \app\admin\model\User::where('id', $user->id)->setInc('money', $bonus);
            \app\common\model\MoneyLog::create([
                'user_id' => $user->id,
                'money' => $bonus,
                'before' => $before,
                'after' => $after,
                'memo' => $memo
            ]);
        }
    }

}