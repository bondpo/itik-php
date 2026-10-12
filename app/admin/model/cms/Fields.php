<?php

namespace app\admin\model\cms;

use app\common\library\cms\Alter;
use app\common\library\cms\Service;
use app\common\library\cms\Config;
use think\facade\Db;
use think\Exception;
use think\exception\PDOException;
use app\common\library\cms\Model;

class Fields extends Model
{

    // 表名
    protected $name = 'cms_fields';
    // 自动写入时间戳字段
    protected $autoWriteTimestamp = true;
    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    // 追加属性
    protected $append = [
        'status_text',
        'content_list',
    ];
    protected $type = [
        'setting' => 'json',
    ];
    protected static $listField = ['select', 'selects', 'checkbox', 'radio', 'array', 'selectpage', 'selectpages'];

    public function setError($error)
    {
        $this->error = $error;
    }

    protected function initializeEvents()
    {
        $beforeUpdateCallback = function ($row) {
            $changedData = $row->getChangedData();
            if (isset($changedData['name'])) {
                if (!preg_match("/^([a-zA-Z0-9_]+)$/i", $row['name'])) {
                    throw new Exception("字段只支持字母数字下划线");
                }
                if (is_numeric(substr($row['name'], 0, 1))) {
                    throw new Exception("字段不能以数字开始");
                }

                if ($row['source'] == 'model') {
                    $tableFields = \think\facade\Db::name('cms_archives')->getTableFields();
                    if (in_array(strtolower($row['name']), $tableFields)) {
                        throw new Exception("字段已经在主表存在了");
                    }
                    if (in_array($row['name'], ['id', 'content'])) {
                        throw new Exception("字段已经存在");
                    }
                } elseif (in_array($row['source'], ['channel', 'page', 'special', 'block', 'archives'])) {
                    //栏目、单页、专题、区块需过滤主表字段
                    $tableFields = \think\facade\Db::name('cms_' . $row['source'])->getTableFields();
                    $customFieldList = Service::getCustomFields($row['source'], 0);
                    $tableFields = array_diff($tableFields, array_map(function ($field) {
                        return $field['name'];
                    }, collection($customFieldList)->toArray()));

                    if (in_array(strtolower($row['name']), $tableFields)) {
                        throw new Exception("字段已经在表中存在了");
                    }

                    if ($row['source'] == 'archives') {
                        $modelList = Modelx::select();
                        foreach ($modelList as $index => $item) {
                            if (in_array(strtolower($row['name']), $item['fields'])) {
                                throw new Exception("字段名称已经在自定义模型【{$item['name']}】表中存在");
                            }
                        }
                    }
                } elseif ($row['source'] == 'diyform') {
                    $tableFields = ['id', 'user_id', 'createtime', 'updatetime', 'memo', 'status'];
                    if (in_array(strtolower($row['name']), $tableFields)) {
                        throw new Exception("字段已经存在");
                    }
                } else {
                    $tableFields = ['id', 'user_id', 'type', 'createtime', 'updatetime'];
                    if (in_array(strtolower($row['name']), $tableFields)) {
                        throw new Exception("字段为保留字段，请使用其它字段");
                    }
                }
                $vars = array_keys(get_class_vars('\think\Model'));
                $vars = array_map('strtolower', $vars);
                $vars = array_merge($vars, ['url', 'fullurl']);
                if (in_array(strtolower($row['name']), $vars)) {
                    throw new Exception("字段为模型保留字段，请使用其它字段");
                }
            }
        };

        $afterInsertCallback = function ($row) {
            //为了避免引起更新的事件回调
            Db::name('cms_fields')->update(['id' => $row['id'], 'weigh' => $row['id']]);
            Fields::refreshTable($row, 'insert');
        };
        $afterUpdateCallback = function ($row) {
            Fields::refreshTable($row, 'update');
        };

        self::beforeInsert($beforeUpdateCallback);
        self::beforeUpdate($beforeUpdateCallback);

        self::afterInsert($afterInsertCallback);
        self::afterUpdate($afterUpdateCallback);

        self::afterDelete(function ($row) {
            Fields::refreshTable($row, 'delete');
        });
    }

    public function setContentAttr($value, $data)
    {
        $json = (array)json_decode($value, true);
        if ($json) {
            $value = Config::encode($json);
        }
        return $value;
    }

    public function setFilterlistAttr($value, $data)
    {
        $json = (array)json_decode($value, true);
        if ($json) {
            $value = Config::encode($json);
        }
        return $value;
    }

    public function getContentListAttr($value, $data)
    {
        return Config::decode($data['content']);
    }

    public function getFilterlistListAttr($value, $data)
    {
        return Config::decode($data['filterlist'] ?: $data['content']);
    }

    /**
     * 获取可投稿字段
     * @param $source
     * @return array|string[]
     */
    public static function getContributeFields($source)
    {
        $fields = ["channel_ids", "image", "images", "tags", "price", "outlink", "content", "keywords", "description"];
        if ($source == 'model') {
            $fields = array_merge($fields, Fields::where('source', 'archives')->column('name'));
        }
        return $fields;
    }

    /**
     * 获取发布字段
     * @param $source
     * @return array|string[]
     */
    public static function getPublishFields($source)
    {
        $fields = ["channel_ids", "user_id", "special_ids", "image", "images", "diyname", "tags", "price", "outlink", "content", "seotitle", "keywords", "description"];
        if ($source == 'model') {
            $fields = array_merge($fields, Fields::where('source', 'archives')->column('name'));
        }
        return $fields;
    }

    /**
     * 获取JS显示字段
     * @param $source
     * @param $source_id
     * @return array
     */
    public static function getJsFieldsList($source, $source_id = 0)
    {
        $fields = [];
        $fieldsList = \app\admin\model\cms\Fields::where('source', $source)->where('source_id', $source_id)->where('type', '<>', 'text')->select();
        foreach ($fieldsList as $index => $item) {
            $fields[] = ['field' => $item['name'], 'title' => $item['title'], 'type' => $item['type'], 'content' => $item['content_list']];
        }
        foreach ($fields as $index => &$field) {
            //避免出现0和1导致按序转为array
            if (in_array($field['type'], ['select', 'selects', 'checkbox', 'radio', 'array'])) {
                $field['content'] = (object)($field['content'] ?? []);
                $field['content_list'] = (object)($field['content_list'] ?? []);
            }
        }
        return $fields;
    }

    /**
     * 刷新表结构
     * @param $row
     * @param $action
     * @return void
     */
    public static function refreshTable($row, $action = 'insert')
    {
        $model = null;
        if (in_array($row['source'], ['model', 'diyform'])) {
            $model = $row['source'] == 'model' ? Modelx::find($row['source_id']) : Diyform::find($row['source_id']);
            if (!$model) {
                throw new Exception("未找到指定模型");
            }
            $table = $model['table'];

            $fields = Fields::where('source', $row['source'])->where('source_id', $row['source_id'])->column('name');
            Db::name("cms_" . $row['source'])->where('id', $row['source_id'])->update(['fields' => implode(',', $fields)]);
        } elseif (in_array($row['source'], ['channel', 'page', 'special', 'block', 'archives'])) {
            $table = "cms_" . $row['source'];
        } else {
            throw new Exception("未找到指定模型");
        }

        $alter = Alter::instance();
        if (isset($row['oldname']) && $row['oldname'] != $row['name']) {
            $alter->setOldname($row['oldname']);
        }

        //列表、列表（多）、单选、复选为了兼容性使用string类型
        $compaticity = true;

        $alter->setTable($table)
            ->setName($row['name'])
            // 未填写长度时使用默认 255，避免生成非法的 varchar()
            ->setLength($row['length'] ?: 255)
            ->setContent($row['content'])
            ->setDecimals($row['decimals'])
            ->setDefaultvalue($row['defaultvalue'])
            ->setComment($row['title'])
            ->setType($compaticity && in_array($row['type'], ['select', 'selects', 'checkbox', 'radio']) ? 'string' : $row['type']);
        if ($action == 'insert') {
            $sql = $alter->getAddSql();
        } elseif ($action == 'update') {
            $sql = $alter->getModifySql();
        } elseif ($action == 'delete') {
            $sql = $alter->getDropSql();
        } else {
            throw new Exception("操作类型错误");
        }
        try {
            Db::connect([], 'refresh')->execute($sql);
        } catch (PDOException $e) {
            throw new Exception($e->getMessage());
        }
    }

    public function getStatusList()
    {
        return ['normal' => __('Normal'), 'hidden' => __('Hidden')];
    }

    public function getStatusTextAttr($value, $data)
    {
        $value = $value ?: $data['status'];
        $list = $this->getStatusList();
        return $list[$value] ?? '';
    }

    public function getFavisibleTextAttr($value, $data)
    {
        if (!$data['favisible']) {
            return '';
        }
        $data['favisible'] = is_array($data['favisible']) ? $data['favisible'] : (array)json_decode($data['favisible'], true);
        $result = [];
        foreach ($data['favisible'] as $index => $datum) {
            if ($datum['operate'] == 'regex') {
                $result[] = $datum['field'] . '=' . (stripos($datum['value'], '/') === 0 ? $datum['value'] : '/' . $datum['value'] . '/i');
            } elseif ($datum['operate'] == 'in') {
                $result[] = $datum['field'] . '=' . (stripos($datum['value'], '[') === 0 ? implode(',', (array)json_decode($datum['value'], true)) : $datum['value']);
            } else {
                $result[] = $datum['field'] . $datum['operate'] . $datum['value'];
            };
        }
        return implode('&&', $result);
    }

}
