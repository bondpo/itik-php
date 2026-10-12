<?php

namespace app\admin\model\cms;

use think\Exception;
use modules\cms\library\Model;

class Tag extends Model
{

    // 表名
    protected $name = 'cms_tag';
    // 自动写入时间戳字段
    protected $autoWriteTimestamp = false;
    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    // 追加属性
    protected $append = [
        'url'
    ];
    protected static $config = [];

    protected function initializeEvents()
    {
        static::$config = get_addon_config('cms');
        self::beforeUpdate(function ($row) {
            $changedData = $row->getChangedData();
            if (isset($changedData['name'])) {
                $exists = \think\facade\Db::name("cms_tag")->where('name', $changedData['name'])->where('id', '<>', $row['id'])->find();
                if ($exists) {
                    throw new Exception("标签已存在");
                }
            }
        });
        self::afterDelete(function ($row) {
            //删除关联的TAG数据
            Taggable::where('tag_id', $row['id'])->delete();
        });
        self::afterUpdate(function ($row) {
            $originData = $row->getOriginData();
            $changedData = $row->getChangedData();

            if (isset($changedData['name'])) {
                $value = $originData['name'];
                $replacement = $changedData['name'];
                $archivesList = \think\facade\Db::name("cms_archives")->where('id', 'in', function ($query) use ($row) {
                    $query->name("cms_taggable")->where('tag_id', $row['id'])->field('archives_id');
                })->select();
                foreach ($archivesList as $index => $item) {
                    $tagsArr = explode(',', $item['tags']);
                    $tagsArr = array_map(function ($v) use ($value, $replacement) {
                        return $v == $value ? $replacement : $v;
                    }, $tagsArr);
                    \think\facade\Db::name("cms_archives")->where('id', $item['id'])->update(['tags' => implode(',', $tagsArr)]);
                }
            }
        });
    }

    public function getOriginData()
    {
        return $this->getOrigin();
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
        $diyname = isset($data['name']) && $data['name'] ? $data['name'] : $data['id'];
        $time = $data['createtime'] ?? time();

        $vars = [
            ':id'      => $data['id'],
            ':name'    => $diyname,
            ':diyname' => $diyname,
            ':year'    => date("Y", $time),
            ':month'   => date("m", $time),
            ':day'     => date("d", $time)
        ];
        return addon_url('cms/tag/index', $vars, static::$config['urlsuffix'], $domain);
    }

    /**
     * 刷新标签关联（原 FastAdmin CMS 公共模型方法）
     * @param string $tags        标签，多个以逗号分隔
     * @param int    $archives_id 文档ID
     * @return bool
     */
    public static function refreshTags($tags, $archives_id)
    {
        $field = 'nums';
        $tags = str_replace('，', ',', (string)$tags);
        $tagsArr = array_unique(array_filter(array_map('strtolower', explode(',', $tags))));

        // 取出该文档已关联的标签
        $taggableTable = \think\facade\Db::name('cms_taggable')->getTable();
        $tagsList = self::alias('t')
            ->join($taggableTable . ' ta', 'ta.tag_id = t.id', 'RIGHT')
            ->where('ta.archives_id', $archives_id)
            ->field('t.*,ta.archives_id,ta.id AS taggable_id')
            ->select();

        foreach ($tagsList as $item) {
            $name = strtolower((string)$item['name']);
            if (!in_array($name, $tagsArr)) {
                // 标签已被移除：数量减一、删除关联
                isset($item[$field]) && $item->setDec($field);
                Taggable::where('id', $item['taggable_id'])->delete();
            } else {
                $tagsArr = array_diff($tagsArr, [$name]);
            }
        }

        if (!$tagsArr) {
            return true;
        }

        $insertTagIds = [];
        // 已存在的标签数量加一
        foreach (self::where('name', 'in', $tagsArr)->select() as $item) {
            $name = strtolower((string)$item['name']);
            $item->setInc($field);
            $tagsArr = array_diff($tagsArr, [$name]);
            $insertTagIds[] = $item['id'];
        }

        // 新增未存在的标签
        if ($tagsArr) {
            foreach (explode(',', $tags) as $item) {
                $name = strtolower($item);
                if (in_array($name, $tagsArr)) {
                    $tagsArr = array_diff($tagsArr, [$name]);
                    $object = self::create(['name' => $item, $field => 1]);
                    $insertTagIds[] = $object->id;
                }
            }
        }

        // 写入关联表
        $insertList = [];
        foreach ($insertTagIds as $tagId) {
            $insertList[] = ['tag_id' => $tagId, 'archives_id' => $archives_id, 'createtime' => time()];
        }
        if ($insertList) {
            (new Taggable())->insertAll($insertList);
        }

        return true;
    }
}
