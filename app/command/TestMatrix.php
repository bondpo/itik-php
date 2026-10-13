<?php

declare(strict_types=1);

namespace app\command;

use app\common\service\MatrixService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * Matrix IM 自动化连通性与功能集成测试指令
 *
 * 用法：
 *   php think matrix:test
 *   php think matrix:test --userA=alice --userB=bob
 */
class TestMatrix extends Command
{
    protected function configure()
    {
        $this->setName('matrix:test')
            ->addOption('userA', null, Option::VALUE_OPTIONAL, '测试用户A用户名/ID', 'm_test_a')
            ->addOption('userB', null, Option::VALUE_OPTIONAL, '测试用户B用户名/ID', 'm_test_b')
            ->setDescription('执行 Matrix IM 全功能端到端集成测试套件');
    }

    protected function execute(Input $input, Output $output)
    {
        $userA = (string) $input->getOption('userA');
        $userB = (string) $input->getOption('userB');

        $output->info("==================================================");
        $output->info("  🚀 正在启动 Matrix IM 服务自动化集成测试套件...  ");
        $output->info("  测试账号: {$userA}  <-->  {$userB}");
        $output->info("==================================================");

        $service = new MatrixService();
        $report = $service->runTestSuite($userA, $userB);

        $output->newLine();
        foreach ($report['steps'] as $step) {
            $status = $step['passed'] ? "\033[32m[PASS]\033[0m" : "\033[31m[FAIL]\033[0m";
            $output->writeln(sprintf(" %s %s", $status, $step['name']));
            if (!empty($step['message'])) {
                $output->writeln(sprintf("        └─ 详情: %s", $step['message']));
            }
        }

        $output->newLine();
        $output->info("--------------------------------------------------");
        if ($report['all_passed']) {
            $output->writeln(sprintf("\033[32m✔ 全部测试用例执行通过！总耗时: %sms\033[0m", $report['total_cost_ms']));
        } else {
            $output->writeln(sprintf("\033[31m✘ 存在未通过的测试项，请根据上述提示排查！总耗时: %sms\033[0m", $report['total_cost_ms']));
        }
        $output->info("==================================================");

        return $report['all_passed'] ? 0 : 1;
    }
}
