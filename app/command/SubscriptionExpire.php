<?php

// +----------------------------------------------------------------------
// | 定时任务：订阅到期扫描
// +----------------------------------------------------------------------
// | 用途：ACTIVE → GRACE → EXPIRED，超时未支付订单关闭，并同步角色升降级
// | 用法：php think subscription:expire [--grace=7]
// | 建议：由 crontab 每日执行一次（如 0 3 * * *）
// +----------------------------------------------------------------------

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;
use app\common\service\app\SubscriptionService;

class SubscriptionExpire extends Command
{
    protected function configure()
    {
        $this->setName('subscription:expire')
            ->addOption('grace', null, Option::VALUE_OPTIONAL, '宽限期天数（0=不启用，默认读取配置 app/grace_days）', null)
            ->setDescription('扫描到期的订阅：转入宽限期或置为已到期，并同步角色');
    }

    protected function execute(Input $input, Output $output)
    {
        $grace = $input->getOption('grace');
        if ($grace === null || $grace === '') {
            $grace = (int)Db::name('config')->where('group', 'app')->where('name', 'grace_days')->value('value');
        }
        $grace = max(0, (int)$grace);

        $service = new SubscriptionService();
        $result = $service->syncExpired($grace);

        $output->writeln('订阅到期扫描完成');
        $output->writeln('  宽限期设置：' . $grace . ' 天');
        $output->writeln('  转入宽限期：' . $result['grace'] . ' 条');
        $output->writeln('  置为已到期：' . $result['expired'] . ' 条');
        $output->writeln('  关闭超时订单：' . $result['closed_orders'] . ' 条');
        return 0;
    }
}
