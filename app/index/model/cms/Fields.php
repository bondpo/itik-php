<?php

namespace app\index\model\cms;

class Fields extends \think\Model
{

    // 表名
    protected $name = 'cms_fields';
    // 自动写入时间戳字段
    protected $autoWriteTimestamp = 'int';
    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    // 追加属性
    protected $append = [
        'content_list',
        'isrequire',
    ];
    protected $type = [
        'setting'   => 'json',
        'favisible' => 'json',
    ];
    protected static $listFields = ['select', 'selects', 'checkbox', 'radio', 'array', 'selectpage', 'selectpages'];

    protected function initializeEvents()
    {
    }

    public function getIsrequireAttr($value, $data)
    {
        return $data['rule'] && in_array('required', explode(',', $data['rule']));
    }

    public function getDownloadListAttr($value, $data)
    {
        $config = get_addon_config('cms');
        $downloadtype = $config['downloadtype'];
        $result = [];
        foreach ($downloadtype as $index => $item) {
            $result[] = ['name' => $index, 'url' => '', 'password' => ''];
        }
        return json_encode($result);
    }

    public function getExtendHtmlAttr($value, $data)
    {
        $result = preg_replace_callback("/\{([a-zA-Z]+)\}/", function ($matches) use ($data) {
            if (isset($data[$matches[1]])) {
                return $data[$matches[1]];
            }
        }, $data['extend']);
        return $result;
    }

    /**
     * 获取字典列表字段
     * @return array
     */
    public static function getListFields()
    {
        return self::$listFields;
    }

    public function getContentListAttr($value, $data)
    {
        $result = \modules\cms\library\Config::decode($data['content'] ?? '');
        return $result ?: [];
    }

    public function getFilterListAttr($value, $data)
    {
        $result = \modules\cms\library\Config::decode($data['filterlist'] ?? '');
        return $result ?: [];
    }

    public static function getFieldsContentList($source, $source_id = 0)
    {
        $list = Fields::where('source', $source)
            ->where('source_id', $source_id)
            ->field('id,name,type,content')
            ->where('status', 'normal')
            ->cache(true, null, 'cms')
            ->select();
        $fieldsList = [];
        $listFields = Fields::getListFields();
        foreach ($list as $index => $item) {
            if (in_array($item['type'], $listFields)) {
                $fieldsList[$item['name']] = $item['content_list'];
            }
        }
        return $fieldsList;
    }

    public function getFavisibleTextAttr($value, $data)
    {
        if (!$data['favisible']) {
            return '';
        }
        $data['favisible'] = is_array($data['favisible']) ? $data['favisible'] : (array)json_decode($data['favisible'], true);
        $result = [];
        foreach ($data['favisible'] as $index => $datum) {
            // 兼容旧数据中 favisible 为字符串数组的情况
            if (!is_array($datum)) {
                continue;
            }
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
