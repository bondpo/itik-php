<?php

declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

/**
 * 地区数据导入指令
 *
 * 用途：将中国省市区县全量数据（GB/T 2260 行政区划代码）导入 bd_region。
 *
 * 数据源（任选其一，均为公开数据集）：
 *   - modood/Administrative-divisions-of-China（省市区县 JSON）
 *   - xiangyuecn/AreaCity-JsSpider-StatsGov（含统计用区划代码与经纬度）
 *
 * 输入 JSON 结构（嵌套树）：
 *   [
 *     {"code": "110000", "name": "北京市", "children": [
 *       {"code": "110100", "name": "市辖区", "children": [
 *         {"code": "110101", "name": "东城区"}
 *       ]}
 *     ]}
 *   ]
 *
 * 用法：
 *   php think region:import --file=path/to/regions.json
 *   php think region:import --file=path/to/regions.json --parent-code=CN --dry-run
 */
class ImportRegion extends Command
{
    protected function configure()
    {
        $this->setName('region:import')
            ->addArgument('file', Argument::OPTIONAL, 'JSON 数据文件路径')
            ->addOption('file', null, Option::VALUE_REQUIRED, 'JSON 数据文件路径')
            ->addOption('parent-code', null, Option::VALUE_OPTIONAL, '根节点挂载的父级代码（默认 CN=中国）', 'CN')
            ->addOption('dry-run', null, Option::VALUE_NONE, '仅解析校验，不写库')
            ->setDescription('从 JSON 文件导入中国省市区县地区数据');
    }

    protected function execute(Input $input, Output $output)
    {
        $file       = (string) ($input->getArgument('file') ?: $input->getOption('file'));
        $parentCode = (string) $input->getOption('parent-code');
        $dryRun     = (bool) $input->getOption('dry-run');

        if ($file === '' || !is_file($file)) {
            $output->error("数据文件不存在：{$file}");
            return 1;
        }

        $raw = file_get_contents($file);
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            $output->error('JSON 解析失败，请检查文件格式（需为数组）');
            return 1;
        }

        $parent = Db::name('region')->where('code', $parentCode)->find();
        if (!$parent) {
            $output->error("未找到父级地区代码：{$parentCode}（请先执行 database/init_region.php 初始化国家数据）");
            return 1;
        }

        $count = 0;
        $output->info("父级：{$parent['name_zh']} (code={$parentCode}, id={$parent['id']})");
        $output->info('开始导入...');

        try {
            $this->importChildren($data, (int) $parent['id'], (int) $parent['level'], $count, $output, $dryRun);
        } catch (\Throwable $e) {
            $output->error('导入异常：' . $e->getMessage());
            return 1;
        }

        $output->newLine();
        $output->info($dryRun ? "解析完成，共 {$count} 个节点（dry-run，未写库）" : "导入完成，共写入 {$count} 条地区数据");
        return 0;
    }

    /**
     * 递归导入子节点
     *
     * @param array  $nodes  节点数组
     * @param int    $parentId 父级 ID
     * @param int    $parentLevel 父级层级
     * @param int    $count  计数（引用）
     */
    private function importChildren(array $nodes, int $parentId, int $parentLevel, int &$count, Output $output, bool $dryRun): void
    {
        foreach ($nodes as $node) {
            if (!is_array($node) || empty($node['name'])) {
                continue;
            }

            $code   = (string) ($node['code'] ?? '');
            $nameZh = trim((string) $node['name']);
            $level  = $parentLevel + 1;

            if (!$dryRun) {
                // 已存在同 code 同 parent 的记录则跳过（幂等）
                $exist = Db::name('region')
                    ->where('parent_id', $parentId)
                    ->where('code', $code)
                    ->where('code', '<>', '')
                    ->find();
                if ($exist) {
                    $nodeId = (int) $exist['id'];
                } else {
                    $nodeId = (int) Db::name('region')->insertGetId([
                        'parent_id'     => $parentId,
                        'level'         => $level,
                        'code'          => $code,
                        'name_zh'       => $nameZh,
                        'name_en'       => (string) ($node['name_en'] ?? ''),
                        'name_short_zh' => (string) ($node['name_short'] ?? ''),
                        'pinyin'        => (string) ($node['pinyin'] ?? ''),
                        'py'            => (string) ($node['py'] ?? ''),
                        'weigh'         => 0,
                        'status'        => 'normal',
                        'create_time'   => time(),
                        'update_time'   => time(),
                    ]);
                }
            } else {
                $nodeId = 0;
            }

            $count++;
            if ($count % 500 === 0) {
                $output->writeln("  已处理 {$count} 个节点...");
            }

            if (!empty($node['children']) && is_array($node['children'])) {
                $this->importChildren($node['children'], $dryRun ? 0 : $nodeId, $level, $count, $output, $dryRun);
            }
        }
    }
}
