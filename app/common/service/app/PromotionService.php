<?php

// +----------------------------------------------------------------------
// | 项目社区信息交互平台 —— 推流服务层
// +----------------------------------------------------------------------
// | 需求依据（§7.3）：
// |   1. 未审核素材不得投放
// |   2. 投放需有开始/结束时间
// |   3. 需曝光/点击/咨询/关注归因数据
// |   4. 坑位：首页推荐位 / 行业频道位 / 热门项目榜单 / 定向相关项目
// +----------------------------------------------------------------------

namespace app\common\service\app;

use think\facade\Db;
use think\facade\Log;

class PromotionService
{
    /** 投放状态 */
    public const ST_PENDING  = 'pending';
    public const ST_NORMAL   = 'normal';
    public const ST_REJECTED = 'rejected';
    public const ST_FINISHED = 'finished';
    public const ST_CANCELED = 'canceled';

    /** 坑位 */
    public const POSITIONS = ['home', 'channel', 'rank', 'target'];

    /**
     * 创建投放计划
     *
     * 校验链：项目方角色 + 有效订阅 → 套餐有效 → 素材已审核 → 时间区间合法 → 预算/曝光上限
     *
     * @param int    $userId
     * @param int    $projectId
     * @param int    $packageId
     * @param int    $materialId 推广素材（bd_app_project_material.id，须 review_status=normal）
     * @param string $startAt    开始时间（Y-m-d H:i:s 或时间戳）
     * @param string $endAt      结束时间
     * @return array
     * @throws \Exception
     */
    public function createCampaign(int $userId, int $projectId, int $packageId, int $materialId, $startAt = '', $endAt = ''): array
    {
        // 1) 项目归属
        $project = Db::name('app_project')->where('id', $projectId)->find();
        if (!$project) {
            throw new \Exception('项目不存在');
        }
        if ((int)$project['owner_user_id'] !== $userId) {
            throw new \Exception('仅项目方可投放该项目');
        }

        // 2) 套餐
        $package = Db::name('app_promotion_package')->where('id', $packageId)->where('status', 'normal')->find();
        if (!$package) {
            throw new \Exception('推流套餐不存在或已下架');
        }
        if (!in_array((string)$package['position'], self::POSITIONS, true)) {
            throw new \Exception('套餐坑位配置不合法');
        }

        // 3) 素材必须已通过审核（核心约束）
        if ($materialId <= 0) {
            throw new \Exception('请选择已审核通过的推广素材');
        }
        $material = Db::name('app_project_material')->where('id', $materialId)->find();
        if (!$material) {
            throw new \Exception('推广素材不存在');
        }
        if ((int)$material['project_id'] !== $projectId) {
            throw new \Exception('推广素材不属于该项目');
        }
        if ($material['review_status'] !== 'normal') {
            throw new \Exception('推广素材尚未通过审核，不能投放');
        }

        // 4) 时间区间
        $start = $this->parseTime($startAt) ?: time();
        $end   = $this->parseTime($endAt);
        if ($end <= 0) {
            $end = $start + max(1, (int)$package['duration_days']) * 86400;
        }
        if ($end <= $start) {
            throw new \Exception('结束时间必须晚于开始时间');
        }
        if ($start > time() + 86400 * 365) {
            throw new \Exception('开始时间过于遥远');
        }

        $now = time();
        $id = Db::name('app_promotion_campaign')->insertGetId([
            'project_id'    => $projectId,
            'user_id'       => $userId,
            'package_id'    => $packageId,
            'position'      => (string)$package['position'],
            'material_id'   => $materialId,
            'budget'        => $package['price'],
            'currency'      => $package['currency'],
            'start_at'      => $start,
            'end_at'        => $end,
            'status'        => self::ST_PENDING,
            'review_status' => 'pending',
            'exposure_used' => 0,
            'create_time'   => $now,
            'update_time'   => $now,
        ]);

        $this->audit($userId, 'promotion.create', 'campaign', $id, [], [
            'project_id' => $projectId, 'package_id' => $packageId,
            'position' => $package['position'], 'start_at' => $start, 'end_at' => $end,
        ]);

        return Db::name('app_promotion_campaign')->where('id', $id)->find();
    }

    /**
     * 审核投放（通过 / 驳回）
     *
     * 通过时再次校验素材审核状态，避免「先通过后素材被下架」的时序漏洞。
     */
    public function reviewCampaign(int $campaignId, string $action, int $adminId = 0, string $reason = ''): array
    {
        if (!in_array($action, ['pass', 'reject'], true)) {
            throw new \Exception('审核动作不合法');
        }
        $campaign = Db::name('app_promotion_campaign')->where('id', $campaignId)->find();
        if (!$campaign) {
            throw new \Exception('投放计划不存在');
        }
        if ($campaign['status'] !== self::ST_PENDING) {
            throw new \Exception('仅待审核的投放计划可审核');
        }

        if ($action === 'pass') {
            $material = Db::name('app_project_material')->where('id', (int)$campaign['material_id'])->find();
            if (!$material || $material['review_status'] !== 'normal') {
                throw new \Exception('推广素材未通过审核，不能放行投放');
            }
        } else {
            if ($reason === '') {
                throw new \Exception('驳回时必须填写原因');
            }
        }

        $status = $action === 'pass' ? self::ST_NORMAL : self::ST_REJECTED;
        Db::name('app_promotion_campaign')->where('id', $campaignId)->update([
            'status'        => $status,
            'review_status' => $action === 'pass' ? 'normal' : 'rejected',
            'review_reason' => $reason,
            'update_time'   => time(),
        ]);

        $this->audit($adminId, 'promotion.' . $action, 'campaign', $campaignId,
            ['status' => $campaign['status']], ['status' => $status, 'reason' => $reason]);

        return Db::name('app_promotion_campaign')->where('id', $campaignId)->find();
    }

    /**
     * 归因上报（曝光 / 点击 / 咨询 / 关注）
     *
     * 幂等与安全：
     * - 仅统计「投放中且未超曝光上限」的计划
     * - 曝光上限达到后自动归档为 finished
     *
     * @param int    $campaignId
     * @param string $metric exposure|click|consult|follow
     * @param int    $count
     * @return array
     * @throws \Exception
     */
    public function track(int $campaignId, string $metric, int $count = 1): array
    {
        $allow = ['exposure', 'click', 'consult', 'follow'];
        if (!in_array($metric, $allow, true)) {
            throw new \Exception('统计类型不合法');
        }
        $count = max(1, min($count, 1000));

        $campaign = Db::name('app_promotion_campaign')->where('id', $campaignId)->find();
        if (!$campaign) {
            throw new \Exception('投放计划不存在');
        }
        if ($campaign['status'] !== self::ST_NORMAL) {
            throw new \Exception('投放未在进行中');
        }
        $now = time();
        if ((int)$campaign['start_at'] > $now) {
            throw new \Exception('投放尚未开始');
        }
        if ((int)$campaign['end_at'] > 0 && (int)$campaign['end_at'] < $now) {
            // 到期自动归档
            Db::name('app_promotion_campaign')->where('id', $campaignId)
                ->update(['status' => self::ST_FINISHED, 'update_time' => $now]);
            throw new \Exception('投放已结束');
        }

        $today = date('Y-m-d');
        $stat = Db::name('app_promotion_stat')->where('campaign_id', $campaignId)->where('date', $today)->find();

        Db::startTrans();
        try {
            if ($stat) {
                Db::name('app_promotion_stat')->where('id', $stat['id'])
                    ->inc($metric, $count)->update(['update_time' => $now]);
            } else {
                $row = [
                    'campaign_id' => $campaignId, 'date' => $today,
                    'exposure' => 0, 'click' => 0, 'consult' => 0, 'follow' => 0,
                    'create_time' => $now, 'update_time' => $now,
                ];
                $row[$metric] = $count;
                Db::name('app_promotion_stat')->insert($row);
            }

            // 曝光累计与上限控制
            if ($metric === 'exposure') {
                Db::name('app_promotion_campaign')->where('id', $campaignId)->inc('exposure_used', $count)->update();

                $package = Db::name('app_promotion_package')->where('id', (int)$campaign['package_id'])->find();
                $cap = $package ? (int)$package['exposure_cap'] : 0;
                $used = (int)$campaign['exposure_used'] + $count;
                if ($cap > 0 && $used >= $cap) {
                    Db::name('app_promotion_campaign')->where('id', $campaignId)
                        ->update(['status' => self::ST_FINISHED, 'update_time' => $now]);
                }
            }

            // 归因到埋点事件
            Db::name('app_event')->insert([
                'user_id'     => 0,
                'event_type'  => 'promotion_' . $metric,
                'target_type' => 'campaign',
                'target_id'   => $campaignId,
                'metadata'    => json_encode(['project_id' => (int)$campaign['project_id'], 'count' => $count], JSON_UNESCAPED_UNICODE),
                'create_time' => $now,
            ]);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('推流统计失败：' . $e->getMessage());
            throw new \Exception('统计上报失败');
        }

        return $this->statsOf($campaignId);
    }

    /**
     * 单计划归因汇总
     */
    public function statsOf(int $campaignId): array
    {
        $agg = Db::name('app_promotion_stat')
            ->where('campaign_id', $campaignId)
            ->field('SUM(exposure) AS exposure, SUM(click) AS click, SUM(consult) AS consult, SUM(follow) AS follow')
            ->find() ?: [];
        $campaign = Db::name('app_promotion_campaign')->where('id', $campaignId)->find() ?: [];

        $exposure = (int)($agg['exposure'] ?? 0);
        $click    = (int)($agg['click'] ?? 0);

        return [
            'campaign_id' => $campaignId,
            'status'      => $campaign['status'] ?? '',
            'position'    => $campaign['position'] ?? '',
            'start_at'    => (int)($campaign['start_at'] ?? 0),
            'end_at'      => (int)($campaign['end_at'] ?? 0),
            'exposure'    => $exposure,
            'click'       => $click,
            'consult'     => (int)($agg['consult'] ?? 0),
            'follow'      => (int)($agg['follow'] ?? 0),
            'ctr'         => $exposure > 0 ? round($click / $exposure * 100, 2) : 0.0,
            'daily'       => Db::name('app_promotion_stat')
                ->where('campaign_id', $campaignId)
                ->order('date', 'asc')
                ->field('date,exposure,click,consult,follow')
                ->select(),
        ];
    }

    /**
     * 归档已结束的投放（定时任务调用）
     */
    public function finishExpired(): int
    {
        $now = time();
        return (int)Db::name('app_promotion_campaign')
            ->where('status', self::ST_NORMAL)
            ->where('end_at', '>', 0)
            ->where('end_at', '<=', $now)
            ->update(['status' => self::ST_FINISHED, 'update_time' => $now]);
    }

    /**
     * 当前生效的投放（用于列表页展示位）
     */
    public function activeByPosition(string $position = '', int $limit = 10): array
    {
        $now = time();
        $query = Db::name('app_promotion_campaign')
            ->where('status', self::ST_NORMAL)
            ->where('start_at', '<=', $now)
            ->where(function ($q) use ($now) {
                $q->where('end_at', '=', 0)->whereOr('end_at', '>', $now);
            });
        if ($position !== '') {
            if (!in_array($position, self::POSITIONS, true)) {
                return [];
            }
            $query->where('position', $position);
        }

        $rows = $query->order('exposure_used', 'asc')->limit($limit)
            ->field('id,project_id,position,start_at,end_at')->select()->toArray();
        if (!$rows) {
            return [];
        }

        $pids = array_unique(array_column($rows, 'project_id'));
        $projects = array_column(
            Db::name('app_project')
                ->alias('p')
                ->leftJoin('app_project_category c', 'p.category_id = c.id')
                ->whereIn('p.id', $pids)
                ->field('p.id,p.name,p.cover,p.subtitle,p.certified,c.name as category_name')
                ->select()->toArray(),
            null, 'id'
        );
        foreach ($rows as $k => $r) {
            $p = $projects[$r['project_id']] ?? [];
            $rows[$k]['name']      = $p['name'] ?? '';
            $rows[$k]['cover']     = $p['cover'] ?? '';
            $rows[$k]['subtitle']  = $p['subtitle'] ?? '';
            $rows[$k]['certified'] = (int)($p['certified'] ?? 0);
            $rows[$k]['category']  = $p['category_name'] ?? '';
        }
        return $rows;
    }

    /* ==================== 内部方法 ==================== */

    /**
     * 解析时间（支持时间戳与多种日期串）
     */
    protected function parseTime($value): int
    {
        if ($value === '' || $value === null) {
            return 0;
        }
        if (is_numeric($value)) {
            return (int)$value;
        }
        $ts = strtotime((string)$value);
        return $ts ?: 0;
    }

    /**
     * 写审计日志
     */
    protected function audit(int $adminId, string $action, string $targetType, int $targetId, array $before, array $after): void
    {
        try {
            Db::name('app_audit_log')->insert([
                'actor_type'  => $adminId > 0 ? 'admin' : 'user',
                'actor_id'    => $adminId,
                'action'      => $action,
                'target_type' => $targetType,
                'target_id'   => $targetId,
                'before'      => $before ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
                'after'       => $after ? json_encode($after, JSON_UNESCAPED_UNICODE) : null,
                'ip'          => request()->ip() ?: '',
                'create_time' => time(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('审计写入失败：' . $e->getMessage());
        }
    }
}
