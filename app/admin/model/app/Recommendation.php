<?php

// +----------------------------------------------------------------------
// | 项目社区信息交互平台 —— 后台模型
// +----------------------------------------------------------------------

namespace app\admin\model\app;

use think\Model;

class Recommendation extends Model
{
    protected $name = 'app_recommendation';
    protected $autoWriteTimestamp = false;
    protected $type = [
        'create_time' => 'int',
    ];

    /** 接收人 */
    public function receiver()
    {
        return $this->belongsTo(\app\admin\model\User::class, 'receiver_id', 'id');
    }
}
