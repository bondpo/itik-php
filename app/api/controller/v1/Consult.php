<?php

// +----------------------------------------------------------------------
// | API v1 —— 咨询（留言式，非实时 IM）
// +----------------------------------------------------------------------
// | 说明：本期不接入 WebSocket / RTC，以「提交留言 → 对方查看回复」的
// |       HTTP 模式承载沟通需求；数据结构预留后续实时能力。
// +----------------------------------------------------------------------

namespace app\api\controller\v1;

use think\facade\Db;

/**
 * 咨询接口
 *
 * 路由：
 *   GET  /api/v1/consultations                 我的会话列表
 *   POST /api/v1/consultations                 创建咨询会话（关系 ACL）
 *   GET  /api/v1/consultations/messages        拉取会话留言
 *   POST /api/v1/consultations/messages        发送留言 / 回复
 *   POST /api/v1/consultations/read            标记已读
 *   POST /api/v1/consultations/close           关闭会话
 */
class Consult extends Base
{
    protected $noNeedLogin = [];
    protected $noNeedRight = '*';

    /**
     * 我的会话列表（我发起的 + 我收到的）
     */
    public function index()
    {
        [$page, $limit] = $this->pageParams();
        $uid = $this->userId();

        // 我发起的
        $sentQuery = Db::name('app_conversation')->where('initiator_id', $uid);
        // 我是被咨询方的（按 target_type/target_id 命中我的项目或本人）
        $myProjectIds = Db::name('app_project')->where('owner_user_id', $uid)->column('id');
        $receivedQuery = Db::name('app_conversation')->where(function ($q) use ($uid, $myProjectIds) {
            $q->where(function ($qq) use ($uid) {
                $qq->where('target_type', 'user')->where('target_id', $uid);
            });
            if ($myProjectIds) {
                $q->whereOr(function ($qq) use ($myProjectIds) {
                    $qq->where('target_type', 'project')->whereIn('target_id', $myProjectIds);
                });
            }
        });

        $role = (string)$this->request->param('role', 'sent'); // sent / received
        $query = $role === 'received' ? $receivedQuery : $sentQuery;

        $total = (clone $query)->count();
        $rows = $query->order('last_message_at', 'desc')->order('id', 'desc')
            ->page($page, $limit)
            ->field('id,type,initiator_id,target_type,target_id,subject,status,last_message_at,create_time')
            ->select()->toArray();

        $list = $this->decorateConversations($rows);

        $this->success('', $this->pageResult($list, $total, $page, $limit));
    }

    /**
     * 创建咨询会话
     *
     * 关系 ACL（需求 §6.3）：社区长/项目方默认仅对「已关注」关系开放完整咨询权限，
     * 该行为由配置 app/consult_relation_required 控制，可后台关闭。
     */
    public function create()
    {
        $targetType = (string)$this->request->post('target_type', 'project');
        $targetId   = (int)$this->request->post('target_id', 0);
        $subject    = trim((string)$this->request->post('subject', ''));
        $content    = trim((string)$this->request->post('content', ''));

        if (!in_array($targetType, ['project', 'user'], true)) {
            $this->fail('咨询对象类型不合法', self::CODE_PARAM_INVALID);
        }
        if ($targetId <= 0) {
            $this->fail('缺少咨询对象 ID', self::CODE_PARAM_MISSING);
        }
        if ($content === '') {
            $this->fail('请填写咨询内容', self::CODE_PARAM_MISSING);
        }
        if (mb_strlen($content) > 2000) {
            $this->fail('咨询内容过长（最多 2000 字）', self::CODE_PARAM_INVALID);
        }

        [$targetUserId, $displayName] = $this->resolveTarget($targetType, $targetId);
        if ($targetUserId === $this->userId()) {
            $this->fail('不能咨询自己', self::CODE_PARAM_INVALID);
        }

        // 关系门槛
        $relationRequired = (int)Db::name('config')->where('group', 'app')->where('name', 'consult_relation_required')->value('value');
        if ($relationRequired === 1) {
            $followed = Db::name('app_follow')
                ->where('follower_id', $this->userId())
                ->where('followee_type', $targetType)
                ->where('followee_id', $targetId)
                ->where('status', 'normal')
                ->find();
            if (!$followed) {
                $this->fail('请先关注对方后再发起咨询', self::CODE_FORBIDDEN, ['need_follow' => true]);
            }
        }
        // 拉黑校验（双向）
        if (Db::name('app_block')->where('user_id', $targetUserId)->where('blocked_user_id', $this->userId())->find()) {
            $this->fail('对方已将你拉黑', self::CODE_FORBIDDEN);
        }
        if (Db::name('app_block')->where('user_id', $this->userId())->where('blocked_user_id', $targetUserId)->find()) {
            $this->fail('你已拉黑对方，请先解除', self::CODE_FORBIDDEN);
        }

        // 已有未关闭会话则复用
        $exists = Db::name('app_conversation')
            ->where('initiator_id', $this->userId())
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->where('status', 'open')
            ->find();

        $now = time();
        if ($exists) {
            $cid = (int)$exists['id'];
        } else {
            $cid = (int)Db::name('app_conversation')->insertGetId([
                'type'            => 'consult',
                'initiator_id'    => $this->userId(),
                'target_type'     => $targetType,
                'target_id'       => $targetId,
                'subject'         => $subject !== '' ? $subject : ('咨询：' . $displayName),
                'status'          => 'open',
                'last_message_at' => $now,
                'create_time'     => $now,
                'update_time'     => $now,
            ]);
        }

        $mid = (int)Db::name('app_message')->insertGetId([
            'conversation_id' => $cid,
            'sender_id'       => $this->userId(),
            'type'            => 'text',
            'body'            => $content,
            'read_at'         => 0,
            'create_time'     => $now,
        ]);
        Db::name('app_conversation')->where('id', $cid)->update(['last_message_at' => $now, 'update_time' => $now]);

        Db::name('app_event')->insert([
            'user_id' => $this->userId(), 'event_type' => 'consult',
            'target_type' => $targetType, 'target_id' => $targetId,
            'ip' => $this->request->ip(), 'create_time' => $now,
        ]);

        $this->success('咨询已发送', ['conversation_id' => $cid, 'message_id' => $mid]);
    }

    /**
     * 拉取会话留言
     */
    public function messages()
    {
        $cid = (int)$this->request->param('conversation_id', 0);
        $conversation = $this->findMyConversation($cid);

        $afterId = (int)$this->request->param('after_id', 0);
        $limit = min(100, max(1, (int)$this->request->param('limit', 50)));

        $query = Db::name('app_message')->where('conversation_id', $cid);
        if ($afterId > 0) {
            $query->where('id', '>', $afterId);
        }
        $list = $query->order('id', 'asc')->limit($limit)
            ->field('id,sender_id,type,body,attachment_id,read_at,create_time')
            ->select();

        // 拉取即视为已读（仅标记对方发的）
        Db::name('app_message')
            ->where('conversation_id', $cid)
            ->where('sender_id', '<>', $this->userId())
            ->where('read_at', 0)
            ->update(['read_at' => time()]);

        $this->success('', [
            'conversation' => $conversation,
            'list'         => $list,
        ]);
    }

    /**
     * 发送留言 / 回复
     */
    public function send()
    {
        $cid = (int)$this->request->post('conversation_id', 0);
        $content = trim((string)$this->request->post('content', ''));
        $type = (string)$this->request->post('type', 'text');

        $conversation = $this->findMyConversation($cid);
        if ($conversation['status'] !== 'open') {
            $this->fail('会话已关闭，无法发送', self::CODE_STATE_INVALID);
        }
        if ($content === '') {
            $this->fail('请填写内容', self::CODE_PARAM_MISSING);
        }
        if (mb_strlen($content) > 2000) {
            $this->fail('内容过长（最多 2000 字）', self::CODE_PARAM_INVALID);
        }
        if (!in_array($type, ['text', 'file', 'link'], true)) {
            $this->fail('消息类型不合法', self::CODE_PARAM_INVALID);
        }

        $now = time();
        $mid = (int)Db::name('app_message')->insertGetId([
            'conversation_id' => $cid,
            'sender_id'       => $this->userId(),
            'type'            => $type,
            'body'            => $content,
            'read_at'         => 0,
            'create_time'     => $now,
        ]);
        Db::name('app_conversation')->where('id', $cid)->update(['last_message_at' => $now, 'update_time' => $now]);

        $this->success('已发送', ['message_id' => $mid]);
    }

    /**
     * 标记已读
     */
    public function read()
    {
        $cid = (int)$this->request->post('conversation_id', 0);
        $this->findMyConversation($cid);

        $n = Db::name('app_message')
            ->where('conversation_id', $cid)
            ->where('sender_id', '<>', $this->userId())
            ->where('read_at', 0)
            ->update(['read_at' => time()]);

        $this->success('已读', ['count' => (int)$n]);
    }

    /**
     * 关闭会话
     */
    public function close()
    {
        $cid = (int)$this->request->post('conversation_id', 0);
        $this->findMyConversation($cid);

        Db::name('app_conversation')->where('id', $cid)->update([
            'status' => 'closed', 'update_time' => time(),
        ]);
        $this->success('会话已关闭');
    }

    /* ==================== 内部方法 ==================== */

    /**
     * 解析咨询对象：返回 [目标用户ID, 展示名]
     * @return array{0:int,1:string}
     */
    protected function resolveTarget(string $type, int $id): array
    {
        if ($type === 'user') {
            $u = Db::name('user')->where('id', $id)->field('id,nickname')->find();
            if (!$u) {
                $this->fail('用户不存在', self::CODE_NOT_FOUND);
            }
            return [(int)$u['id'], (string)$u['nickname']];
        }
        $p = Db::name('app_project')->where('id', $id)->field('id,name,owner_user_id')->find();
        if (!$p) {
            $this->fail('项目不存在', self::CODE_NOT_FOUND);
        }
        return [(int)$p['owner_user_id'], (string)$p['name']];
    }

    /**
     * 校验会话归属（发起人或被咨询方）
     */
    protected function findMyConversation(int $cid): array
    {
        if ($cid <= 0) {
            $this->fail('缺少会话 ID', self::CODE_PARAM_MISSING);
        }
        $conversation = Db::name('app_conversation')->where('id', $cid)->find();
        if (!$conversation) {
            $this->fail('会话不存在', self::CODE_NOT_FOUND);
        }
        $uid = $this->userId();
        $isInitiator = (int)$conversation['initiator_id'] === $uid;

        $isTarget = false;
        if ($conversation['target_type'] === 'user') {
            $isTarget = (int)$conversation['target_id'] === $uid;
        } else {
            $ownerId = (int)Db::name('app_project')->where('id', (int)$conversation['target_id'])->value('owner_user_id');
            $isTarget = $ownerId === $uid;
            // 项目团队成员亦可参与
            if (!$isTarget) {
                $isTarget = (bool)Db::name('app_project_member')
                    ->where('project_id', (int)$conversation['target_id'])
                    ->where('user_id', $uid)->where('status', 'normal')->find();
            }
        }

        if (!$isInitiator && !$isTarget) {
            $this->fail('无权访问该会话', self::CODE_FORBIDDEN);
        }
        $conversation['_is_initiator'] = $isInitiator;
        return $conversation;
    }

    /**
     * 会话列表补全信息（对象名称、未读数、最后一条）
     */
    protected function decorateConversations(array $rows): array
    {
        if (!$rows) {
            return [];
        }
        $uid = $this->userId();
        $pids = [];
        $uids = [];
        foreach ($rows as $r) {
            if ($r['target_type'] === 'project') {
                $pids[] = (int)$r['target_id'];
            } else {
                $uids[] = (int)$r['target_id'];
            }
            $uids[] = (int)$r['initiator_id'];
        }
        $projects = $pids
            ? array_column(Db::name('app_project')->whereIn('id', array_unique($pids))->field('id,name,cover,owner_user_id')->select()->toArray(), null, 'id')
            : [];
        $users = $uids
            ? array_column(Db::name('user')->whereIn('id', array_unique($uids))->field('id,nickname,avatar')->select()->toArray(), null, 'id')
            : [];

        foreach ($rows as $k => $r) {
            $tid = (int)$r['target_id'];
            if ($r['target_type'] === 'project') {
                $rows[$k]['target_name'] = $projects[$tid]['name'] ?? '';
                $rows[$k]['target_cover'] = $projects[$tid]['cover'] ?? '';
                $peerId = (int)($projects[$tid]['owner_user_id'] ?? 0);
            } else {
                $rows[$k]['target_name'] = $users[$tid]['nickname'] ?? '';
                $rows[$k]['target_cover'] = $users[$tid]['avatar'] ?? '';
                $peerId = $tid;
            }
            $rows[$k]['initiator_name'] = $users[(int)$r['initiator_id']]['nickname'] ?? '';
            $rows[$k]['unread'] = (int)Db::name('app_message')
                ->where('conversation_id', (int)$r['id'])
                ->where('sender_id', '<>', $uid)
                ->where('read_at', 0)
                ->count();
            $last = Db::name('app_message')->where('conversation_id', (int)$r['id'])
                ->order('id', 'desc')->field('id,sender_id,type,body,create_time')->find();
            $rows[$k]['last_message'] = $last ?: null;
        }
        return $rows;
    }
}
