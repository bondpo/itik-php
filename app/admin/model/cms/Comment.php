<?php

namespace app\admin\model\cms;

use app\common\model\User;
use think\facade\Db;
use app\common\library\cms\Model;
use think\model\concern\SoftDelete;

class Comment extends Model
{
    use SoftDelete;

    // 表名
    protected $name = 'cms_comment';

    // 自动写入时间戳字段
    protected $autoWriteTimestamp = true;

    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $deleteTime = 'deletetime';

    // 追加属性
    protected $append = [
        'type_text',
        'status_text'
    ];

    protected function initializeEvents()
    {
        $config = get_addon_config('cms');
        self::afterWrite(function ($row) use ($config) {
            $changedData = $row->getChangedData();
            if (isset($changedData['status']) && isset($config['score']['postcomment'])) {
                if ($changedData['status'] == 'normal') {
                    User::score($config['score']['postcomment'], $row['user_id'], '发表评论');
                } else {
                    User::score(-$config['score']['postcomment'], $row['user_id'], '删除评论');
                }
            }
            self::refreshSourceComments($row['id']);
        });
        self::afterDelete(function ($row) use ($config) {
            $data = Comment::withTrashed()->where('id', $row['id'])->find();
            if ($data) {
                if ($data['status'] == 'normal' && isset($config['score']['postcomment'])) {
                    User::score(-$config['score']['postcomment'], $row['user_id'], '删除评论');
                }
            }
            self::refreshSourceComments($row['id']);
        });
    }

    /**
     * 刷新评论
     * @param int $id 评论ID
     * @return bool
     */
    public static function refreshSourceComments($id)
    {
        $row = self::withTrashed()->where('id', $id)->find();
        if (!$row) {
            return false;
        }
        $model = $row->source;
        if ($model && $model instanceOf \think\Model) {
            $comments = self::where('type', $row['type'])->where('aid', $row['aid'])->where('status', 'normal')->count();
            $model->save(['comments' => $comments]);
        }
        return true;
    }

    public function getTypeList()
    {
        return ['archives' => __('Archives'), 'page' => __('Page'), 'special' => __('Special')];
    }

    public function getStatusList()
    {
        return ['normal' => __('Normal'), 'hidden' => __('Hidden')];
    }

    public function getTypeTextAttr($value, $data)
    {
        $value = $value ? $value : $data['type'];
        $list = $this->getTypeList();
        return $list[$value] ?? '';
    }


    public function getStatusTextAttr($value, $data)
    {
        $value = $value ? $value : $data['status'];
        $list = $this->getStatusList();
        return $list[$value] ?? '';
    }

    /**
     * 根据类型和ID删除
     */
    public static function deleteByType($type, $aid, $force = false)
    {
        if (!$force) {
            //删除评论
            $commentList = Comment::where(['type' => $type, 'aid' => $aid])->select();
            foreach ($commentList as $index => $item) {
                $item->delete();
            }
        } else {
            //强制删除评论
            $commentList = Comment::withTrashed()->where(['type' => $type, 'aid' => $aid])->select();
            foreach ($commentList as $index => $item) {
                $item->delete(true);
            }
        }
    }

    public function user()
    {
        return $this->belongsTo('\app\common\model\User', 'user_id', '', [], 'LEFT');
    }

    /**
     * 关联文档模型
     */
    public function archives()
    {
        return $this->belongsTo('Archives', 'aid', '', [], 'LEFT');
    }

    /**
     * 关联单页模型
     */
    public function spage()
    {
        return $this->belongsTo("app\admin\model\cms\Page", 'aid', '', [], 'LEFT');
    }

    /**
     * 关联专题模型
     */
    public function special()
    {
        return $this->belongsTo("app\admin\model\cms\Special", 'aid', '', [], 'LEFT');
    }

    /**
     * 关联模型
     */
    public function source()
    {
        $type = $this->getData('type');
        $modelArr = ['page' => 'Page', 'archives' => 'Archives', 'special' => 'Special'];
        $model = isset($modelArr[$type]) ? $modelArr[$type] : $modelArr['archives'];
        return $this->belongsTo($model, "aid");
    }
}
