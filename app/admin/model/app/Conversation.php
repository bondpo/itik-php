<?php

// +----------------------------------------------------------------------
// | 项目社区信息交互平台 —— 后台模型（咨询会话）
// +----------------------------------------------------------------------

namespace app\admin\model\app;

use think\Model;

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
}
