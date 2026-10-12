<?php

// +----------------------------------------------------------------------
// | CMS 模块字段配置定义
// +----------------------------------------------------------------------
// | 对应原 FastAdmin app\common\model\Config 中的字段类型与校验正则列表
// +----------------------------------------------------------------------

namespace app\common\library\cms;

class Config
{
    /**
     * 字段类型列表
     * @return array
     */
    public static function getTypeList(): array
    {
        return [
            'string'   => '字符串',
            'text'     => '文本',
            'number'   => '数字',
            'radio'    => '单选按钮',
            'checkbox' => '复选框',
            'select'   => '下拉列表',
            'selects'  => '多选列表',
            'image'    => '单图',
            'images'   => '多图',
            'file'     => '单文件',
            'files'    => '多文件',
            'switch'   => '开关',
            'date'     => '日期',
            'time'     => '时间',
            'datetime' => '日期时间',
            'editor'   => '编辑器',
            'array'    => '数组',
            'custom'   => '自定义',
        ];
    }

    /**
     * 字段校验正则列表
     * @return array
     */
    public static function getRegexList(): array
    {
        return [
            ''           => '无',
            'required'   => '必填',
            'number'     => '数字',
            'integer'    => '整数',
            'float'      => '浮点数',
            'email'      => '邮箱',
            'mobile'     => '手机号',
            'url'        => 'URL',
            'date'       => '日期',
            'alpha'      => '字母',
            'alphaNum'   => '字母和数字',
            'alphaDash'  => '字母数字下划线',
            'chs'        => '汉字',
            'chsAlpha'   => '汉字字母',
            'chsAlphaNum'=> '汉字字母数字',
            'chsDash'    => '汉字字母数字下划线',
            'lower'      => '小写',
            'upper'      => '大写',
            'json'       => 'JSON',
        ];
    }

    /**
     * 兼容原 FastAdmin 的 Config::getArrayData：把数组键统一转换为字符串，便于 JSON 存储
     * @param mixed $data
     * @return array
     */
    public static function getArrayData($data): array
    {
        if (!is_array($data)) {
            return [];
        }
        $result = [];
        foreach ($data as $key => $value) {
            $result[(string)$key] = is_array($value) ? static::getArrayData($value) : $value;
        }
        return $result;
    }

    /**
     * 解析配置内容为 key => value 数组，兼容 JSON 与每行 key|value 的文本格式
     * @param mixed $value
     * @return array
     */
    public static function decode($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $value = (string)$value;
        if ($value === '') {
            return [];
        }

        $json = json_decode($value, true);
        if (is_array($json)) {
            return $json;
        }

        $result = [];
        foreach (preg_split('/[\r\n]+/', $value) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            foreach (['|', '=', ':'] as $separator) {
                if (str_contains($line, $separator)) {
                    [$key, $val] = explode($separator, $line, 2);
                    $result[trim($key)] = trim($val);
                    continue 2;
                }
            }
            $result[$line] = $line;
        }
        return $result;
    }

    /**
     * 编码配置内容，数组统一存储为 JSON
     * @param mixed $data
     * @return mixed
     */
    public static function encode($data)
    {
        if (is_array($data)) {
            return json_encode($data, JSON_UNESCAPED_UNICODE);
        }
        return $data;
    }
}
