<?php
namespace app;

// 应用请求对象类
class Request extends \think\Request
{

    /**
     * 登录保存参数
     * @var array
     */
    protected $loginParam = [
        'tokenData', 'user', 'uid', 'isLogin',
        'isAdminLogin', 'adminId', 'adminInfo',
        'kefuId', 'kefuInfo',
        'outId', 'outInfo',
        'storeId', 'storeStaffId', 'storeStaffInfo',
        'cashierId', 'cashierInfo',
        'clientInfo', 'userid',
        'supplierId', 'supplierInfo', 'adminType'
    ];


    /**
     * 不过滤变量名
     * @var array
     */
    protected array $except = [
        'menu_path', 'api_url', 'unique_auth',
        'description', 'custom_form', 'product_detail_diy', 'member', 'product_category_diy', 'value','val'
    ];


    /**
     * 全局过滤规则
     * app/common.php 的 filter 函数
     */
    protected $filter = 'filter';

    /**
     * 兼容 php think run（内置服务器，PHP_SAPI=cli-server）直接访问入口文件根路径的场景。
     * 此时 PATH_INFO 为空，父类会把 REQUEST_URI 整体当作 pathinfo（例如 "admin.php"），
     * 导致入口的默认路由无法命中而返回 404。这里去掉入口文件名，取真实访问路径，
     * 使其与 Apache/Nginx 下的行为保持一致。
     */
    public function pathinfo(): string
    {
        if (!is_null($this->pathinfo)) {
            return $this->pathinfo;
        }

        if (PHP_SAPI === 'cli-server' && !$this->server('PATH_INFO')) {
            $pathinfo = $this->server('REQUEST_URI');
            if (false !== $pos = strpos($pathinfo, '?')) {
                $pathinfo = substr($pathinfo, 0, $pos);
            }
            $scriptName = $this->server('SCRIPT_NAME');
            if ($scriptName && str_starts_with($pathinfo, $scriptName)) {
                $pathinfo = substr($pathinfo, strlen($scriptName));
            }
            return $this->pathinfo = empty($pathinfo) || '/' === $pathinfo ? '' : ltrim($pathinfo, '/');
        }

        return parent::pathinfo();
    }

    /**
     * CDN 回源为 HTTP、但外部访问地址已配置为 HTTPS 时，
     * 按外部访问协议生成带域名的 URL。
     */
    public function isSsl(): bool
    {
        if (parent::isSsl()) {
            return true;
        }

        return strtolower((string) parse_url((string) config('badouadmin.app_url'), PHP_URL_SCHEME)) === 'https';
    }



    /**
     * @param $array
     * @return array
     */
    public function filterArrayValues($array)
    {
        $result = [];
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                // 如果值是数组，递归调用 filterArrayValues
                $result[$key] = in_array($key, $this->except) ? $value : $this->filterArrayValues($value);
            } else {
                if (in_array($key, $this->except) || is_int($value) || is_null($value)) {
                    $result[$key] = $value;
                } else {
                    // 如果值是字符串，过滤特殊字符
                    $result[$key] = filter_str($value);
                }

            }
        }
        return $result;
    }


    /**
     * 获取请求的数据
     * @param array $params
     * @param bool $suffix
     * @param bool $filter
     * @return array
     */
    public function more(array $params, bool $suffix = false, bool $filter = true): array
    {
        $p = [];
        $i = 0;
        foreach ($params as $param) {
            if (!is_array($param)) {
                $p[$suffix == true ? $i++ : $param] = $this->param($param);
            } else {
                if (!isset($param[1])) $param[1] = null;
                if (!isset($param[2])) $param[2] = '';
                if (is_array($param[0])) {
                    $name = is_array($param[1]) ? $param[0][0] . '/a' : $param[0][0] . '/' . $param[0][1];
                    $keyName = $param[0][0];
                } else {
                    $name = is_array($param[1]) ? $param[0] . '/a' : $param[0];
                    $keyName = $param[0];
                }

                $p[$suffix == true ? $i++ : ($param[3] ?? $keyName)] = $this->param($name, $param[1], $param[2]);
            }
        }

        if ($filter && $p) {
            $p = $this->filterArrayValues($p);
        }

        return $p;
    }


    /**
     * 获取get参数
     * @param array $params
     * @param bool $suffix
     * @param bool $filter
     * @return array
     */
    public function getMore(array $params, bool $suffix = false, bool $filter = true): array
    {
        return $this->more($params, $suffix, $filter);
    }

    /**
     * 获取post参数
     * @param array $params
     * @param bool $suffix
     * @param bool $filter
     * @return array
     */
    public function postMore(array $params, bool $suffix = false, bool $filter = true): array
    {
        return $this->more($params, $suffix, $filter);
    }

    /**
     * 获取用户访问端
     * @return array|string|null
     */
    public function getFromType()
    {
        return $this->header('Form-type', '');
    }

    /**
     * 当前访问端
     * @param string $terminal
     * @return bool
     */
    public function isTerminal(string $terminal)
    {
        return strtolower($this->getFromType()) === $terminal;
    }

    /**
     * 是否是H5端
     * @return bool
     */
    public function isH5()
    {
        return $this->isTerminal('h5');
    }

    /**
     * 是否是微信端
     * @return bool
     */
    public function isWechat()
    {
        return $this->isTerminal('wechat');
    }

    /**
     * 是否是小程序端
     * @return bool
     */
    public function isRoutine()
    {
        return $this->isTerminal('routine');
    }

    /**
     * 是否是app端
     * @return bool
     */
    public function isApp()
    {
        return $this->isTerminal('app');
    }

    /**
     * 是否是app端
     * @return bool
     */
    public function isPc()
    {
        return $this->isTerminal('pc');
    }



    /**
     * 获取ip
     * @return string
     */
    public function ip(): string
    {
        if ($this->server('HTTP_CLIENT_IP', '')) {
            $ip = $this->server('HTTP_CLIENT_IP', '');
        } elseif ($this->server('HTTP_X_REAL_IP', '')) {
            $ip = $this->server('HTTP_X_REAL_IP', '');
        } elseif ($this->server('HTTP_X_FORWARDED_FOR', '')) {
            $ip = $this->server('HTTP_X_FORWARDED_FOR', '');
            $ips = explode(',', $ip);
            $ip = $ips[0];
        } elseif ($this->server('REMOTE_ADDR', '')) {
            $ip = $this->server('REMOTE_ADDR', '');
        } else {
            $ip = '0.0.0.0';
        }
        return $ip;
    }



    public function hasMacro(string $name): bool
    {
        return !!$this->{$name};
    }



    public function __call($method, $parameters)
    {
        if (in_array($method, $this->loginParam)) {
            $result = $this->{$method};
            if (is_callable($result)) {
                return call_user_func_array($result, $parameters);
            } else {
                return $result;
            }
        }
        return null;
    }
}
