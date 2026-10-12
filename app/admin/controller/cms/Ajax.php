<?php

namespace app\admin\controller\cms;

use app\common\library\cms\aip\AipContentCensor;
use app\common\library\cms\SensitiveHelper;
use app\common\library\cms\Service;
use app\common\controller\Backend;
use think\facade\Cache;
use think\facade\Config;
use think\facade\Db;

/**
 * Ajax 辅助接口
 *
 * @icon fa fa-circle-o
 * @internal
 */
class Ajax extends Backend
{
    /**
     * 模型对象
     */
    protected $model = null;
    protected $noNeedRight = ['*'];

    /**
     * 获取模板列表
     * @internal
     */
    public function get_template_list()
    {
        $files = [];
        $keyValue = $this->request->request("keyValue");
        if (!$keyValue) {
            $type = $this->request->request("type");
            $this->request->filter(['strip_tags']);
            $themeDir = root_path() . 'public' . DS . 'assets' . DS . 'addons' . DS . 'cms' . DS;
            if (is_dir($themeDir) && ($dh = opendir($themeDir))) {
                while (false !== ($filename = readdir($dh))) {
                    if ($filename == '.' || $filename == '..') {
                        continue;
                    }
                    if ($type) {
                        $rule = $type == 'channel' ? '(channel|list)' : $type;
                        if (!preg_match("/^{$rule}(.*)/i", $filename)) {
                            continue;
                        }
                    }
                    $files[] = ['name' => $filename];
                }
                closedir($dh);
            }
        } else {
            $files[] = ['name' => $keyValue];
        }
        $filename = array_column($files, 'name');
        array_multisort($filename, SORT_ASC, $files);
        return ['total' => count($files), 'list' => $files];
    }

    /**
     * 检查内容是否包含违禁词
     */
    public function check_content_islegal()
    {
        $config = get_cms_config();
        $content = $this->request->post('content');
        if (!$content) {
            $this->error(__('Please input your content'));
        }
        if (($config['audittype'] ?? 'local') == 'local') {
            $wordsFile = app_path('common/data/cms/words.dic');
            $handle = SensitiveHelper::init()->setTreeByFile($wordsFile);
            $arr = $handle->getBadWord($content);
            if ($arr) {
                $this->error(__('The content is not legal'), null, $arr);
            } else {
                $this->success(__('The content is legal'));
            }
        } else {
            $client = new AipContentCensor($config['aip_appid'] ?? '', $config['aip_apikey'] ?? '', $config['aip_secretkey'] ?? '');
            $result = $client->textCensorUserDefined($content);
            if (isset($result['conclusionType']) && $result['conclusionType'] > 1) {
                $msg = [];
                foreach ($result['data'] as $datum) {
                    $msg[] = $datum['msg'];
                }
                $this->error(implode("<br>", $msg), null, []);
            } else {
                $this->success(__('The content is legal'));
            }
        }
    }

    /**
     * 获取关键字与摘要
     */
    public function get_content_keywords()
    {
        $title = $this->request->post('title');
        $tags = $this->request->post('tags', '');
        $content = $this->request->post('content');
        if (!$content) {
            $this->error(__('Please input your content'));
        }
        $keywords = Service::getContentTags($title);
        $keywords = in_array($title, $keywords) ? [] : $keywords;
        $keywords = array_filter(array_merge([$tags], $keywords));
        $description = mb_substr(strip_tags($content), 0, 200);
        $data = [
            "keywords"    => implode(',', $keywords),
            "description" => $description
        ];
        $this->success("提取成功", null, $data);
    }

    /**
     * 获取标题拼音
     */
    public function get_title_pinyin()
    {
        $config = get_cms_config();
        $title = $this->request->post("title");
        $delimiter = $this->request->post("delimiter", "");
        $pinyin = new \Overtrue\Pinyin\Pinyin('Overtrue\Pinyin\MemoryFileDictLoader');
        if ($title) {
            if (!empty($config['autopinyin'])) {
                $result = $pinyin->permalink($title, $delimiter);
                $this->success("", null, ['pinyin' => $result]);
            } else {
                $this->error();
            }
        } else {
            $this->error(__('Parameter %s can not be empty', 'name'));
        }
    }

    /**
     * 获取表字段列表
     * @internal
     */
    public function get_fields_list()
    {
        $table = $this->request->request('table');
        $fieldList = Service::getTableFields($table);
        $this->success("", null, ['fieldList' => $fieldList]);
    }

    /**
     * 获取自定义字段列表HTML
     * @internal
     */
    public function get_fields_html()
    {
        $this->view->engine->layout(false);
        $source = $this->request->post('source');
        $id = $this->request->post('id/d');
        if ($source === 'channel') {
            $values = Db::name("cms_channel")->where('id', $id)->find() ?: [];
            $fields = Service::getCustomFields($source, 0, $values);
            $this->view->assign('fields', $fields);
            $this->view->assign('values', $values);
            $this->success('', null, ['html' => $this->view->fetch('cms/common/fields')]);
        } else {
            $this->error(__('Please select type'));
        }
    }

    public function get_page_list()
    {
        $pageList = [
            ['path' => 'https://www.baidu.com', 'name' => '外部链接'],
            ['path' => '/pages/index/index', 'name' => '主页'],
            ['path' => '/pages/index/my', 'name' => '个人中心'],
            ['path' => '/pages/article/article', 'name' => '文章列表'],
            ['path' => '/pages/search/search', 'name' => '搜索'],
            ['path' => '/pages/tag/tag', 'name' => '标签'],
        ];
        $this->view->assign('pageList', $pageList);
        return $this->view->fetch('cms/common/pages');
    }

    /**
     * 获取选择链接列表
     */
    public function get_link_list()
    {
        if ($this->request->isAjax()) {
            return [
                'rows'  => [],
                'total' => 0
            ];
        }
        $this->view->assign('typeList', []);
        return $this->view->fetch('cms/common/links');
    }

    // 配置
    public function config()
    {
        $this->error("系统已采用内置模块架构，不再支持独立插件扩展");
    }

    /**
     * 检测配置项是否合法
     * @internal
     */
    public function check_config_available()
    {
        $name = $this->request->request('name');
        $value = $this->request->request('value');
        $name = substr($name, 4, -1);
        if (!$name) {
            $this->error(__('Parameter %s can not be empty', 'name'));
        }
        if ($name == 'theme') {
            if (!preg_match("/^([a-zA-Z0-9\-_]+)$/i", $value)) {
                $this->error("只支持字母数字下划线");
            }
        } elseif ($name == 'archivesratio') {
            $valueArr = explode(':', $value);
            if (!isset($valueArr[1])) {
                $this->error('格式不正确');
            }
            if (bcadd($valueArr[0], $valueArr[1], 2) != 1) {
                $this->error('分成占比相加必须等于1');
            }
        } elseif ($name == 'cachelifetime') {
            if (!is_numeric($value) && !in_array($value, ['true', 'false'])) {
                $this->error("格式不正确,只支持 数字/true");
            }
        }
        $this->success();
    }

    /**
     * 清除缓存
     */
    public function clearcache()
    {
        Cache::clear("cms");
        @rmdirs(TEMP_PATH, false);
        $this->success("");
    }
}
