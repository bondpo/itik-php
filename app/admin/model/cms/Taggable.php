<?php

namespace app\admin\model\cms;

use modules\cms\library\Model;

class Taggable extends Model
{

    // 表名
    protected $name = 'cms_taggable';
    // 自动写入时间戳字段
    protected $autoWriteTimestamp = false;
    // 定义时间戳字段名
    protected $createTime = false;
    protected $updateTime = false;
    // 追加属性
    protected $append = [
    ];
    protected static $config = [];

    protected function initializeEvents()
    {
        static::$config = get_addon_config('cms');
    }
}
