<?php

namespace app\admin\model;

use think\Model;


class UserLvTeam extends Model
{


    protected $name = 'user_lv_team';

    protected $autoWriteTimestamp = true;

    protected $type = [

    ];

    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    protected $deleteTime = false;

    protected $append = [
        'status_text'
    ];



    public function getStatusList()
    {
        return ['0' => __('Status0'), '1' => __('Status1')];
    }


    public function getStatusTextAttr($value, $data)
    {
        $value = $value ?: ($data['status'] ?? '');
        $list = $this->getStatusList();
        return $list[$value] ?? '';
    }




}
