<?php

namespace app\index\model\cms;

use modules\cms\library\Service;
use modules\cms\library\Model;

class Diydata extends Model
{

    // 开启自动写入时间戳字段
    protected $autoWriteTimestamp = 'int';
    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    public $diyform = null;
    protected static $config = [];
    protected static $diyformModel = null;

    protected function initializeEvents()
    {
        $config = get_addon_config('cms');
        self::$config = $config;

        self::beforeWrite(function ($row) {
            $data = $row->getData();
            foreach ($data as $index => &$value) {
                if (is_array($value) && isset($value['field'])) {
                    $value = json_encode(\modules\cms\library\Config::getArrayData($value), JSON_UNESCAPED_UNICODE);
                } else {
                    $value = is_array($value) ? implode(',', $value) : $value;
                }
                $row->setAttr($index, $value);
            }
        });
    }

    public function __construct($data = [], $diyform = null)
    {
        if ($diyform) {
            static::$diyformModel = $diyform;
            $this->diyform = $diyform;
        }

        parent::__construct($data);

        // 动态表名需要在模型完成构造后设置，否则会触发 think-orm 的 WeakMap 检查
        if ($diyform) {
            $this->setOption('name', $diyform['table']);
            $this->setOption('schema', []);
        }
    }

    /**
     * 动态表名：自定义表单对应的数据表
     * @return string
     */
    public function getName(): string
    {
        // 优先使用当前实例的表单，其次使用最近一次设置的表单（静态兜底）
        $table = '';
        if ($this->diyform && !empty($this->diyform['table'])) {
            $table = $this->diyform['table'];
        } elseif (static::$diyformModel && !empty(static::$diyformModel['table'])) {
            $table = static::$diyformModel['table'];
        }
        return $table ?: parent::getName();
    }

    public function getDiyform()
    {
        return static::$diyformModel;
    }

    public function getAttr($name)
    {
        //获取自定义字段关联表数据
        if (!isset($this->data[$name]) && preg_match("/(.*)_value\$/i", $name, $matches)) {
            $key = $this->data[$matches[1]] ?? '';
            if (!$key) {
                return '';
            }
            return Service::getRelationFieldValue('diyform', static::$diyformModel['id'], $matches[1], $key);
        }
        return parent::getAttr($name);
    }

    public function getUrlAttr($value, $data)
    {
        return $this->buildUrl($value, $data);
    }

    public function getFullurlAttr($value, $data)
    {
        return $this->buildUrl($value, $data, true);
    }

    private function buildUrl($value, $data, $domain = false)
    {
        $diyname = !empty(static::$diyformModel['diyname']) ? static::$diyformModel['diyname'] : 'all';
        $time = $data['createtime'] ?? time();

        $vars = [
            ':id'      => $data['id'],
            ':diyname' => $diyname,
            ':year'    => date("Y", $time),
            ':month'   => date("m", $time),
            ':day'     => date("d", $time)
        ];
        $suffix = static::$config['moduleurlsuffix']['diyform_show'] ?? static::$config['urlsuffix'];
        return addon_url('cms/diyform/show', $vars, $suffix, $domain);
    }

    public function getStatusList()
    {
        return ['normal' => __('Normal'), 'hidden' => __('Hidden'), 'rejected' => __('Rejected')];
    }

    public function getStatusTextAttr($value, $data)
    {
        $value = $value ? $value : $data['status'];
        $list = $this->getStatusList();
        return $list[$value] ?? '';
    }

}
