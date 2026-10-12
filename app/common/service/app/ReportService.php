<?php

// +----------------------------------------------------------------------
// | 项目社区信息交互平台 —— 统计报表服务层
// +----------------------------------------------------------------------
// | 指标依据需求 §2.3：用户增长 / 订阅商业化 / 项目生态 / 匹配效率 /
// | 关系网络 / 沟通活跃 / 内容质量
// +----------------------------------------------------------------------

namespace app\common\service\app;

use think\facade\Db;

class ReportService
{
    /**
     * 核心指标概览（后台看板）
     */
    public function overview(): array
    {
        $now = time();
        $todayStart = strtotime(date('Y-m-d'));
        $yesterdayStart = $todayStart - 86400;
        $monthStart = strtotime(date('Y-m-01'));

        // ===== 用户增长 =====
        $totalUser     = (int)Db::name('user')->count();
        $todayUser     = (int)Db::name('user')->where('jointime', '>=', $todayStart)->count();
        $yesterdayUser = (int)Db::name('user')->whereBetween('jointime', [$yesterdayStart, $todayStart])->count();

        // ===== 订阅商业化 =====
        $activeSub  = (int)Db::name('app_subscription')->where('status', 'ACTIVE')->where('end_at', '>', $now)->count();
        $graceSub   = (int)Db::name('app_subscription')->where('status', 'GRACE')->where('grace_end_at', '>', $now)->count();
        $paidUser   = $this->countDistinct('app_subscription', 'user_id', function ($q) use ($now) {
            $q->whereIn('status', ['ACTIVE', 'GRACE'])->where('end_at', '>', $now);
        });

        $paidOrders   = (int)Db::name('app_order')->where('status', 'paid')->count();
        $totalOrder   = (int)Db::name('app_order')->count();
        $revenueSum   = (float)Db::name('app_order')->where('status', 'paid')->sum('amount');
        $revenueMonth = (float)Db::name('app_order')->where('status', 'paid')->where('paid_at', '>=', $monthStart)->sum('amount');
        $revenueToday = (float)Db::name('app_order')->where('status', 'paid')->where('paid_at', '>=', $todayStart)->sum('amount');

        // ===== 项目生态 =====
        $totalProject   = (int)Db::name('app_project')->whereNull('deletetime')->count();
        $validProject   = (int)Db::name('app_project')->where('status', 'normal')->whereNull('deletetime')->count();
        $certifiedCount = (int)Db::name('app_project')->where('certified', 1)->whereNull('deletetime')->count();
        $pendingProject = (int)Db::name('app_project')->where('status', 'pending')->whereNull('deletetime')->count();

        // ===== 内容与审核 =====
        $totalContent   = (int)Db::name('app_content')->whereNull('deletetime')->count();
        $pendingContent = (int)Db::name('app_content')->where('status', 'pending')->whereNull('deletetime')->count();
        $reviewPass     = (int)Db::name('app_content_review')->where('result', 'normal')->count();
        $reviewAll      = (int)Db::name('app_content_review')->whereIn('result', ['normal', 'rejected'])->count();
        $reviewPassRate = $reviewAll > 0 ? round($reviewPass / $reviewAll * 100, 2) : 0.0;

        // ===== 关系网络 =====
        $followTotal = (int)Db::name('app_follow')->where('status', 'normal')->count();
        $favTotal    = (int)Db::name('app_favorite')->count();

        // ===== 沟通活跃（留言式咨询）=====
        $conversationTotal = (int)Db::name('app_conversation')->count();
        $messageTotal      = (int)Db::name('app_message')->count();
        $messageToday      = (int)Db::name('app_message')->where('create_time', '>=', $todayStart)->count();

        // ===== 匹配效率（推荐）=====
        $recTotal    = (int)Db::name('app_recommendation')->count();
        $recViewed   = (int)Db::name('app_recommendation')->where('is_viewed', 1)->count();
        $recResponded = (int)Db::name('app_recommendation')->where('is_responded', 1)->count();
        $recRelevant = (int)Db::name('app_recommendation')->where('feedback', 'relevant')->count();
        $recFeedback = (int)Db::name('app_recommendation')->whereIn('feedback', ['relevant', 'irrelevant'])->count();

        // ===== 推流 =====
        $campaignActive  = (int)Db::name('app_promotion_campaign')->where('status', 'normal')->count();
        $campaignPending = (int)Db::name('app_promotion_campaign')->where('status', 'pending')->count();
        $exposureTotal   = (int)Db::name('app_promotion_stat')->sum('exposure');
        $clickTotal      = (int)Db::name('app_promotion_stat')->sum('click');

        // ===== 风控 =====
        $pendingReport = (int)Db::name('app_report')->whereIn('status', ['pending', 'processing'])->count();

        return [
            'generated_at' => $now,
            'user' => [
                'total'           => $totalUser,
                'today_new'       => $todayUser,
                'yesterday_new'   => $yesterdayUser,
                'growth_rate'     => $yesterdayUser > 0 ? round(($todayUser - $yesterdayUser) / $yesterdayUser * 100, 2) : ($todayUser > 0 ? 100.0 : 0.0),
            ],
            'billing' => [
                'active_subscription' => $activeSub,
                'grace_subscription'  => $graceSub,
                'paid_user'           => $paidUser,
                'conversion_rate'     => $totalUser > 0 ? round($paidUser / $totalUser * 100, 2) : 0.0,
                'order_total'         => $totalOrder,
                'order_paid'          => $paidOrders,
                'order_pay_rate'      => $totalOrder > 0 ? round($paidOrders / $totalOrder * 100, 2) : 0.0,
                'revenue_total'       => round($revenueSum, 2),
                'revenue_month'       => round($revenueMonth, 2),
                'revenue_today'       => round($revenueToday, 2),
                'currency'            => 'USD',
            ],
            'project' => [
                'total'      => $totalProject,
                'valid'      => $validProject,
                'certified'  => $certifiedCount,
                'pending'    => $pendingProject,
            ],
            'content' => [
                'total'          => $totalContent,
                'pending'        => $pendingContent,
                'review_pass'    => $reviewPass,
                'review_total'   => $reviewAll,
                'review_pass_rate' => $reviewPassRate,
            ],
            'relation' => [
                'follow'   => $followTotal,
                'favorite' => $favTotal,
            ],
            'consult' => [
                'conversation' => $conversationTotal,
                'message'      => $messageTotal,
                'message_today' => $messageToday,
            ],
            'recommend' => [
                'total'          => $recTotal,
                'viewed'         => $recViewed,
                'responded'      => $recResponded,
                'view_rate'      => $recTotal > 0 ? round($recViewed / $recTotal * 100, 2) : 0.0,
                'response_rate'  => $recTotal > 0 ? round($recResponded / $recTotal * 100, 2) : 0.0,
                'relevant_rate'  => $recFeedback > 0 ? round($recRelevant / $recFeedback * 100, 2) : 0.0,
            ],
            'promotion' => [
                'campaign_active'  => $campaignActive,
                'campaign_pending' => $campaignPending,
                'exposure'         => $exposureTotal,
                'click'            => $clickTotal,
                'ctr'              => $exposureTotal > 0 ? round($clickTotal / $exposureTotal * 100, 2) : 0.0,
            ],
            'risk' => [
                'pending_report' => $pendingReport,
            ],
        ];
    }

    /**
     * 趋势数据（近 N 天）：注册、订单、内容、消息
     */
    public function trend(int $days = 14): array
    {
        $days = max(1, min($days, 90));
        $start = strtotime(date('Y-m-d')) - ($days - 1) * 86400;

        $categories = [];
        for ($i = 0; $i < $days; $i++) {
            $categories[] = date('m-d', $start + $i * 86400);
        }

        return [
            'category' => $categories,
            'series'   => [
                'user'    => $this->dailyCount('user', 'jointime', $start, $days),
                'order'   => $this->dailyCount('app_order', 'create_time', $start, $days, ['status' => 'paid']),
                'content' => $this->dailyCount('app_content', 'create_time', $start, $days),
                'message' => $this->dailyCount('app_message', 'create_time', $start, $days),
            ],
        ];
    }

    /**
     * 角色分布（四角色用户数）
     */
    public function roleDistribution(): array
    {
        $rows = Db::name('user')
            ->alias('u')
            ->join('user_group g', 'g.id = u.group_id')
            ->field('g.rules AS role_code, COUNT(*) AS num')
            ->group('g.rules')
            ->select()->toArray();

        $map = ['OWNER' => '项目方', 'LEADER' => '社区长', 'MEMBER' => '会员', 'VISITOR' => '游客'];
        $out = [];
        $known = 0;
        foreach ($rows as $r) {
            $code = in_array($r['role_code'], array_keys($map), true) ? $r['role_code'] : 'VISITOR';
            $out[$code] = ($out[$code] ?? 0) + (int)$r['num'];
            $known += (int)$r['num'];
        }
        foreach ($map as $code => $label) {
            $out[$code] = $out[$code] ?? 0;
        }
        $out['_labels'] = $map;
        return $out;
    }

    /**
     * 项目行业分布 Top
     */
    public function projectByCategory(int $limit = 10): array
    {
        return Db::name('app_project')
            ->alias('p')
            ->join('app_project_category c', 'p.category_id = c.id')
            ->whereNull('p.deletetime')
            ->where('c.status', 'normal')
            ->field('c.name AS name, COUNT(*) AS num')
            ->group('c.id, c.name')
            ->order('num', 'desc')
            ->limit($limit)
            ->select()->toArray();
    }

    /**
     * 订阅套餐分布
     */
    public function subscriptionByPlan(): array
    {
        return Db::name('app_subscription')
            ->whereIn('status', ['ACTIVE', 'GRACE'])
            ->field('plan_code AS name, COUNT(*) AS num')
            ->group('plan_code')
            ->select()->toArray();
    }

    /* ==================== 内部方法 ==================== */

    /**
     * 按天统计（避免 N 次查询：一次 group by 后补零）
     */
    protected function dailyCount(string $table, string $timeField, int $start, int $days, array $where = []): array
    {
        $end = $start + $days * 86400;
        $query = Db::name($table)->whereBetween($timeField, [$start, $end]);
        foreach ($where as $k => $v) {
            $query->where($k, $v);
        }
        $rows = $query->field("FROM_UNIXTIME({$timeField}, '%m-%d') AS d, COUNT(*) AS num")
            ->group('d')
            ->select()->toArray();

        $map = array_column($rows, 'num', 'd');
        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $key = date('m-d', $start + $i * 86400);
            $out[] = (int)($map[$key] ?? 0);
        }
        return $out;
    }

    /**
     * 条件计数去重（think-orm 的 group+count 语义与 MySQL 不同，单独处理）
     */
    protected function countDistinct(string $table, string $field, callable $filter): int
    {
        $query = Db::name($table);
        $filter($query);
        return count($query->distinct(true)->column($field));
    }
}
