<?php

namespace app\admin\validate\cms;

use think\Validate;

class Channel extends Validate
{
    /**
     * 验证规则
     */
    protected $rule = [
        'image|图片'            => 'length:0,255',
        'keywords|关键字'       => 'length:0,255',
        'description|描述'      => 'length:0,255',
        'diyname|自定义URL名称' => 'length:0,255',
        'seotitle|SEO标题'      => 'length:0,255',
        'outlink|外部链接'      => 'length:0,255',
    ];
    /**
     * 提示消息
     */
    protected $message = [
    ];
    /**
     * 验证场景
     */
    protected $scene = [
        'add'  => [
            'image',
            'keywords',
            'description',
            'diyname',
            'seotitle',
            'outlink',
        ],
        'edit' => [
            'image',
            'keywords',
            'description',
            'diyname',
            'seotitle',
            'outlink',
        ],
    ];
}
