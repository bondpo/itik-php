<?php

namespace app\common\model\cms;

use app\common\library\cms\Service;
use think\facade\Cache;
use think\facade\Db;
use app\common\library\cms\Model;
use think\View;

/**
 * 区块模型
 */
class Block extends Model
{
    protected $name = "cms_block";
    // 开启自动写入时间戳字段
    protected $autoWriteTimestamp = 'int';
    // 定义时间戳字段名
    protected $createTime = '';
    protected $updateTime = '';
    // 追加属性
    protected $append = [
    ];
    protected static $config = [];

    protected static $tagCount = 0;

    protected function initializeEvents()
    {
        $config = get_addon_config('cms');
        self::$config = $config;
    }

    public function getAttr($name)
    {
        //获取自定义字段关联表数据
        if (!isset($this->data[$name]) && preg_match("/(.*)_value\$/i", $name, $matches)) {
            $key = $this->data[$matches[1]] ?? '';
            if (!$key) {
                return '';
            }
            return Service::getRelationFieldValue('block', 0, $matches[1], $key);
        }
        return parent::getAttr($name);
    }

    public function getImageAttr($value, $data)
    {
        $value = $value ? $value : self::$config['default_block_img'];
        return cdnurl($value);
    }

    /**
     * 内容：前后端分离后直接返回原始内容，由前端渲染
     */
    public function getContentAttr($value, $data)
    {
        return $data['content'] ?? $value;
    }

    public function getHasimageAttr($value, $data)
    {
        return (bool)$this->getData("image");
    }

    public static function applyLanguage($query, ?string $language = null): void
    {
        $language = $language ?? app()->lang->getLangSet();
        $query->whereIn('lang', ['', $language]);
    }

    public static function getHomeBlocks(int $limit = 6): array
    {
        $now = time();
        $query = Db::name('cms_block')->where('status', 'normal')
            ->where(function ($query) use ($now) {
                $query->whereNull('begintime')->whereOr('begintime', 0)->whereOr('begintime', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('endtime')->whereOr('endtime', 0)->whereOr('endtime', '>=', $now);
            });
        self::applyLanguage($query);
        return $query->field('id,type,name,title,image,url,content,weigh,begintime,endtime,lang')
            ->order('weigh', 'desc')->order('id', 'desc')->limit($limit)->select()->toArray();
    }

    /**
     * 获取区块列表
     * @param $params
     * @return false|\PDOStatement|string|\think\Collection
     */
    public static function getBlockList($params)
    {
        $params['lang'] = app()->lang->getLangSet();
        $config = get_addon_config('cms');
        $type = empty($params['type']) ? '' : $params['type'];
        $name = empty($params['name']) ? '' : $params['name'];
        $condition = empty($params['condition']) ? '' : $params['condition'];
        $field = empty($params['field']) ? '*' : $params['field'];
        $row = empty($params['row']) ? 10 : (int)$params['row'];
        $orderby = empty($params['orderby']) ? 'id' : $params['orderby'];
        $orderway = empty($params['orderway']) ? 'desc' : strtolower($params['orderway']);
        $limit = empty($params['limit']) ? $row : $params['limit'];
        $cache = !isset($params['cache']) ? $config['cachelifetime'] : $params['cache'];
        $imgwidth = empty($params['imgwidth']) ? '' : $params['imgwidth'];
        $imgheight = empty($params['imgheight']) ? '' : $params['imgheight'];
        $orderway = in_array($orderway, ['asc', 'desc']) ? $orderway : 'desc';
        $paginate = !isset($params['paginate']) ? false : $params['paginate'];

        list($cacheKey, $cacheExpire) = Service::getCacheKeyExpire('blocklist', $params);

        self::$tagCount++;

        $where = ['status' => 'normal'];
        if ($type !== '') {
            $where['type'] = $type;
        }
        if ($name !== '') {
            $where['name'] = $name;
        }
        $order = $orderby == 'rand' ? Db::raw('rand()') : (preg_match("/\,|\s/", $orderby) ? $orderby : "{$orderby} {$orderway}");
        $order = $orderby == 'weigh' ? $order . ',id DESC' : $order;

        $blockModel = self::where($where)
            ->where($condition)
            ->field($field)
            ->orderRaw($order);

        self::applyLanguage($blockModel);
        if ($paginate) {
            list($listRows, $simple, $config) = Service::getPaginateParams('bpage' . self::$tagCount, $params);
            $list = $blockModel->paginate($listRows, $simple, $config);
        } else {
            $list = $blockModel->limit($limit)->cache($cacheKey, $cacheExpire, 'cms')->select();
        }

        Service::appendTextAndList('block', 0, $list, true);

        self::render($list, $imgwidth, $imgheight);
        return $list;
    }

    public static function render(&$list, $imgwidth, $imgheight)
    {
        $width = $imgwidth ? 'width="' . $imgwidth . '"' : '';
        $height = $imgheight ? 'height="' . $imgheight . '"' : '';
        $time = time();
        foreach ($list as $k => &$v) {
            if (($v['begintime'] && $time < $v['begintime']) || ($v['endtime'] && $time > $v['endtime'])) {
                unset($list[$k]);
                continue;
            }
            $v['textlink'] = '<a href="' . $v['url'] . '">' . $v['title'] . '</a>';
            $v['imglink'] = '<a href="' . $v['url'] . '"><img src="' . $v['image'] . '" ' . $width . ' ' . $height . ' /></a>';
            $v['img'] = '<img src="' . $v['image'] . '" ' . $width . ' ' . $height . ' />';
        }
        return $list;
    }

    /**
     * 获取区块内容
     * @param $params
     * @return string
     */
    public static function getBlockContent($params)
    {
        $params['lang'] = app()->lang->getLangSet();
        $fieldName = isset($params['id']) ? 'id' : 'name';
        $value = $params[$fieldName] ?? '';
        $field = $params['field'] ?? '';

        list($cacheKey, $cacheExpire) = Service::getCacheKeyExpire('blockcontent', $params);

        $query = self::where($fieldName, $value)->where('status', 'normal');
        self::applyLanguage($query);
        $row = $query->cache($cacheKey, $cacheExpire, 'cms')->find();
        $result = '';
        if ($row) {
            Service::appendTextAndList('block', 0, $row);

            $content = $row->getData('content');
            if ($field && isset($row[$field])) {
                $result = $row->getData($field);
            } else {
                if ($content) {
                    $result = $content;
                } elseif ($row['image']) {
                    $result = '<img src="' . $row['image'] . '" class="img-responsive"/>';
                } else {
                    $result = $row['title'];
                }
                if ($row['url'] && !$content) {
                    $result = $row['url'] ? '<a href="' . (preg_match("/^https?:\/\/(.*)/i", $row['url']) ? $row['url'] : url($row['url'])) . '" target="_blank">' . $result . '</a>' : $result;
                }
            }
            $row['begintime'] = (int)$row['begintime'];
            $row['endtime'] = (int)$row['endtime'];
        }
        return $result;
    }
}
