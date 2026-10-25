<?php

// +----------------------------------------------------------------------
// | 项目社区信息交互平台 —— 后台模型
// +----------------------------------------------------------------------

namespace app\admin\model\app;

use think\Model;
use think\model\concern\SoftDelete;

class Plan extends Model
{
    protected $name = 'app_plan';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    protected $defaultSoftDelete = null;

    /** 时间字段类型声明：getDateTime() 依据此映射决定写入时间戳还是日期字符串 */
    protected $type = [
        'create_time' => 'int',
        'update_time' => 'int',
    ];

    protected $append = [
        'start_time_text',
        'end_time_text',
    ];


    public function setStartTimeAttr($value)
    {
        return $value && !is_numeric($value) ? strtotime($value) : $value;
    }
    public function getStartTimeTextAttr($value, $data)
    {
        $value = $value ? $value : ($data['start_time'] ?? "");
        return is_numeric($value) ? date("Y-m-d H:i:s", $value) : $value;
    }

    public function setEndTimeAttr($value)
    {
        return $value && !is_numeric($value) ? strtotime($value) : $value;
    }
    public function getEndTimeTextAttr($value, $data)
    {
        $value = $value ? $value : ($data['end_time'] ?? "");
        return is_numeric($value) ? date("Y-m-d H:i:s", $value) : $value;
    }
}
