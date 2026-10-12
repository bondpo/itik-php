<?php

// +----------------------------------------------------------------------
// | 项目社区信息交互平台 —— 后台模型
// +----------------------------------------------------------------------

namespace app\admin\model\app;

use think\Model;
use think\model\concern\SoftDelete;

class ProjectMember extends Model
{
    protected $name = 'app_project_member';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    protected $defaultSoftDelete = null;

    /** 时间字段类型声明：getDateTime() 依据此映射决定写入时间戳还是日期字符串 */
    protected $type = [
        'create_time' => 'int',
        'update_time' => 'int',
    ];

    /** 所属项目 */
    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id', 'id');
    }

    /** 成员用户 */
    public function user()
    {
        return $this->belongsTo(\app\admin\model\User::class, 'user_id', 'id');
    }
}
