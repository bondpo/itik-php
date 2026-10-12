<?php

// +----------------------------------------------------------------------
// | API v1 —— 关系（关注 / 收藏 / 拉黑 / 举报）
// +----------------------------------------------------------------------

namespace app\api\controller\v1;

use think\facade\Db;

/**
 * 关系接口
 *
 * 路由：
 *   POST   /api/v1/relations/follow            关注（用户/项目）
 *   POST   /api/v1/relations/unfollow          取消关注
 *   GET    /api/v1/relations/following         我关注的
 *   GET    /api/v1/relations/followers         关注我的
 *   POST   /api/v1/relations/favorite          收藏
 *   POST   /api/v1/relations/unfavorite        取消收藏
 *   GET    /api/v1/relations/favorites         我的收藏
 *   POST   /api/v1/relations/block             拉黑
 *   POST   /api/v1/relations/unblock           解除拉黑
 *   GET    /api/v1/relations/blocks            黑名单
 *   POST   /api/v1/relations/reports           举报
 */
class Relation extends Base
{
    protected $noNeedLogin = [];
    protected $noNeedRight = '*';

    /** 关注目标类型 */
    protected const FOLLOW_TYPES = ['user', 'project'];
    /** 举报分类 */
    protected const REPORT_CATEGORIES = ['fraud', 'false_content', 'harass', 'illegal', 'other'];

    /**
     * 关注
     */
    public function follow()
    {
        $type = (string)$this->request->post('target_type', '');
        $id   = (int)$this->request->post('target_id', 0);

        $this->validateTarget($type, $id);
        if ($type === 'user' && $id === $this->userId()) {
            $this->fail('不能关注自己', self::CODE_PARAM_INVALID);
        }
        if ($this->isBlocked($id, $type)) {
            $this->fail('对方已将你拉黑，无法关注', self::CODE_FORBIDDEN);
        }

        $exists = Db::name('app_follow')
            ->where('follower_id', $this->userId())
            ->where('followee_type', $type)
            ->where('followee_id', $id)
            ->find();
        if ($exists) {
            if ($exists['status'] === 'normal') {
                // 幂等：已关注直接成功返回
                $this->success('已关注', ['repeated' => true, 'follows' => $this->followCount($type, $id)]);
            }
            Db::name('app_follow')->where('id', $exists['id'])->update(['status' => 'normal', 'update_time' => time()]);
        } else {
            Db::name('app_follow')->insert([
                'follower_id'   => $this->userId(),
                'followee_type' => $type,
                'followee_id'   => $id,
                'status'        => 'normal',
                'create_time'   => time(),
                'update_time'   => time(),
            ]);
        }

        // 项目关注数冗余维护
        if ($type === 'project') {
            Db::name('app_project')->where('id', $id)->inc('follows')->update();
        }

        Db::name('app_event')->insert([
            'user_id' => $this->userId(), 'event_type' => 'follow',
            'target_type' => $type, 'target_id' => $id,
            'ip' => $this->request->ip(), 'create_time' => time(),
        ]);

        $this->success('关注成功', ['follows' => $this->followCount($type, $id)]);
    }

    /**
     * 取消关注
     */
    public function unfollow()
    {
        $type = (string)$this->request->post('target_type', '');
        $id   = (int)$this->request->post('target_id', 0);
        $this->validateTarget($type, $id);

        $row = Db::name('app_follow')
            ->where('follower_id', $this->userId())
            ->where('followee_type', $type)
            ->where('followee_id', $id)
            ->where('status', 'normal')
            ->find();
        if (!$row) {
            $this->success('未关注', ['follows' => $this->followCount($type, $id)]);
        }

        Db::name('app_follow')->where('id', $row['id'])->update(['status' => 'cancel', 'update_time' => time()]);
        if ($type === 'project') {
            Db::name('app_project')->where('id', $id)->where('follows', '>', 0)->dec('follows')->update();
        }

        $this->success('已取消关注', ['follows' => $this->followCount($type, $id)]);
    }

    /**
     * 我关注的
     */
    public function following()
    {
        [$page, $limit] = $this->pageParams();
        $type = (string)$this->request->param('target_type', '');

        $query = Db::name('app_follow')
            ->where('follower_id', $this->userId())
            ->where('status', 'normal');
        if ($type !== '') {
            if (!in_array($type, self::FOLLOW_TYPES, true)) {
                $this->fail('目标类型不合法', self::CODE_PARAM_INVALID);
            }
            $query->where('followee_type', $type);
        }

        $total = (clone $query)->count();
        $rows = $query->order('id', 'desc')->page($page, $limit)
            ->field('id,followee_type,followee_id,create_time')->select();

        $list = $this->decorateTargets($rows->toArray());

        $this->success('', $this->pageResult($list, $total, $page, $limit));
    }

    /**
     * 关注我的
     */
    public function followers()
    {
        [$page, $limit] = $this->pageParams();

        $query = Db::name('app_follow')
            ->where('followee_type', 'user')
            ->where('followee_id', $this->userId())
            ->where('status', 'normal');

        $total = (clone $query)->count();
        $rows = $query->order('id', 'desc')->page($page, $limit)
            ->field('id,follower_id,create_time')->select();

        $uids = array_column($rows->toArray(), 'follower_id');
        $users = $uids ? Db::name('user')->whereIn('id', $uids)->field('id,nickname,avatar')->select()->toArray() : [];
        $users = array_column($users, null, 'id');
        $list = [];
        foreach ($rows as $r) {
            $list[] = [
                'id'         => (int)$r['id'],
                'user_id'    => (int)$r['follower_id'],
                'nickname'   => $users[$r['follower_id']]['nickname'] ?? '',
                'avatar'     => $users[$r['follower_id']]['avatar'] ?? '',
                'create_time' => (int)$r['create_time'],
            ];
        }

        $this->success('', $this->pageResult($list, $total, $page, $limit));
    }

    /**
     * 收藏
     */
    public function favorite()
    {
        $type = (string)$this->request->post('target_type', 'project');
        $id   = (int)$this->request->post('target_id', 0);
        $this->validateTarget($type, $id);

        $exists = Db::name('app_favorite')
            ->where('user_id', $this->userId())
            ->where('target_type', $type)
            ->where('target_id', $id)
            ->find();
        if ($exists) {
            $this->success('已收藏', ['repeated' => true]);
        }

        Db::name('app_favorite')->insert([
            'user_id'     => $this->userId(),
            'target_type' => $type,
            'target_id'   => $id,
            'create_time' => time(),
        ]);

        $this->success('收藏成功');
    }

    /**
     * 取消收藏
     */
    public function unfavorite()
    {
        $type = (string)$this->request->post('target_type', 'project');
        $id   = (int)$this->request->post('target_id', 0);
        $this->validateTarget($type, $id);

        Db::name('app_favorite')
            ->where('user_id', $this->userId())
            ->where('target_type', $type)
            ->where('target_id', $id)
            ->delete();

        $this->success('已取消收藏');
    }

    /**
     * 我的收藏
     */
    public function favorites()
    {
        [$page, $limit] = $this->pageParams();

        $query = Db::name('app_favorite')->where('user_id', $this->userId());
        $type = (string)$this->request->param('target_type', '');
        if ($type !== '') {
            $query->where('target_type', $type);
        }

        $total = (clone $query)->count();
        $rows = $query->order('id', 'desc')->page($page, $limit)
            ->field('id,target_type,target_id,create_time')->select();

        $list = $this->decorateTargets($rows->toArray());

        $this->success('', $this->pageResult($list, $total, $page, $limit));
    }

    /**
     * 拉黑
     */
    public function block()
    {
        $userId = (int)$this->request->post('user_id', 0);
        if ($userId <= 0) {
            $this->fail('缺少用户 ID', self::CODE_PARAM_MISSING);
        }
        if ($userId === $this->userId()) {
            $this->fail('不能拉黑自己', self::CODE_PARAM_INVALID);
        }
        if (!Db::name('user')->where('id', $userId)->find()) {
            $this->fail('用户不存在', self::CODE_NOT_FOUND);
        }

        $exists = Db::name('app_block')->where('user_id', $this->userId())->where('blocked_user_id', $userId)->find();
        if ($exists) {
            $this->success('已在黑名单', ['repeated' => true]);
        }

        Db::name('app_block')->insert([
            'user_id'         => $this->userId(),
            'blocked_user_id' => $userId,
            'reason'          => (string)$this->request->post('reason', ''),
            'create_time'     => time(),
        ]);

        $this->success('已拉黑');
    }

    /**
     * 解除拉黑
     */
    public function unblock()
    {
        $userId = (int)$this->request->post('user_id', 0);
        if ($userId <= 0) {
            $this->fail('缺少用户 ID', self::CODE_PARAM_MISSING);
        }
        Db::name('app_block')->where('user_id', $this->userId())->where('blocked_user_id', $userId)->delete();
        $this->success('已解除拉黑');
    }

    /**
     * 黑名单
     */
    public function blocks()
    {
        [$page, $limit] = $this->pageParams();
        $query = Db::name('app_block')->where('user_id', $this->userId());
        $total = (clone $query)->count();
        $rows = $query->order('id', 'desc')->page($page, $limit)->field('id,blocked_user_id,reason,create_time')->select();

        $uids = array_column($rows->toArray(), 'blocked_user_id');
        $users = $uids ? Db::name('user')->whereIn('id', $uids)->field('id,nickname,avatar')->select()->toArray() : [];
        $users = array_column($users, null, 'id');
        $list = [];
        foreach ($rows as $r) {
            $list[] = [
                'id'          => (int)$r['id'],
                'user_id'     => (int)$r['blocked_user_id'],
                'nickname'    => $users[$r['blocked_user_id']]['nickname'] ?? '',
                'avatar'      => $users[$r['blocked_user_id']]['avatar'] ?? '',
                'reason'      => $r['reason'],
                'create_time' => (int)$r['create_time'],
            ];
        }

        $this->success('', $this->pageResult($list, $total, $page, $limit));
    }

    /**
     * 举报
     */
    public function reports()
    {
        $type     = (string)$this->request->post('target_type', '');
        $id       = (int)$this->request->post('target_id', 0);
        $category = (string)$this->request->post('category', '');
        $remark   = trim((string)$this->request->post('remark', ''));
        $evidence = trim((string)$this->request->post('evidence', ''));

        if (!in_array($type, ['user', 'project', 'content'], true)) {
            $this->fail('举报对象类型不合法', self::CODE_PARAM_INVALID);
        }
        if (!in_array($category, self::REPORT_CATEGORIES, true)) {
            $this->fail('举报分类不合法', self::CODE_PARAM_INVALID);
        }
        if ($id <= 0) {
            $this->fail('缺少举报对象 ID', self::CODE_PARAM_MISSING);
        }
        if (mb_strlen($remark) > 500) {
            $this->fail('补充说明过长（最多 500 字）', self::CODE_PARAM_INVALID);
        }

        // 防重复：同一用户对同一对象的待处理举报只允许一条
        $exists = Db::name('app_report')
            ->where('reporter_id', $this->userId())
            ->where('target_type', $type)->where('target_id', $id)
            ->whereIn('status', ['pending', 'processing'])
            ->find();
        if ($exists) {
            $this->fail('你已提交过举报，平台正在处理中', self::CODE_DUPLICATE, ['report_id' => (int)$exists['id']]);
        }

        $rid = Db::name('app_report')->insertGetId([
            'reporter_id' => $this->userId(),
            'target_type' => $type,
            'target_id'   => $id,
            'category'    => $category,
            'remark'      => $remark,
            'evidence'    => $evidence,
            'status'      => 'pending',
            'create_time' => time(),
            'update_time' => time(),
        ]);

        Db::name('app_event')->insert([
            'user_id' => $this->userId(), 'event_type' => 'report',
            'target_type' => $type, 'target_id' => $id,
            'ip' => $this->request->ip(), 'create_time' => time(),
        ]);

        $this->success('举报已提交，我们会尽快处理', ['report_id' => $rid]);
    }

    /* ==================== 内部方法 ==================== */

    /**
     * 校验关注/收藏目标是否存在
     */
    protected function validateTarget(string $type, int $id): void
    {
        if (!in_array($type, self::FOLLOW_TYPES, true)) {
            $this->fail('目标类型不合法', self::CODE_PARAM_INVALID);
        }
        if ($id <= 0) {
            $this->fail('缺少目标 ID', self::CODE_PARAM_MISSING);
        }
        if ($type === 'user') {
            if (!Db::name('user')->where('id', $id)->find()) {
                $this->fail('用户不存在', self::CODE_NOT_FOUND);
            }
        } else {
            if (!Db::name('app_project')->where('id', $id)->find()) {
                $this->fail('项目不存在', self::CODE_NOT_FOUND);
            }
        }
    }

    /**
     * 是否被对方拉黑
     */
    protected function isBlocked(int $targetId, string $type): bool
    {
        if ($type !== 'user' || $targetId <= 0) {
            return false;
        }
        return (bool)Db::name('app_block')
            ->where('user_id', $targetId)
            ->where('blocked_user_id', $this->userId())
            ->find();
    }

    /**
     * 关注数（用户维度实时统计，项目维度读冗余字段）
     */
    protected function followCount(string $type, int $id): int
    {
        if ($type === 'project') {
            return (int)Db::name('app_project')->where('id', $id)->value('follows');
        }
        return (int)Db::name('app_follow')
            ->where('followee_type', 'user')->where('followee_id', $id)->where('status', 'normal')->count();
    }

    /**
     * 为关注/收藏列表补充目标信息
     */
    protected function decorateTargets(array $rows): array
    {
        if (!$rows) {
            return [];
        }
        $userIds = [];
        $projectIds = [];
        foreach ($rows as $r) {
            if (($r['followee_type'] ?? $r['target_type'] ?? '') === 'user') {
                $userIds[] = $r['followee_id'] ?? $r['target_id'];
            } elseif (($r['followee_type'] ?? $r['target_type'] ?? '') === 'project') {
                $projectIds[] = $r['followee_id'] ?? $r['target_id'];
            }
        }
        if ($userIds) {
            $u = Db::name('user')->whereIn('id', array_unique($userIds))->field('id,nickname,avatar')->select()->toArray();
            $users = array_column($u, null, 'id');
        } else {
            $users = [];
        }
        if ($projectIds) {
            $pj = Db::name('app_project')->whereIn('id', array_unique($projectIds))->field('id,name,cover,certified,status')->select()->toArray();
            $projects = array_column($pj, null, 'id');
        } else {
            $projects = [];
        }

        $list = [];
        foreach ($rows as $r) {
            $type = $r['followee_type'] ?? $r['target_type'];
            $tid  = (int)($r['followee_id'] ?? $r['target_id']);
            $item = [
                'id'          => (int)$r['id'],
                'target_type' => $type,
                'target_id'   => $tid,
                'create_time' => (int)$r['create_time'],
            ];
            if ($type === 'user') {
                $item['title']  = $users[$tid]['nickname'] ?? '';
                $item['cover']  = $users[$tid]['avatar'] ?? '';
                $item['certified'] = 0;
            } else {
                $item['title']  = $projects[$tid]['name'] ?? '';
                $item['cover']  = $projects[$tid]['cover'] ?? '';
                $item['certified'] = (int)($projects[$tid]['certified'] ?? 0);
                $item['status'] = $projects[$tid]['status'] ?? '';
            }
            $list[] = $item;
        }
        return $list;
    }
}
