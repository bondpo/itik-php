<?php

// +----------------------------------------------------------------------
// | 项目社区信息交互平台 —— 后台模型
// +----------------------------------------------------------------------

namespace app\admin\model;

use think\Model;

class Region extends Model
{
    protected $name = 'region';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    protected $type = [
        'create_time' => 'int',
        'update_time' => 'int',
    ];

    protected $append = ['level_text'];

    /**
     * 层级由父级自动推导：1=国家/地区 2=省/州 3=市/区 4=县/区
     */
    public static function onBeforeWrite($row): void
    {
        $parentId = (int) ($row->parent_id ?? 0);
        if ($parentId > 0) {
            $parentLevel = (int) self::where('id', $parentId)->value('level');
            $row->level = $parentLevel > 0 ? $parentLevel + 1 : 1;
        } else {
            $row->level = 1;
        }
    }

    /**
     * 层级下拉
     */
    public function getLevelList(): array
    {
        return [
            1 => '国家/地区',
            2 => '省/州（含直辖市）',
            3 => '市/区',
            4 => '县/区',
        ];
    }

    /**
     * 层级中文展示
     */
    public function getLevelTextAttr($value, $data): string
    {
        $map = $this->getLevelList();
        return $map[(int)($data['level'] ?? 0)] ?? '';
    }

    /**
     * 状态下拉
     */
    public function getStatusList(): array
    {
        return ['normal' => '正常', 'hidden' => '隐藏'];
    }
}
