<?php

// +----------------------------------------------------------------------
// | 定时任务：批量生成每日推荐
// +----------------------------------------------------------------------
// | 用途：为项目方与社区长按配额生成当日推荐（去重、限量）
// | 用法：php think recommend:generate [--limit=200]
// | 建议：每日凌晨执行（如 0 1 * * *）
// +----------------------------------------------------------------------

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;
use app\common\service\app\RecommendService;

class RecommendGenerate extends Command
{
    protected function configure()
    {
        $this->setName('recommend:generate')
            ->addOption('limit', null, Option::VALUE_OPTIONAL, '单次处理的用户上限', 200)
            ->setDescription('为项目方与社区长批量生成当日推荐（受配额与去重约束）');
    }

    protected function execute(Input $input, Output $output)
    {
        $limit = max(1, (int)$input->getOption('limit'));
        $service = new RecommendService();

        $ownerGroup  = (int)Db::name('user_group')->where('rules', 'OWNER')->value('id');
        $leaderGroup = (int)Db::name('user_group')->where('rules', 'LEADER')->value('id');
        $now = time();

        $totalGenerated = 0;
        $processed = 0;

        foreach ([[$ownerGroup, RecommendService::CHANNEL_OWNER_MATCH],
                  [$leaderGroup, RecommendService::CHANNEL_LEADER_MEMBER]] as [$gid, $channel]) {
            if ($gid <= 0) {
                continue;
            }
            // 仅处理订阅生效中的用户
            $userIds = Db::name('app_subscription')
                ->where('role_code', $channel === RecommendService::CHANNEL_OWNER_MATCH ? 'OWNER' : 'LEADER')
                ->whereIn('status', ['ACTIVE', 'GRACE'])
                ->where('end_at', '>', $now)
                ->limit($limit)
                ->column('user_id');
            $userIds = array_unique(array_map('intval', $userIds));

            foreach ($userIds as $uid) {
                $ret = $service->generate($uid, $channel);
                $totalGenerated += $ret['generated'];
                $processed++;
            }
            $output->writeln(sprintf('  %s：处理 %d 个用户，生成 %d 条',
                $channel, count($userIds), $totalGenerated));
        }

        $output->writeln('推荐生成完成：处理 ' . $processed . ' 个用户，共生成 ' . $totalGenerated . ' 条');
        return 0;
    }
}
