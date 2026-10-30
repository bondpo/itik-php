<?php

// +----------------------------------------------------------------------
// | CMS API —— 首页聚合
// +----------------------------------------------------------------------

namespace app\api\controller\cms;

use think\facade\Db;

/**
 * 首页接口
 *
 * 路由：
 *   GET /api/cms/index           站点首页聚合数据（配置/导航/推荐栏目/焦点内容）
 *   GET /api/cms/config          站点基础配置（供前端初始化）
 *   GET /api/cms/navigation      导航菜单
 *   GET /api/cms/friendlink      友情链接
 */
class Index extends Base
{
    protected $noNeedLogin = ['index', 'config', 'navigation', 'friendlink'];
    protected $noNeedRight = '*';

    /**
     * 首页聚合
     */
    public function index()
    {
        $channelTree = $this->channelTree(0);
        $limit = min(10, max(1, (int)$this->request->param('limit', 6)));

        // 焦点图（block 或推荐内容）
        $blocks = \app\common\model\cms\Block::getHomeBlocks();

        // 热门内容（按浏览量）
        $hot = Db::name('cms_archives')
            ->where('status', 'normal')->whereNull('deletetime')
            ->field($this->fieldString('cms_archives', ['id','channel_id','title','image','description','views','likes','comments','publishtime']))
            ->order('views', 'desc')->limit($limit)->select()->toArray();

        // 最新内容
        $latest = Db::name('cms_archives')
            ->where('status', 'normal')->whereNull('deletetime')
            ->field('id,channel_id,title,image,description,views,publishtime')
            ->order('publishtime', 'desc')->limit($limit)->select()->toArray();

        $this->success('', [
            'config'   => $this->siteConfig(),
            'channels' => $channelTree,
            'blocks'   => $blocks,
            'hot'      => $this->translations()->rows('cms_archive', $hot),
            'latest'   => $this->translations()->rows('cms_archive', $latest),
        ]);
    }

    /**
     * 站点配置（前端初始化用）
     */
    public function config()
    {
        $this->success('', $this->siteConfig());
    }

    /**
     * 导航菜单
     */
    public function navigation()
    {
        $list = Db::name('cms_navigation')
            ->where('status', 'normal')
            ->field($this->fieldString('cms_navigation', ['id','title','image','website','intro','memo','createtime']))
            ->order('id', 'asc')->limit(50)->select()->toArray();

        $this->success('', ['list' => $list]);
    }

    /**
     * 友情链接
     */
    public function friendlink()
    {
        $list = Db::name('cms_friendlink')
            ->where('status', 'normal')
            ->field($this->fieldString('cms_friendlink', ['id','title','image','website','intro','memo','createtime']))
            ->order('id', 'asc')->limit(50)->select()->toArray();
        $this->success('', ['list' => $list]);
    }

    /* ==================== 内部方法 ==================== */

    /**
     * 站点配置白名单输出（不含密钥等敏感项）
     */
    protected function siteConfig(): array
    {
        $sys = get_sys_config();
        $cfg = $this->cmsConfig();
        return [
            'site_name'        => $sys['site_name'] ?? '',
            'site_logo'        => $sys['site_logo'] ?? '',
            'site_description' => $sys['site_description'] ?? '',
            'site_keywords'    => $sys['site_keywords'] ?? '',
            'version'          => $sys['version'] ?? '',
            'cdn_url'          => cdnurl(''),
            'cms'              => [
                'name'                => $cfg['name'] ?? 'CMS',
                'title'               => $cfg['title'] ?? '',
                'keywords'            => $cfg['keywords'] ?? '',
                'description'         => $cfg['description'] ?? '',
                'default_channel'     => (int)($cfg['default_channel'] ?? 0),
                'archives_pagesize'   => (int)($cfg['pagesize'] ?? self::DEFAULT_LIMIT),
                'comment_interval'    => (int)($cfg['comment_interval'] ?? 30),
                'comment_audit'       => (int)($cfg['comment_audit'] ?? 1),
                'diyname_style'       => $cfg['diyname_style'] ?? 'pinyin',
                'attention'           => $cfg['attention'] ?? '',
            ],
        ];
    }
}
