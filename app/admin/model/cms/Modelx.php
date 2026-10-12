<?php

namespace app\admin\model\cms;

use think\facade\Config;
use think\facade\Db;
use think\Exception;
use think\exception\PDOException;
use app\common\library\cms\Model;

class Modelx extends Model
{

    // 表名
    protected $name = 'cms_model';
    // 自动写入时间戳字段
    protected $autoWriteTimestamp = true;
    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    // 追加属性
    protected $append = [
    ];

    protected function initializeEvents()
    {
        self::beforeInsert(function ($row) {
            $source = 'model';
            $setting = [
                'contributefields' => Fields::getContributeFields($source),
                'publishfields'    => Fields::getPublishFields($source),
            ];
            $row['setting'] = json_encode($setting);
            if (!preg_match("/^([a-z0-9_]+)$/", $row['table'])) {
                throw new Exception("表名只支持小写字母、数字、下划线");
            }
            $exist = Modelx::where('table', $row['table'])->find();
            if ($exist) {
                throw new Exception("已经存在相同表名的模型");
            }
            $info = null;
            try {
                $info = Db::name($row['table'])->getTableInfo();
            } catch (\Exception $e) {
            }
            if ($info) {
                throw new Exception("数据表已经存在");
            }
        });
        self::afterInsert(function ($row) {
            $prefix = config('database.connections.mysql.prefix');
            $sql = "CREATE TABLE `{$prefix}{$row['table']}` (`id` int(10) NOT NULL,`content` longtext NOT NULL,PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='{$row['name']}'";
            try {
                Db::execute($sql);
            } catch (\Exception $e) {
                throw new Exception("发生错误：" . $e->getMessage());
            }
        });
        //存在栏目无法删除
        self::beforeDelete(function ($row) {
            $exist = Channel::where('model_id', $row['id'])->find();
            if ($exist) {
                throw new Exception("模型下存在栏目，无法进行删除");
            }
        });
        //删除模型后删除对应的表字段
        self::afterDelete(function ($row) {
            Db::name("cms_fields")->where(['source' => 'model', 'source_id' => $row['id']])->delete();
        });

        //更新模型后
        self::afterWrite(function ($row) {
            if ($row['syncconfig'] ?? [] && $row['syncchannel'] ?? []) {
                $data = [
                ];
                Db::name("cms_channel")->where('id', 'in', $row['syncchannel'])->update($data);
            }
        });
    }

    public function getFieldsAttr($value, $data)
    {
        return is_array($value) ? $value : ($value ? explode(',', $value) : []);
    }

    public function getSettingAttr($value, $data)
    {
        return is_array($value) ? $value : (array)json_decode($data['setting'], true);
    }

    public function setSettingAttr($value)
    {
        return is_array($value) ? json_encode($value) : $value;
    }
}
