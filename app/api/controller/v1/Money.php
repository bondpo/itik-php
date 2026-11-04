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
        
        if ($type === 'all') {
            $where['memo'] = ['in', ['分享奖励', '社区奖励', '社区平级']];
        } elseif (in_array($type, ['share', 'community', 'level'])) {
            $typeMap = [
                'share' => '分享奖励',
                'community' => '社区奖励',
                'level' => '社区平级'
            ];
            $where['memo'] = $typeMap[$type];
        }
        
        $where['createtime'] = ['between', [$startTimestamp, $endTimestamp]];
        
        // 基础统计（当前时间段）
        $currentStats = Db::name('user_money_log')
            ->where($where)
            ->field('memo,SUM(money) as total')
            ->group('memo')
            ->select()
            ->toArray();
        
        $current = [
            'share' => 0,
            'community' => 0,
            'level' => 0,
            'total' => 0
        ];
        
        foreach ($currentStats as $stat) {
            switch ($stat['memo']) {
                case '分享奖励':
                    $current['share'] = $stat['total'];
                    break;
                case '社区奖励':
                    $current['community'] = $stat['total'];
                    break;
                case '社区平级':
                    $current['level'] = $stat['total'];
                    break;
            }
        }
        $current['total'] = $current['share'] + $current['community'] + $current['level'];
        
        // 如果是单日查询，计算昨日对比
        $compare = null;
        if ($startDate === $endDate) {
            $prevStart = $startTimestamp - 86400;
            $prevEnd = $endTimestamp - 86400;
            
            $prevWhere = $where;
            $prevWhere['createtime'] = ['between', [$prevStart, $prevEnd]];
            
            $prevStats = Db::name('user_money_log')
                ->where($prevWhere)
                ->field('memo,SUM(money) as total')
                ->group('memo')
                ->select()
                ->toArray();
            
            $previous = [
                'share' => 0,
                'community' => 0,
                'level' => 0,
                'total' => 0
            ];
            
            foreach ($prevStats as $stat) {
                switch ($stat['memo']) {
                    case '分享奖励':
                        $previous['share'] = $stat['total'];
                        break;
                    case '社区奖励':
                        $previous['community'] = $stat['total'];
                        break;
                    case '社区平级':
                        $previous['level'] = $stat['total'];
                        break;
                }
            }
            $previous['total'] = $previous['share'] + $previous['community'] + $previous['level'];
            
            // 计算百分比变化
            $compare = [
                'share' => $previous['share'] > 0 ? round(($current['share'] - $previous['share']) / $previous['share'] * 100, 2) : ($current['share'] > 0 ? 100 : 0),
                'community' => $previous['community'] > 0 ? round(($current['community'] - $previous['community']) / $previous['community'] * 100, 2) : ($current['community'] > 0 ? 100 : 0),
                'level' => $previous['level'] > 0 ? round(($current['level'] - $previous['level']) / $previous['level'] * 100, 2) : ($current['level'] > 0 ? 100 : 0),
                'total' => $previous['total'] > 0 ? round(($current['total'] - $previous['total']) / $previous['total'] * 100, 2) : ($current['total'] > 0 ? 100 : 0),
            ];
        }
        
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
                $dayWhere = [
                    'user_id' => $userId,
                    'createtime' => ['between', [$range['start'], $range['end']]],
                    'memo' => ['in', ['分享奖励', '社区奖励', '社区平级']]
                ];
                
                $dayTotal = Db::name('user_money_log')
                    ->where($dayWhere)
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
        $userId = $this->userId();
        if (!$userId) {
            $this->fail('用户未登录', self::CODE_UNAUTHORIZED);
        }

        [$page, $limit] = $this->pageParams(20, 100);
        [$orderby, $orderway] = $this->orderParams(['id', 'createtime', 'money'], 'id');

        $total = Db::name('user_money_log')
            ->where('user_id', $userId)
            ->count();

        $list = Db::name('user_money_log')
            ->where('user_id', $userId)
            ->field('id,user_id,money,before,after,memo,order_no,createtime')
            ->order($orderby, $orderway)
            ->page($page, $limit)
            ->select()
            ->toArray();

        $this->success('', [
            'list'  => $list,
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
        ]);
    }
}
