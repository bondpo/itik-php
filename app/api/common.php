<?php

// +----------------------------------------------------------------------
// | api 应用公共文件（每次访问 api 应用时加载）
// +----------------------------------------------------------------------
// | 说明：本项目开启 app_express（应用快速访问）且模块通过 AppInit 注册路由，
// |       全局 route/ 与 app/xxx/route/ 均不会生效；因此在应用公共文件中
// |       显式注册 API v1 路由，既得到规范的 RESTful 路径，又不影响其它模块。
// |
// | 重要：项目 route 配置 route_complete_match=false（前缀匹配）。若不开启完整匹配，
// |       规则 'projects' 会前缀命中 'projects/detail'、'projects/update' 等同级路由，
// |       导致请求被错误分发。故此处显式调用 completeMatch()，而非修改全局配置。
// +----------------------------------------------------------------------

use think\facade\Route;
use app\api\controller\v1\Auth;
use app\api\controller\v1\User;
use app\api\controller\v1\Plan;
use app\api\controller\v1\Billing;
use app\api\controller\v1\Project;
use app\api\controller\v1\Content;
use app\api\controller\v1\Relation;
use app\api\controller\v1\Recommend;
use app\api\controller\v1\Promotion;
use app\api\controller\v1\Consult;
use app\api\controller\cms\Index      as CmsIndex;
use app\api\controller\cms\Channel    as CmsChannel;
use app\api\controller\cms\Archives   as CmsArchives;
use app\api\controller\cms\Tag        as CmsTag;
use app\api\controller\cms\Page       as CmsPage;
use app\api\controller\cms\Special    as CmsSpecial;
use app\api\controller\cms\Search     as CmsSearch;
use app\api\controller\cms\Comment    as CmsComment;
use app\api\controller\cms\Diyform    as CmsDiyform;
use app\api\controller\cms\Order      as CmsOrder;
use app\api\controller\cms\User       as CmsUser;

Route::group('v1', function () {

    /* ---------------- 认证 ---------------- */
    Route::post('auth/email/send-code', [Auth::class, 'sendCode']);
    Route::post('auth/email/register', [Auth::class, 'register']);
    Route::post('auth/email/login', [Auth::class, 'login']);
    Route::post('auth/logout', [Auth::class, 'logout']);
    Route::post('auth/wallet/challenge', [Auth::class, 'walletChallenge']);
    Route::post('auth/wallet/verify', [Auth::class, 'walletVerify']);

    /* ---------------- 用户与角色 ---------------- */
    Route::get('users/me', [User::class, 'me']);
    Route::put('users/me', [User::class, 'update']);
    Route::get('users/me/identities', [User::class, 'identities']);
    Route::get('users/me/subscription', [User::class, 'subscription']);

    /* ---------------- 套餐（公开） ---------------- */
    Route::get('roles/plans', [Plan::class, 'index']);

    /* ---------------- 订阅与订单 ---------------- */
    Route::get('billing/orders', [Billing::class, 'orders']);
    Route::post('billing/orders', [Billing::class, 'createOrder']);
    Route::get('billing/orders/detail', [Billing::class, 'orderDetail']);
    Route::post('billing/orders/close', [Billing::class, 'closeOrder']);
    Route::get('billing/subscription', [Billing::class, 'subscription']);
    Route::post('billing/subscription/renew', [Billing::class, 'renew']);
    Route::post('billing/pay/notify', [Billing::class, 'notify']);

    /* ---------------- 项目 ---------------- */
    Route::get('projects/categories', [Project::class, 'categories']);
    Route::get('projects', [Project::class, 'index']);
    Route::get('projects/detail', [Project::class, 'detail']);
    Route::post('projects', [Project::class, 'save']);
    Route::post('projects/update', [Project::class, 'update']);
    Route::post('projects/submit', [Project::class, 'submit']);
    Route::get('projects/materials', [Project::class, 'materials']);
    Route::post('projects/materials', [Project::class, 'addMaterial']);
    Route::get('projects/members', [Project::class, 'members']);
    Route::post('projects/members/bind', [Project::class, 'bindLeader']);
    Route::get('projects/stats', [Project::class, 'stats']);
    Route::get('projects/timelines', [Project::class, 'timelines']);
    Route::post('projects/timelines', [Project::class, 'addTimeline']);
    Route::post('projects/timelines/update', [Project::class, 'updateTimeline']);
    Route::post('projects/timelines/delete', [Project::class, 'deleteTimeline']);

    /* ---------------- 内容 / 资讯 ---------------- */
    Route::get('contents', [Content::class, 'index']);
    Route::get('contents/detail', [Content::class, 'detail']);
    Route::get('contents/mine', [Content::class, 'mine']);
    Route::post('contents', [Content::class, 'save']);
    Route::post('contents/update', [Content::class, 'update']);
    Route::post('contents/submit', [Content::class, 'submit']);
    Route::post('contents/delete', [Content::class, 'delete']);

    /* ---------------- 关系 ---------------- */
    Route::post('relations/follow', [Relation::class, 'follow']);
    Route::post('relations/unfollow', [Relation::class, 'unfollow']);
    Route::get('relations/following', [Relation::class, 'following']);
    Route::get('relations/followers', [Relation::class, 'followers']);
    Route::post('relations/favorite', [Relation::class, 'favorite']);
    Route::post('relations/unfavorite', [Relation::class, 'unfavorite']);
    Route::get('relations/favorites', [Relation::class, 'favorites']);
    Route::post('relations/block', [Relation::class, 'block']);
    Route::post('relations/unblock', [Relation::class, 'unblock']);
    Route::get('relations/blocks', [Relation::class, 'blocks']);
    Route::post('relations/reports', [Relation::class, 'reports']);

    /* ---------------- 推荐与配额 ---------------- */
    Route::get('recommendations', [Recommend::class, 'index']);
    Route::post('recommendations/generate', [Recommend::class, 'generate']);
    Route::post('recommendations/view', [Recommend::class, 'markView']);
    Route::post('recommendations/respond', [Recommend::class, 'markRespond']);
    Route::post('recommendations/feedback', [Recommend::class, 'feedback']);
    Route::get('recommendations/quota', [Recommend::class, 'quota']);

    /* ---------------- 推流投放 ---------------- */
    Route::get('promotions/packages', [Promotion::class, 'packages']);
    Route::get('promotions/active', [Promotion::class, 'active']);
    Route::get('promotions/campaigns', [Promotion::class, 'campaigns']);
    Route::post('promotions/campaigns', [Promotion::class, 'createCampaign']);
    Route::get('promotions/campaigns/stats', [Promotion::class, 'stats']);
    Route::post('promotions/campaigns/cancel', [Promotion::class, 'cancelCampaign']);
    Route::post('promotions/track', [Promotion::class, 'track']);

    /* ---------------- 咨询（留言式） ---------------- */
    Route::get('consultations', [Consult::class, 'index']);
    Route::post('consultations', [Consult::class, 'create']);
    Route::get('consultations/messages', [Consult::class, 'messages']);
    Route::post('consultations/messages', [Consult::class, 'send']);
    Route::post('consultations/read', [Consult::class, 'read']);
    Route::post('consultations/close', [Consult::class, 'close']);

})->completeMatch()->allowCrossDomain();

/* ==================================================================
 * CMS 前台展示接口（前后端分离，仅 JSON，不含模板渲染）
 * 路径：/api/cms/*
 * ================================================================== */
Route::group('cms', function () {
Route::get('index', [CmsIndex::class, 'index']);
Route::get('config', [CmsIndex::class, 'config']);
Route::get('navigation', [CmsIndex::class, 'navigation']);
Route::get('friendlink', [CmsIndex::class, 'friendlink']);

Route::get('channels', [CmsChannel::class, 'index']);
Route::get('channels/detail', [CmsChannel::class, 'detail']);
Route::get('channels/archives', [CmsChannel::class, 'archives']);

Route::get('archives/detail', [CmsArchives::class, 'detail']);
Route::get('archives/relative', [CmsArchives::class, 'relative']);
Route::post('archives/like', [CmsArchives::class, 'like']);
Route::post('archives/view', [CmsArchives::class, 'view']);

Route::get('tags', [CmsTag::class, 'index']);
Route::get('tags/archives', [CmsTag::class, 'archives']);

Route::get('pages', [CmsPage::class, 'index']);
Route::get('pages/detail', [CmsPage::class, 'detail']);

Route::get('specials', [CmsSpecial::class, 'index']);
Route::get('specials/detail', [CmsSpecial::class, 'detail']);

Route::get('search', [CmsSearch::class, 'index']);
Route::get('search/hot', [CmsSearch::class, 'hot']);
Route::get('search/suggest', [CmsSearch::class, 'suggest']);
/* 评论 */
Route::get('comments', [CmsComment::class, 'index']);
Route::post('comments', [CmsComment::class, 'post']);
Route::post('comments/delete', [CmsComment::class, 'delete']);
Route::post('comments/subscribe', [CmsComment::class, 'subscribe']);

/* 自定义表单 */
Route::get('diyform', [CmsDiyform::class, 'index']);
Route::get('diyform/data', [CmsDiyform::class, 'data']);
Route::post('diyform/post', [CmsDiyform::class, 'post']);

/* 订单（内容付费） */
Route::get('orders', [CmsOrder::class, 'index']);
Route::post('orders', [CmsOrder::class, 'save']);
Route::get('orders/detail', [CmsOrder::class, 'detail']);
Route::post('orders/cancel', [CmsOrder::class, 'cancel']);
Route::post('orders/notify', [CmsOrder::class, 'notify']);

/* 会员中心 */
Route::get('user/overview', [CmsUser::class, 'overview']);
Route::get('user/archives', [CmsUser::class, 'archives']);
Route::get('user/comments', [CmsUser::class, 'comments']);
Route::get('user/favorites', [CmsUser::class, 'favorites']);
Route::post('user/favorite', [CmsUser::class, 'favorite']);

})->completeMatch()->allowCrossDomain();
