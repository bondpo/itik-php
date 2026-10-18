<?php

namespace app\admin\model;

use think\Model;


class MoneyLog extends Model
{


    protected $name = 'user_money_log';

    protected $autoWriteTimestamp = true;

    protected $type = [
        'createtime' => 'int',
    ];

    protected $createTime = 'createtime';
    protected $updateTime = false;
    protected $deleteTime = false;

    protected $append = [

    ];










}
