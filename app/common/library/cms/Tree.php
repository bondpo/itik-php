<?php

// +----------------------------------------------------------------------
// | CMS 模块树状结构扩展
// +----------------------------------------------------------------------
// | 在 badou\Tree 基础上补充原 FastAdmin fast\Tree 的下拉树生成方法
// +----------------------------------------------------------------------

namespace app\common\library\cms;

class Tree extends \badou\Tree
{
    /**
     * 初始化数据，兼容传入 Collection 或对象的情况
     * ps: 父类 $arr 声明为 array 类型，直接传入 think\Collection 会触发类型错误
     * @param mixed  $arr
     * @param string $pidname
     * @param string $nbsp
     * @param string $pk
     * @param string $childname
     * @return $this
     */
    public function init($arr = [], $pidname = null, $nbsp = null, $pk = null, $childname = null)
    {
        if ($arr instanceof \think\Collection) {
            $arr = $arr->toArray();
        } elseif (is_object($arr)) {
            $arr = (array)$arr;
        } elseif (!is_array($arr)) {
            $arr = [];
        }

        return parent::init($arr, $pidname, $nbsp, $pk, $childname);
    }

    /**
     * 生成树状下拉选项（兼容原 FastAdmin fast\Tree::getTree 的参数顺序）
     * @param int          $myid        父级ID
     * @param string       $tpl         模板，支持 @id/@name/@spacer/@selected/@disabled/@model_id
     * @param mixed        $selectedid  选中的节点ID（支持单个或数组）
     * @param array        $disabledIds 禁用的节点ID
     * @param string       $spacer      当前缩进
     * @return string
     */
    public function getTree($myid = 0, string $tpl = "<option value=@id @selected @disabled>@spacer@name</option>", $selectedid = '', array $disabledIds = [], string $spacer = ''): string
    {
        $children = $this->getChild($myid);
        if (!$children) {
            return '';
        }

        $result = '';
        foreach ($children as $item) {
            $id = $item[$this->pk] ?? '';
            $selected = is_array($selectedid) ? in_array($id, $selectedid) : ((string)$id !== '' && (string)$id === (string)$selectedid);
            $replace = [
                '@id'       => $id,
                '@name'     => $item['name'] ?? '',
                '@model_id' => $item['model_id'] ?? '',
                '@spacer'   => $spacer,
                '@selected' => $selected ? 'selected' : '',
                '@disabled' => in_array($id, $disabledIds) ? 'disabled' : '',
            ];
            $result .= str_replace(array_keys($replace), array_values($replace), $tpl);
            $result .= $this->getTree($id, $tpl, $selectedid, $disabledIds, $spacer . '&nbsp;&nbsp;');
        }

        return $result;
    }
}
