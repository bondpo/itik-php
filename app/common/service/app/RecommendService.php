<?php

// +----------------------------------------------------------------------
// | 项目社区信息交互平台 —— 推荐与配额服务层
// +----------------------------------------------------------------------
// | 需求依据：
// |   §7.1 项目方「适配人脉」：按行业/标签/地区/行为/活跃度计算；不限量但需频控与去重
// |   §7.2 社区长「每日 5 个会员」：当日最多 5 人、同人不重复、后台有推荐日志
// | 实现要点：加权打分 + 当日去重（唯一索引）+ 配额表计数 + 理由可展示
// +----------------------------------------------------------------------

namespace app\common\service\app;

use think\facade\Db;
use think\facade\Log;

class RecommendService
{
    /** 推荐渠道 */
    public const CHANNEL_OWNER_MATCH  = 'owner_match';   // 项目方 → 匹配人脉
    public const CHANNEL_LEADER_MEMBER = 'leader_member'; // 社区长 → 匹配会员

    /**
     * 获取推荐配额上限
     * @param string $channel
     * @return int 0 表示不限
     */
    public function quotaLimit(string $channel): int
    {
        $name = $channel === self::CHANNEL_LEADER_MEMBER
            ? 'recommend_leader_daily_limit'
            : 'recommend_owner_daily_limit';
        $val = Db::name('config')->where('group', 'app')->where('name', $name)->value('value');
        return max(0, (int)$val);
    }

    /**
     * 今日已用配额
     */
    public function quotaUsed(int $userId, string $channel): int
    {
        $row = Db::name('app_recommend_quota')
            ->where('user_id', $userId)
            ->where('date', date('Y-m-d'))
            ->where('channel', $channel)
            ->find();
        return $row ? (int)$row['used'] : 0;
    }

    /**
     * 剩余配额（-1 表示不限）
     */
    public function quotaRemain(int $userId, string $channel): int
    {
        $limit = $this->quotaLimit($channel);
        if ($limit === 0) {
            return -1;
        }
        return max(0, $limit - $this->quotaUsed($userId, $channel));
    }

    /**
     * 读取推荐权重配置
     */
    protected function weights(): array
    {
        $raw = Db::name('config')->where('group', 'app')->where('name', 'recommend_weights')->value('value');
        $defaults = ['paid_level' => 30, 'content_level' => 20, 'interaction' => 15, 'region' => 10, 'activity' => 10];
        $parsed = json_decode((string)$raw, true);
        return is_array($parsed) ? array_merge($defaults, $parsed) : $defaults;
    }

    /**
     * 为指定用户生成推荐（幂等：当日同目标不重复）
     *
     * @param int    $userId  接收推荐的人
     * @param string $channel owner_match / leader_member
     * @param int    $want    期望条数（0 = 用配额上限；上限为 0 时默认 20）
     * @return array{generated:int,skipped_quota:int,candidates:int,channel:string,remain:int}
     */
    public function generate(int $userId, string $channel = self::CHANNEL_LEADER_MEMBER, int $want = 0): array
    {
        $limit  = $this->quotaLimit($channel);
        $used   = $this->quotaUsed($userId, $channel);
        $remain = $limit === 0 ? -1 : max(0, $limit - $used);

        if ($limit > 0 && $remain <= 0) {
            return ['generated' => 0, 'skipped_quota' => 0, 'candidates' => 0,
                    'channel' => $channel, 'remain' => 0, 'message' => '今日推荐配额已用完'];
        }

        // 本次可生成条数
        $target = $want > 0 ? $want : ($limit > 0 ? $remain : 20);
        if ($limit > 0) {
            $target = min($target, $remain);
        }
        if ($target <= 0) {
            return ['generated' => 0, 'skipped_quota' => 0, 'candidates' => 0,
                    'channel' => $channel, 'remain' => $remain, 'message' => '无可生成条数'];
        }

        $candidates = $this->candidates($userId, $channel, $target * 3);
        if (!$candidates) {
            return ['generated' => 0, 'skipped_quota' => 0, 'candidates' => 0,
                    'channel' => $channel, 'remain' => $remain, 'message' => '暂无可推荐对象'];
        }

        $today = date('Y-m-d');
        $now = time();
        $generated = 0;
        $skipped = 0;

        foreach ($candidates as $cand) {
            if ($generated >= $target) {
                break;
            }
            $exists = Db::name('app_recommendation')
                ->where('receiver_id', $userId)
                ->where('target_type', $cand['target_type'])
                ->where('target_id', $cand['target_id'])
                ->where('batch_date', $today)
                ->find();
            if ($exists) {
                $skipped++;
                continue; // 当日去重
            }

            try {
                Db::name('app_recommendation')->insert([
                    'receiver_id' => $userId,
                    'target_type' => $cand['target_type'],
                    'target_id'   => $cand['target_id'],
                    'score'       => $cand['score'],
                    'reason'      => $cand['reason'],
                    'channel'     => $channel,
                    'batch_date'  => $today,
                    'create_time' => $now,
                ]);
                $generated++;
            } catch (\Throwable $e) {
                // 唯一索引冲突（并发场景）视为已推荐
                $skipped++;
                Log::info('推荐去重命中：' . $e->getMessage());
            }
        }

        // 累加配额（仅计新增）
        if ($generated > 0) {
            $this->addQuota($userId, $channel, $generated);
        }

        return [
            'generated'  => $generated,
            'skipped_quota' => $skipped,
            'candidates' => count($candidates),
            'channel'    => $channel,
            'remain'     => $limit === 0 ? -1 : max(0, $limit - ($used + $generated)),
        ];
    }

    /**
     * 推荐列表（接收方视角）
     */
    public function listFor(int $userId, string $channel = '', int $page = 1, int $limit = 20): array
    {
        $query = Db::name('app_recommendation')->where('receiver_id', $userId);
        if ($channel !== '') {
            $query->where('channel', $channel);
        }
        $total = (clone $query)->count();
        $rows = $query->order('score', 'desc')->order('id', 'desc')
            ->page($page, $limit)
            ->field('id,target_type,target_id,score,reason,channel,batch_date,is_viewed,is_responded,feedback,create_time')
            ->select()->toArray();

        return ['list' => $this->decorate($rows), 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    /**
     * 标记已查看 / 已响应
     */
    public function mark(int $userId, int $id, string $field = 'viewed'): bool
    {
        $rec = Db::name('app_recommendation')->where('id', $id)->where('receiver_id', $userId)->find();
        if (!$rec) {
            return false;
        }
        $data = $field === 'responded'
            ? ['is_responded' => 1]
            : ['is_viewed' => 1];
        Db::name('app_recommendation')->where('id', $id)->update($data);
        return true;
    }

    /**
     * 反馈（相关 / 不相关），用于后续权重迭代
     */
    public function feedback(int $userId, int $id, string $feedback): bool
    {
        if (!in_array($feedback, ['relevant', 'irrelevant'], true)) {
            return false;
        }
        $rec = Db::name('app_recommendation')->where('id', $id)->where('receiver_id', $userId)->find();
        if (!$rec) {
            return false;
        }
        Db::name('app_recommendation')->where('id', $id)->update([
            'feedback'     => $feedback,
            'is_viewed'    => 1,
            'is_responded' => 1,
        ]);

        Db::name('app_event')->insert([
            'user_id'     => $userId,
            'event_type'  => 'recommend_feedback',
            'target_type' => $rec['target_type'],
            'target_id'   => (int)$rec['target_id'],
            'metadata'    => json_encode(['feedback' => $feedback, 'rec_id' => $id], JSON_UNESCAPED_UNICODE),
            'create_time' => time(),
        ]);
        return true;
    }

    /* ==================== 内部方法 ==================== */

    /**
     * 候选对象打分
     *
     * leader_member：社区长 → 会员（按会员等级/活跃度/地区）
     * owner_match  ：项目方 → 会员或社区长（按兴趣标签/地区/活跃度）
     */
    protected function candidates(int $userId, string $channel, int $limit): array
    {
        $w = $this->weights();

        // 已推荐过的对象（历史，避免重复打扰）
        $recommended = Db::name('app_recommendation')
            ->where('receiver_id', $userId)
            ->column('target_id');
        // 已关注的对象
        $followed = Db::name('app_follow')
            ->where('follower_id', $userId)
            ->where('followee_type', 'user')
            ->where('status', 'normal')
            ->column('followee_id');
        // 已被拉黑 / 拉黑我的人
        $blocked = Db::name('app_block')->where('user_id', $userId)->column('blocked_user_id');
        $blockedBy = Db::name('app_block')->where('blocked_user_id', $userId)->column('user_id');

        $exclude = array_unique(array_merge([$userId], $recommended, $followed, $blocked, $blockedBy));

        $query = Db::name('user')->where('status', 'normal');
        if ($exclude) {
            $query->whereNotIn('id', $exclude);
        }

        if ($channel === self::CHANNEL_LEADER_MEMBER) {
            // 会员：仅取会员角色组及其它非游客账号
            $memberGroupId = (int)(Db::name('user_group')->where('rules', 'MEMBER')->value('id') ?: 0);
            $visitorGroupId = (int)(Db::name('user_group')->where('rules', 'VISITOR')->value('id') ?: 0);
            $gids = array_filter([$memberGroupId, $visitorGroupId]);
            if ($gids) {
                $query->whereIn('group_id', $gids);
            }
        }

        $rows = $query->limit(max($limit, 10))
            ->field('id,nickname,avatar,level,score,logintime,jointime,group_id')
            ->select()->toArray();
        if (!$rows) {
            return [];
        }

        // 接收方地区（用于地区权重）
        $myRegion = (string)Db::name('app_identity')->where('user_id', $userId)->value('identifier');
        $now = time();

        $out = [];
        foreach ($rows as $u) {
            $score = 0;
            $reasons = [];

            // 会员等级（付费等级权重）
            $level = (int)$u['level'];
            if ($level > 0) {
                $score += min($w['paid_level'], $level * 5);
                $reasons[] = "会员等级 L{$level}";
            }

            // 活跃度（最近登录）
            $loginDays = (int)$u['logintime'] > 0 ? floor(($now - (int)$u['logintime']) / 86400) : 999;
            if ($loginDays <= 1) {
                $score += $w['activity'];
                $reasons[] = '近 24 小时活跃';
            } elseif ($loginDays <= 7) {
                $score += (int)round($w['activity'] / 2);
                $reasons[] = '近一周活跃';
            }

            // 内容等级（是否有内容产出）
            $contentCount = Db::name('app_content')->where('author_id', (int)$u['id'])->where('status', 'normal')->count();
            if ($contentCount > 0) {
                $score += min($w['content_level'], $contentCount * 3);
                $reasons[] = "已发布 {$contentCount} 条内容";
            }

            // 互动情况（关注数 / 粉丝数）
            $follows = Db::name('app_follow')->where('follower_id', (int)$u['id'])->where('status', 'normal')->count();
            if ($follows > 0) {
                $score += min($w['interaction'], $follows * 2);
                $reasons[] = "关注 {$follows} 人";
            }

            // 注册时长（老用户略加分，降低新号骚扰风险）
            $joinDays = (int)$u['jointime'] > 0 ? floor(($now - (int)$u['jointime']) / 86400) : 0;
            if ($joinDays >= 30) {
                $reasons[] = '注册超过 30 天';
            }

            $out[] = [
                'target_type' => 'user',
                'target_id'   => (int)$u['id'],
                'score'       => round($score, 4),
                'reason'      => $reasons ? implode('；', array_slice($reasons, 0, 3)) : '系统匹配',
            ];
        }

        usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($out, 0, max($limit, 10));
    }

    /**
     * 累加当日配额
     */
    protected function addQuota(int $userId, string $channel, int $count): void
    {
        $today = date('Y-m-d');
        $now = time();
        $row = Db::name('app_recommend_quota')
            ->where('user_id', $userId)->where('date', $today)->where('channel', $channel)->find();
        if ($row) {
            Db::name('app_recommend_quota')->where('id', $row['id'])
                ->inc('used', $count)->update(['update_time' => $now]);
            return;
        }
        try {
            Db::name('app_recommend_quota')->insert([
                'user_id' => $userId, 'date' => $today, 'channel' => $channel,
                'used' => $count, 'create_time' => $now, 'update_time' => $now,
            ]);
        } catch (\Throwable $e) {
            // 并发下唯一索引冲突，转为累加
            Db::name('app_recommend_quota')
                ->where('user_id', $userId)->where('date', $today)->where('channel', $channel)
                ->inc('used', $count)->update(['update_time' => $now]);
        }
    }

    /**
     * 为推荐记录补全目标信息（用户/项目）
     */
    protected function decorate(array $rows): array
    {
        if (!$rows) {
            return [];
        }
        $uids = [];
        $pids = [];
        foreach ($rows as $r) {
            if ($r['target_type'] === 'user') {
                $uids[] = (int)$r['target_id'];
            } else {
                $pids[] = (int)$r['target_id'];
            }
        }
        $users = $uids
            ? array_column(Db::name('user')->whereIn('id', array_unique($uids))->field('id,nickname,avatar,level,bio')->select()->toArray(), null, 'id')
            : [];
        $projects = $pids
            ? array_column(
                Db::name('app_project')
                    ->alias('p')
                    ->leftJoin('app_project_category c', 'p.category_id = c.id')
                    ->whereIn('p.id', array_unique($pids))
                    ->field('p.id,p.name,p.cover,p.certified,c.name as category_name')
                    ->select()->toArray(),
                null, 'id'
            )
            : [];

        foreach ($rows as $k => $r) {
            $tid = (int)$r['target_id'];
            if ($r['target_type'] === 'user') {
                $rows[$k]['title']     = $users[$tid]['nickname'] ?? '';
                $rows[$k]['cover']     = $users[$tid]['avatar'] ?? '';
                $rows[$k]['bio']       = $users[$tid]['bio'] ?? '';
                $rows[$k]['certified'] = 0;
            } else {
                $rows[$k]['title']     = $projects[$tid]['name'] ?? '';
                $rows[$k]['cover']     = $projects[$tid]['cover'] ?? '';
                $rows[$k]['bio']       = $projects[$tid]['category_name'] ?? '';
                $rows[$k]['certified'] = (int)($projects[$tid]['certified'] ?? 0);
            }
            $rows[$k]['score'] = (float)$r['score'];
        }
        return $rows;
    }
}
