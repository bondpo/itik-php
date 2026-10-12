<?php

namespace app\common\model\cms;

use think\facade\Db;
use app\common\library\cms\Model;

/**
 * 标签模型
 */
class Taggable extends Model
{
    protected $name = "cms_taggable";
    // 开启自动写入时间戳字段
    protected $autoWriteTimestamp = 'int';
    // 定义时间戳字段名
    protected $createTime = '';
    protected $updateTime = '';
    // 追加属性
    protected $append = [
        'url',
        'fullurl'
    ];

    protected static $config = [];

    protected function initializeEvents()
    {
        $config = get_addon_config('cms');
        self::$config = $config;
    }

    public function model()
    {
        return $this->belongsTo("Modelx");
    }

}
