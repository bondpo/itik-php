<?php

// +----------------------------------------------------------------------
// | 项目社区信息交互平台 —— 后台模型（咨询会话）
// +----------------------------------------------------------------------

namespace app\admin\model\app;

use think\Model;
use think\facade\Db;

class Conversation extends Model
{
    protected $name = 'app_conversation';
    protected $autoWriteTimestamp = false;
    protected $type = [
        'create_time'     => 'int',
        'update_time'     => 'int',
        'last_message_at' => 'int',
    ];

    /** 发起人 */
    public function initiator()
    {
        return $this->belongsTo(\app\admin\model\User::class, 'initiator_id', 'id');
    }

    public function getMessages(int $conversationId, int $page, int $limit): array
    {
        $conversation = self::find($conversationId);
        if (!$conversation) {
            throw new \RuntimeException('会话不存在');
        }
        $messages = Db::name('app_message')->alias('m')
            ->leftJoin('user u', 'u.id = m.sender_id')
            ->where('m.conversation_id', $conversationId)
            ->field('m.id,m.sender_id,m.type,m.body,m.read_at,m.create_time,u.nickname')
            ->order('m.id', 'asc')->paginate(['list_rows' => $limit, 'page' => $page]);
        return ['conversation' => $conversation->toArray(), 'list' => $messages->items(),
            'total' => $messages->total(), 'page' => $page, 'limit' => $limit];
    }

    public function deleteWithMessages(array $ids, int $adminId, string $ip): int
    {
        return Db::transaction(function () use ($ids, $adminId, $ip) {
            $existing = self::whereIn('id', $ids)->lock(true)->column('id');
            if (!$existing) {
                throw new \RuntimeException('会话不存在或已删除');
            }
            // 会话和留言属于同一聚合，必须同时删除，失败时统一回滚。
            Db::name('app_message')->whereIn('conversation_id', $existing)->delete();
            $count = self::whereIn('id', $existing)->delete();
            $logs = [];
            foreach ($existing as $id) {
                $logs[] = ['actor_type' => 'admin', 'actor_id' => $adminId,
                    'action' => 'conversation.delete', 'target_type' => 'conversation', 'target_id' => $id,
                    'ip' => $ip, 'create_time' => time()];
            }
            Db::name('app_audit_log')->insertAll($logs);
            return $count;
        });
    }
}
