<?php
declare (strict_types = 1);

namespace app\command;

use app\common\service\MatrixService;
use app\common\service\Ds;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;
use think\Config;


class Calc extends Command
{
    protected function configure()
    {
        // 指令配置
        $this->setName('app\command\calc')
            ->addArgument('func', Argument::OPTIONAL, '指定执行', '')
            ->addArgument('order_id', Argument::OPTIONAL, '订单ID', 0) // 可选参数，默认 1
            ->setDescription('the app\command\calc command');
    }

    protected function execute(Input $input, Output $output)
    {
//        $ids = [145,146];
//        $params = [];
//        foreach ($ids as $id) {
//            $params[$id]['id'] = $id;
//            $params[$id]['username'] = \app\common\model\User::where('id', $id)->value('username');
//            $params[$id]['imUsername'] = (new MatrixService())->buildUserId($id);
//        }
//        var_dump($params);
        $func = $input->getArgument('func');
        if($func == 'ds'){
            $orderId = (int) $input->getArgument('order_id');
            if($orderId > 0){
                try {
                    $ds = new Ds();
                    $result = $ds->orderAct($orderId);
                    $output->writeln("[CALC] orderAct 执行完成，订单ID={$orderId}");
                } catch (\Throwable $e) {
                    $output->writeln(
                        "[CALC ERROR] {$e->getMessage()} 文件：{$e->getFile()} 行号：{$e->getLine()}"
                    );
                    throw $e;
                }
            }
        }else{
            var_dump(get_sys_config('wallet'));
        }
        // 指令输出
        $output->writeln('app\command\calc');
    }



    public function orderAct($order_id=1){
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

        // 3) 角色升级（游客/会员 → 对应角色组）
        $this->assignRoleGroup((int)$order['user_id'], (string)$plan['role_code']);

        $this->audit('system', 0, 'order.pay', 'order', (int)$order['id'], ['status' => self::ORDER_PENDING], [
            'status'   => self::ORDER_PAID,
            'pay_type' => $payType,
            'trade_no' => $tradeNo,
            'sub_id'   => $subId,
            'end_at'   => $subData['end_at'],
        ]);
    }

    public function bonusAct($user, $bonus, $memo, $order_no=''){
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
