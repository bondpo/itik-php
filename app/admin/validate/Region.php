<?php

// +----------------------------------------------------------------------
// | 项目社区信息交互平台 —— 后台验证器
// +----------------------------------------------------------------------

namespace app\admin\validate;

use think\Validate;

class Region extends Validate
{
    protected $failException = true;

    /**
     * 验证规则
     */
    protected $rule = [
        'name_zh|中文名' => 'require|max:100',
        'name_en|英文名' => 'max:100',
        'code|地区代码'  => 'max:32',
    ];

    /**
     * 提示消息
     */
    protected $message = [
        'name_zh.require' => '中文名不能为空',
        'name_zh.max'     => '中文名不能超过 100 个字符',
    ];
}
