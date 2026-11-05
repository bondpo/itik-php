<?php

namespace app\api\controller\v1;

use think\facade\Db;

/**
 * 用户资金统计与明细接口
 *
 * 路由：
 *   GET  /api/v1/users/money-stat  资金统计（按类型和时间筛选）
 *   GET  /api/v1/users/money-log   资金明细列表（分页，ID倒序）
 */
class Money extends Base
{
    protected $noNeedLogin = [];
    protected $noNeedRight = '*';

    /**
     * 资金统计
     * 支持按时间筛选，按类型统计，支持单日对比昨日
     */
    public function stat()
    {
        $userId = $this->userId();
        if (!$userId) {
            $this->fail('用户未登录', self::CODE_UNAUTHORIZED);
        }

        // 时间参数
        $startDate = $this->request->param('start_date', '');
        $endDate = $this->request->param('end_date', '');
        
        // 解析日期
        if ($startDate && $endDate) {
            $startTimestamp = strtotime($startDate);
            $endTimestamp = strtotime($endDate . ' 23:59:59');
        } else {
            // 默认查询今天
            $startTimestamp = strtotime(date('Y-m-d'));
            $endTimestamp = strtotime(date('Y-m-d') . ' 23:59:59');
        }

        $type = $this->request->param('type', 'all');

        // 查询条件
        $where = ['user_id' => $userId];

        // 时间范围用 whereBetween 链式
        $query = Db::name('user_money_log')
            ->where($where)
            ->whereBetween('createtime', [$startTimestamp, $endTimestamp]);

        // 类型条件按分支链式追加
        if ($type === 'all') {
            $query->whereIn('type', ['share', 'community', 'peerLv']);
        } elseif (in_array($type, ['share', 'community', 'peerLv'])) {
            $query->where('type', $type);
        }

        $currentStats = $query
            ->field('type,memo,SUM(money) as total')
            ->group('type')
            ->select()
            ->toArray();

        $current = [
            'share' => 0,
            'community' => 0,
            'peerLv' => 0,
            'total' => 0
        ];
        
        foreach ($currentStats as $stat) {
            switch ($stat['type']) {
                case 'share':
                    $current['share'] = $stat['total'];
                    break;
                case 'community':
                    $current['community'] = $stat['total'];
                    break;
                case 'peerLv':
                    $current['peerLv'] = $stat['total'];
                    break;
            }
        }
        $current['total'] = $current['share'] + $current['community'] + $current['peerLv'];
        
        // 计算昨日对比
        $todayStart = strtotime(date('Y-m-d'));
        $todayEnd = strtotime(date('Y-m-d') . ' 23:59:59');
        $query = Db::name('user_money_log')
            ->where($where)
            ->whereBetween('createtime', [$todayStart, $todayEnd])
            ->whereIn('type', ['share', 'community', 'peerLv']);
        $todayStats = $query
            ->field('type,memo,SUM(money) as total')
            ->group('type')
            ->select()
            ->toArray();
        $todaySum = [
            'share' => 0,
            'community' => 0,
            'peerLv' => 0,
            'total' => 0
        ];
        foreach ($todayStats as $stat) {
            switch ($stat['type']) {
                case 'share':
                    $todaySum['share'] = $stat['total'];
                    break;
                case 'community':
                    $todaySum['community'] = $stat['total'];
                    break;
                case 'peerLv':
                    $todaySum['peerLv'] = $stat['total'];
                    break;
            }
        }
        $todaySum['total'] = $todaySum['share'] + $todaySum['community'] + $todaySum['peerLv'];

        $prevStart = strtotime(date('Y-m-d')) - 86400;
        $prevEnd = strtotime(date('Y-m-d') . ' 23:59:59') - 86400;
        $query = Db::name('user_money_log')
            ->where($where)
            ->whereBetween('createtime', [$prevStart, $prevEnd])
            ->whereIn('type', ['share', 'community', 'peerLv']);
        $prevStats = $query
            ->field('type,memo,SUM(money) as total')
            ->group('type')
            ->select()
            ->toArray();
        $previous = [
            'share' => 0,
            'community' => 0,
            'peerLv' => 0,
            'total' => 0
        ];
        foreach ($prevStats as $stat) {
            switch ($stat['type']) {
                case 'share':
                    $previous['share'] = $stat['total'];
                    break;
                case 'community':
                    $previous['community'] = $stat['total'];
                    break;
                case 'peerLv':
                    $previous['peerLv'] = $stat['total'];
                    break;
            }
        }
        $previous['total'] = $previous['share'] + $previous['community'] + $previous['peerLv'];

        // 计算百分比变化
//            $compare = [
//                'share' => $previous['share'] > 0 ? round(($current['share'] - $previous['share']) / $previous['share'] * 100, 2) : ($current['share'] > 0 ? 100 : 0),
//                'community' => $previous['community'] > 0 ? round(($current['community'] - $previous['community']) / $previous['community'] * 100, 2) : ($current['community'] > 0 ? 100 : 0),
//                'peerLv' => $previous['peerLv'] > 0 ? round(($current['peerLv'] - $previous['peerLv']) / $previous['peerLv'] * 100, 2) : ($current['peerLv'] > 0 ? 100 : 0),
//                'total' => $previous['total'] > 0 ? round(($current['total'] - $previous['total']) / $previous['total'] * 100, 2) : ($current['total'] > 0 ? 100 : 0),
//            ];
        $compare = [
            'share' => $todaySum['share'] - $previous['share'],
            'community' => $todaySum['community'] - $previous['community'],
            'peerLv' => $todaySum['peerLv'] - $previous['peerLv'],
            'total' => $todaySum['total'] - $previous['total'],
        ];
        
        // 近7日每日统计
        $last7Days = [];
        if ($type === 'all') {
            $dateRanges = [];
            for ($i = 0; $i < 7; $i++) {
                $date = date('Y-m-d', strtotime("-$i days"));
                $dateRanges[] = [
                    'start' => strtotime($date),
                    'end' => strtotime($date . ' 23:59:59'),
                    'date' => $date
                ];
            }
            
            $dateRanges = array_reverse($dateRanges, true);
            foreach ($dateRanges as $range) {
                $dayTotal = Db::name('user_money_log')
                    ->where('user_id', $userId)
                    ->whereBetween('createtime', [$range['start'], $range['end']])
                    ->whereIn('type', ['share', 'community', 'peerLv'])
                    ->sum('money');
                
                $last7Days[] = [
                    'date' => $range['date'],
                    'total' => $dayTotal
                ];
            }
        }
        
        $this->success('', [
            'current' => $current,
            'period' => [
                'start' => $startDate,
                'end' => $endDate,
            ],
            'compare' => $compare,
            'last7days' => $last7Days,
        ]);
    }

    /**
     * 资金明细列表
     */
    public function index()
    {
        $memo = \app\admin\model\BonusMemo::where('id', '>', 0)->field('id,code,name')->select();
        $memo = $this->translations()->rows('bonus_memo', $memo);
        $memo = array_column($memo, 'name', 'code');
        $userId = $this->userId();
        if (!$userId) {
            $this->fail('用户未登录', self::CODE_UNAUTHORIZED);
        }

        [$page, $limit] = $this->pageParams(20, 100);
        [$orderby, $orderway] = $this->orderParams(['id', 'createtime', 'money'], 'id');
        $type = $this->request->param('type', '');
        $where = ['user_id' => $userId];
        if($type) $where['type'] = $type;
        $total = Db::name('user_money_log')
            ->where($where)
            ->count();

        $list = Db::name('user_money_log')
            ->where($where)
            ->field('id,user_id,money,before,after,type,memo,order_no,createtime')
            ->order($orderby, $orderway)
            ->page($page, $limit)
            ->select()
            ->toArray();
        foreach ($list as &$item) {
            if (array_key_exists($item['type'], $memo)) {
                $item['memo'] = $memo[$item['type']];
            }
            $item['createtime'] = date('Y-m-d H:i:s', $item['createtime']);
        }
        unset($memo['sub']);
        unset($memo['withdraw']);
        $this->success('', [
            'type'  => $memo,
            'list'  => $list,
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
        ]);
    }
}
