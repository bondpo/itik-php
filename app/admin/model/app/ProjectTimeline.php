<?php

// +----------------------------------------------------------------------
// | 项目社区信息交互平台 —— 项目发展历程与里程碑模型
// +----------------------------------------------------------------------

namespace app\admin\model\app;

use think\Model;

class ProjectTimeline extends Model
{
    protected $name = 'app_project_timeline';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    protected $append = ['event_time_text'];

    protected $type = [
        'create_time' => 'int',
        'update_time' => 'int',
        'event_time'  => 'int',
        'weigh'       => 'int',
        'project_id'  => 'int',
        'user_id'     => 'int',
    ];

    /**
     * 所属项目
     */
    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id', 'id');
    }

    /**
     * 发布人
     */
    public function user()
    {
        return $this->belongsTo(\app\admin\model\User::class, 'user_id', 'id');
    }

    /**
     * 事件发生时间文本读取器
     */
    public function getEventTimeTextAttr($value, $data)
    {
        $time = $data['event_time'] ?? 0;
        return $time > 0 ? date('Y-m-d', $time) : '';
    }
}
