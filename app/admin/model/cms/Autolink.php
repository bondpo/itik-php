<?php

namespace app\admin\model\cms;

use modules\cms\library\Model;


class Autolink extends Model
{

    // 表名
    protected $name = 'cms_autolink';

    // 自动写入时间戳字段
    protected $autoWriteTimestamp = true;

    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $deleteTime = false;

    // 追加属性
    protected $append = [
        'target_text',
        'status_text'
    ];

    protected function initializeEvents()
    {
        self::afterInsert(function ($row) {
            $pk = $row->getPk();
            $row->getQuery()->where($pk, $row[$pk])->update(['weigh' => $row[$pk]]);
        });
    }

    public function getTargetList()
    {
        return ['self' => __('Self'), 'blank' => __('Blank')];
    }

    public function getStatusList()
    {
        return ['normal' => __('Normal'), 'hidden' => __('Hidden')];
    }

    public function getTargetTextAttr($value, $data)
    {
        $value = $value ?: ($data['target'] ?? '');
        $list = $this->getTargetList();
        return $list[$value] ?? '';
    }

    public function getStatusTextAttr($value, $data)
    {
        $value = $value ?: ($data['status'] ?? '');
        $list = $this->getStatusList();
        return $list[$value] ?? '';
    }

}
