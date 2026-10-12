<?php

// +----------------------------------------------------------------------
// | CMS 模块公共函数
// +----------------------------------------------------------------------
// | 为原 FastAdmin CMS 2.0.3 的代码提供兼容函数与配置读取能力
// +----------------------------------------------------------------------

use think\facade\Db;

if (!function_exists('get_cms_config')) {
    /**
     * 获取 CMS 模块配置（合并 bd_config 中 group 为 cms 的配置项）
     * @param string $name 配置项名称，为空时返回全部配置
     * @param mixed  $default 默认值
     * @return mixed
     */
    function get_cms_config(string $name = '', $default = null)
    {
        static $config = null;
        if ($config === null) {
            $config = [];
            $list = Db::name('config')->where('group', 'cms')->select()->toArray();
            foreach ($list as $item) {
                $value = $item['value'];
                // 与原 FastAdmin 插件配置保持一致的解析方式
                if (in_array($item['type'], ['array', 'checkbox', 'selects'])) {
                    $value = $value === '' || $value === null ? [] : json_decode($value, true);
                } elseif ($item['type'] == 'switch') {
                    $value = (bool)$value;
                }
                $config[$item['name']] = $value;
            }
        }
        // 运行时的临时覆盖
        $override = \think\facade\Config::get('cms_runtime_override', []);
        if ($override) {
            $config = array_merge($config, $override);
        }

        if ($name === '') {
            return $config;
        }
        return $config[$name] ?? $default;
    }
}

if (!function_exists('get_cms_fullconfig')) {
    /**
     * 获取 CMS 模块完整配置列表（用于后台系统配置管理）
     * @return array
     */
    function get_cms_fullconfig(): array
    {
        $list = Db::name('config')->where('group', 'cms')->order('id asc')->select()->toArray();
        foreach ($list as &$item) {
            if (in_array($item['type'], ['array', 'checkbox', 'selects'])) {
                $item['value'] = ($item['value'] === '' || $item['value'] === null) ? [] : json_decode($item['value'], true);
            }
        }
        return $list;
    }
}

if (!function_exists('set_cms_fullconfig')) {
    /**
     * 将 CMS 配置更新回数据库
     * @param array $config 配置列表
     * @return bool
     */
    function set_cms_fullconfig(array $config): bool
    {
        if (!$config) {
            return false;
        }
        foreach ($config as $item) {
            if (empty($item['name'])) {
                continue;
            }
            $value = $item['value'] ?? '';
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            Db::name('config')
                ->where('group', 'cms')
                ->where('name', $item['name'])
                ->update(['value' => $value]);
        }
        \app\admin\model\Config::clearCache();
        return true;
    }
}

if (!function_exists('cms_url')) {
    /**
     * 生成 CMS 模块前台访问地址，如 cms_url('archives/detail', [':id' => 3])
     * @param string $url    模块地址，如 cms/archives/index
     * @param array  $vars   路径或查询参数，如 [':id' => 3]
     * @param string $suffix 后缀（当前项目路由未启用后缀，此项忽略）
     * @param bool   $domain 是否返回完整域名
     * @return string
     */
    function cms_url(string $url, array $vars = [], string $suffix = '', bool $domain = false): string
    {
        $url = trim((string)$url, '/');
        $url = preg_replace('#^cms/#', '', $url);

        // 路径主键：优先 id，其次 diyname / name / eid 等（其余参数不参与路径）
        $primary = '';
        $query = [];
        foreach ($vars as $key => $value) {
            $key = ltrim((string)$key, ':');
            if ($value === '' || $value === null) {
                continue;
            }
            if (in_array($key, ['id', 'diyname', 'name', 'eid', 'tag', 'category'])) {
                if ($primary === '' && $key === 'id') {
                    $primary = (string)$value;
                }
                if ($primary === '' && !in_array($key, ['id'])) {
                    $primary = (string)$value;
                }
            } else {
                $query[$key] = $value;
            }
        }

        // 去掉结尾的 /index，得到资源路径
        $url = preg_replace('#/index$#', '', $url);

        $path = '/cms' . ($url ? '/' . $url : '');
        if ($primary !== '') {
            $path .= '/' . rawurlencode($primary);
        }

        $result = (string)url($path, $query, false);

        return $domain ? request()->domain() . $result : $result;
    }
}

if (!function_exists('get_addon_config')) {
    /**
     * 兼容别名：直接获取 CMS 配置
     * @param string $name 模块名称
     * @return mixed
     */
    function get_addon_config(string $name = 'cms')
    {
        return get_cms_config();
    }
}

if (!function_exists('addon_url')) {
    /**
     * 兼容别名：转发至 cms_url
     * @param string $url
     * @param array  $vars
     * @param string $suffix
     * @param bool   $domain
     * @return string
     */
    function addon_url(string $url, array $vars = [], string $suffix = '', bool $domain = false): string
    {
        return cms_url($url, $vars, $suffix, $domain);
    }
}

if (!function_exists('collection')) {
    /**
     * 兼容原 FastAdmin/TP5 的 collection 辅助函数
     * @param mixed $items
     * @return \think\Collection
     */
    function collection($items = [])
    {
        return \think\Collection::make($items);
    }
}

if (!function_exists('db')) {
    /**
     * 兼容原 FastAdmin/TP5 的 db 辅助函数
     * @param string $name 表名
     * @return \think\db\Query
     */
    function db(string $name = '')
    {
        return $name === '' ? \think\facade\Db::connect() : \think\facade\Db::name($name);
    }
}


if (!function_exists('addtion')) {
    /**
     * 兼容原 FastAdmin 的 addtion 调用：将逗号分隔的关联ID转换为关联记录数组
     * @param array $list 数据列表（引用）
     * @param array $with 配置，[\['field' => 'channel_ids', 'display' => 'channel_ids', 'model' => Channel::class]]
     * @param string $order 排序
     * @return void
     */
    function addtion(&$list, array $with = [], string $order = '')
    {
        if (!$list || !$with) {
            return;
        }
        foreach ($list as &$item) {
            $row = $item instanceof \think\Model ? $item->toArray() : (array)$item;
            foreach ($with as $vo) {
                $field = $vo['field'];
                $display = $vo['display'] ?? $field;
                $model = $vo['model'];
                $ids = array_filter(array_unique(explode(',', (string)($row[$field] ?? ''))));
                $result = [];
                if ($ids) {
                    $query = $model::where('id', 'in', $ids);
                    if ($order) {
                        $query->order($order);
                    }
                    foreach ($query->select() as $child) {
                        $result[] = $child;
                    }
                }
                if ($item instanceof \think\Model) {
                    $item->setAttr($display, $result);
                } else {
                    $item[$display] = $result;
                }
            }
        }
        unset($item);
    }
}


if (!function_exists('datetime')) {
    /**
     * 兼容原 FastAdmin 的 datetime 辅助函数：格式化时间戳
     * @param mixed  $time   时间戳或时间字符串
     * @param string $format 输出格式
     * @return string
     */
    function datetime($time = null, string $format = 'Y-m-d H:i:s'): string
    {
        if ($time === null || $time === '' || $time === 0 || $time === '0') {
            return '';
        }
        if (!is_numeric($time)) {
            $time = strtotime((string)$time);
        }
        return $time ? date($format, (int)$time) : '';
    }
}

if (!function_exists('human_date')) {
    /**
     * 兼容原 FastAdmin 的 human_date 辅助函数：输出友好的相对时间
     * @param int|string $timestamp 时间戳或时间字符串
     * @return string
     */
    function human_date($timestamp = 0): string
    {
        if (!$timestamp) {
            return '';
        }
        $time = is_numeric($timestamp) ? (int)$timestamp : strtotime((string)$timestamp);
        if (!$time) {
            return '';
        }

        $diff = time() - $time;
        if ($diff < 0) {
            return date('Y-m-d H:i', $time);
        }
        if ($diff < 60) {
            return '刚刚';
        }
        if ($diff < 3600) {
            return floor($diff / 60) . '分钟前';
        }
        if ($diff < 86400) {
            return floor($diff / 3600) . '小时前';
        }
        if ($diff < 2592000) {
            return floor($diff / 86400) . '天前';
        }
        return date('Y-m-d', $time);
    }
}
