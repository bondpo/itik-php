<?php

namespace app\admin\model;

use think\Model;


class PageImages extends Model
{


    protected $name = 'page_images';

    protected $autoWriteTimestamp = true;

    protected $type = [
        'create_time' => 'int',
        'update_time' => 'int',
    ];

    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    protected $deleteTime = false;

    protected $append = [
        'status_text'
    ];


    protected static function onAfterInsert($row)
    {
        if (!$row['weigh']) {
            $pk = $row->getPk();
            $row->where($pk, $row[$pk])->update(['weigh' => $row[$pk]]);
        }
    }

    public function getStatusList()
    {
        return ['normal' => __('StatusNormal'), 'hidden' => __('StatusHidden')];
    }


    public function getStatusTextAttr($value, $data)
    {
        $value = $value ?: ($data['status'] ?? '');
        $list = $this->getStatusList();
        return $list[$value] ?? '';
    }




}
