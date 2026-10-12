<?php

namespace app\index\model\cms;

use think\facade\Db;
use modules\cms\library\Model;

/**
 * 自动链接模型
 */
class Autolink extends Model
{
    protected $name = "cms_autolink";
    // 开启自动写入时间戳字段
    protected $autoWriteTimestamp = 'int';
    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    // 追加属性
    protected $append = [
    ];
    protected static $config = [];

    protected function initializeEvents()
    {
        $config = get_addon_config('cms');
        self::$config = $config;
    }

}
