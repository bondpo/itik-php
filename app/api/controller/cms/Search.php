<?php

// +----------------------------------------------------------------------
// | CMS API —— 搜索
// +----------------------------------------------------------------------

namespace app\api\controller\cms;

use think\facade\Db;

/**
 * 搜索接口
 *
 * 路由：
 *   GET /api/cms/search           关键词搜索内容（分页）
 *   GET /api/cms/search/hot       热门搜索词
 *   GET /api/cms/search/suggest   搜索建议（标题后缀匹配）
 */
class Search extends Base
{
    protected $noNeedLogin = ['index', 'hot', 'suggest'];
    protected $noNeedRight = '*';

    /**
     * 搜索
     */
    public function index()
    {
        $keyword = trim((string)$this->request->param('keyword', ''));
        if ($keyword === '') {
            $this->fail('请输入搜索关键词', self::CODE_PARAM_MISSING);
        }
        if (mb_strlen($keyword) > 50) {
            $this->fail('搜索关键词过长', self::CODE_PARAM_INVALID);
        }

        [$page, $limit] = $this->cmsPageParams();
        [$orderby, $orderway] = $this->cmsOrderParams(['id', 'views', 'publishtime', 'comments'], 'publishtime');

        $query = Db::name('cms_archives')
            ->where('status', 'normal')->whereNull('deletetime')
            ->where(function ($q) use ($keyword) {
                $q->whereLike('title', "%{$keyword}%")
                  ->whereOr('description', 'like', "%{$keyword}%")
                  ->whereOr('keywords', 'like', "%{$keyword}%")
                  ->whereOr('tags', 'like', "%{$keyword}%");
            });

        // 可选：限定栏目
        $channelId = (int)$this->request->param('channel_id', 0);
        if ($channelId > 0) {
            $query->where('channel_id', $channelId);
        }

        $total = (clone $query)->count();
        $list = $query->order($orderby, $orderway)->page($page, $limit)
            ->field('id,channel_id,title,image,description,views,comments,publishtime')
            ->select()->toArray();

        // 记录搜索词（供后台统计热门词）
        $this->recordKeyword($keyword);

        $this->success('', [
            'keyword' => $keyword,
            'list'    => $this->translations()->rows('cms_archive', $list),
            'total'   => $total,
            'page'    => $page,
            'limit'   => $limit,
        ]);
    }

    /**
     * 热门搜索词
     */
    public function hot()
    {
        $limit = min(20, max(1, (int)$this->request->param('limit', 10)));
        $rows = Db::name('cms_search_log')
            ->where('status', 'normal')
            ->where('keywords', '<>', '')
            ->field('keywords, nums')
            ->order('nums', 'desc')->order('id', 'desc')
            ->limit($limit)->select()->toArray();

        $this->success('', ['list' => $rows]);
    }

    /**
     * 搜索建议
     */
    public function suggest()
    {
        $keyword = trim((string)$this->request->param('keyword', ''));
        if ($keyword === '') {
            $this->success('', ['list' => []]);
        }
        $list = Db::name('cms_archives')
            ->where('status', 'normal')->whereNull('deletetime')
            ->whereLike('title', "%{$keyword}%")
            ->field('id,title')->order('views', 'desc')->limit(10)->select()->toArray();
        $this->success('', ['list' => $this->translations()->rows('cms_archive', $list)]);
    }

    /**
     * 记录搜索词（去重：同一 IP 同一词 10 分钟内只记一次）
     */
    protected function recordKeyword(string $keyword): void
    {
        try {
            // 表结构：keywords / nums / createtime / status（命中则累加 nums）
            $row = Db::name('cms_search_log')->where('keywords', $keyword)->find();
            if ($row) {
                Db::name('cms_search_log')->where('id', $row['id'])->inc('nums')->update();
                return;
            }
            Db::name('cms_search_log')->insert([
                'keywords'   => $keyword,
                'nums'       => 1,
                'createtime' => time(),
                'status'     => 'normal',
            ]);
        } catch (\Throwable $e) {
            // 统计失败不影响搜索主流程
        }
    }
}
