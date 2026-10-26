<?php

namespace app\admin\model;

use think\Model;


class UsdtTransfers extends Model
{


    protected $name = 'usdt_transfers';

    protected $autoWriteTimestamp = false;

    protected $type = [

    ];

    protected $createTime = false;
    protected $updateTime = false;
    protected $deleteTime = false;

    protected $append = [
        'task_id_text',
        'token_symbol_text',
        'status_text'
    ];



    public function getTaskIdList()
    {
        return ['0' => __('TaskId0'), '1' => __('TaskId1')];
    }

    public function getTokenSymbolList()
    {
        return ['USDT' => __('USDT')];
    }

    public function getStatusList()
    {
        return ['wait' => __('StatusWait'), 'pending' => __('StatusPending'), 'prepared' => __('StatusPrepared'), 'broadcast' => __('StatusBroadcast'), 'success' => __('StatusSuccess'), 'failed' => __('StatusFailed'), 'reverted' => __('StatusReverted'), 'stuck' => __('StatusStuck'), 'manual_fixed' => __('StatusManualFixed'), 'cancelled' => __('StatusCancelled')];
    }


    public function getTaskIdTextAttr($value, $data)
    {
        $value = $value ?: ($data['task_id'] ?? '');
        $list = $this->getTaskIdList();
        return $list[$value] ?? '';
    }


    public function getTokenSymbolTextAttr($value, $data)
    {
        $value = $value ?: ($data['token_symbol'] ?? '');
        $list = $this->getTokenSymbolList();
        return $list[$value] ?? '';
    }


    public function getStatusTextAttr($value, $data)
    {
        $value = $value ?: ($data['status'] ?? '');
        $list = $this->getStatusList();
        return $list[$value] ?? '';
    }

    protected function setTaskIdAttr($value)
    {
        return is_array($value) ? implode(',', $value) : $value;
    }

    protected function setTokenSymbolAttr($value)
    {
        return is_array($value) ? implode(',', $value) : $value;
    }


}
