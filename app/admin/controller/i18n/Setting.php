<?php

namespace app\admin\controller\i18n;

use app\common\controller\Backend;
use app\common\model\i18n\Setting as SettingModel;

class Setting extends Backend
{
    public function index()
    {
        if ($this->request->isPost()) {
            $this->token();
            try {
                SettingModel::saveOptions($this->request->post('row/a', []));
            } catch (\Throwable $e) {
                $this->error($e->getMessage());
            }
            $this->success('翻译配置已保存');
        }
        $options = SettingModel::options();
        $options['has_secret'] = $options['secret'] !== '';
        unset($options['secret']);
        $this->assign('row', $options);
        $this->assign('languages', config('i18n.languages'));
        return $this->view->fetch();
    }
}
