<?php

namespace app\admin\validate;

use think\Validate;

class PageImages extends Validate
{
    protected $failException = true;

    protected $rule = [
    ];

    protected $message = [
    ];

    protected $scene = [
        'add'  => [],
        'edit' => [],
    ];
}
