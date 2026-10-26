<?php

namespace app\command;

use app\common\service\i18n\TranslationService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;

class TranslateContent extends Command
{
    protected function configure()
    {
        $this->setName('i18n:translate')->setDescription('扫描已发布内容并执行百度翻译任务')
            ->addOption('limit', null, Option::VALUE_OPTIONAL, '单次最多处理字段数', 20)
            ->addOption('scan-only', null, Option::VALUE_NONE, '仅扫描，不调用百度 API');
    }

    protected function execute(Input $input, Output $output)
    {
        // 同一部署只允许一个 worker，避免并发扫描和突破账户 QPS。
        $lock = fopen(runtime_path() . 'i18n-worker.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            $output->writeln('已有翻译任务正在执行');
            return 0;
        }
        try {
            $service = new TranslationService();
            $count = $service->scan();
            $output->writeln('已检查字段语言组合：' . $count);
            if (!$input->getOption('scan-only')) {
                $result = $service->work(max(1, min(1000, (int)$input->getOption('limit'))));
                $output->writeln('翻译成功：' . $result['success'] . '，失败：' . $result['failed']);
                return $result['failed'] ? 1 : 0;
            }
            return 0;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
