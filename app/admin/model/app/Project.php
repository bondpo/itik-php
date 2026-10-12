<?php

// +----------------------------------------------------------------------
// | 项目社区信息交互平台 —— 后台模型
// +----------------------------------------------------------------------

namespace app\admin\model\app;

use think\Model;
use think\model\concern\SoftDelete;

class Project extends Model
{
    use SoftDelete;

    protected $name = 'app_project';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    protected $deleteTime = 'deletetime';
    protected $defaultSoftDelete = null;

    /** 时间字段类型声明：getDateTime() 依据此映射决定写入时间戳还是日期字符串 */
    protected $type = [
        'create_time' => 'int',
        'update_time' => 'int',
    ];

    /** 所属项目方 */
    public function owner()
    {
        return $this->belongsTo(\app\admin\model\User::class, 'owner_user_id', 'id');
    }
}
