<?php

// +----------------------------------------------------------------------
// | CMS 模块模型基类
// +----------------------------------------------------------------------
// | 兼容原 FastAdmin CMS 模型的事件注册写法（beforeInsert/afterWrite 等），
// | 使移植过来的模型可以继续在 init() 中注册事件回调
// +----------------------------------------------------------------------

namespace app\common\library\cms;

use think\Model as BaseModel;

class Model extends BaseModel
{
    /**
     * 各模型注册的事件回调
     * @var array
     */
    protected static $eventCallbacks = [];

    /**
     * 已完成事件注册的模型标记，避免每次实例化重复注册回调
     * @var array
     */
    protected static $eventInitialized = [];

    /**
     * 仅首次实例化时执行事件注册，避免回调重复触发
     * @return void
     */
    protected function init()
    {
        // 时间戳字段统一按整型处理，避免写入日期字符串导致数据截断（每次实例化均需设置）
        // 注意：think-orm 4 中这些配置保存在 option 中，不能通过属性访问（会误触发字段读取）
        $type = $this->getOption('type', []);
        foreach (['createTime', 'updateTime', 'deleteTime'] as $attr) {
            $field = $this->getOption($attr);
            if (is_string($field) && $field !== '') {
                $type[$field] = 'int';
            }
        }
        $this->setOption('type', $type);
        // 清除字段类型缓存，保证上面的类型定义生效
        $this->setOption('schema', []);
        // think-orm 4 的 getMappingName 未处理未配置 mapping 的情况，这里补默认空映射
        if ($this->getOption('mapping') === null) {
            $this->setOption('mapping', []);
        }

        if (isset(static::$eventInitialized[static::class])) {
            return;
        }
        static::$eventInitialized[static::class] = true;

        if (method_exists($this, 'initializeEvents')) {
            $this->initializeEvents();
        }
    }

    public static function beforeInsert(callable $callback)
    {
        static::addEventCallback('before_insert', $callback);
    }

    public static function afterInsert(callable $callback)
    {
        static::addEventCallback('after_insert', $callback);
    }

    public static function beforeUpdate(callable $callback)
    {
        static::addEventCallback('before_update', $callback);
    }

    public static function afterUpdate(callable $callback)
    {
        static::addEventCallback('after_update', $callback);
    }

    public static function beforeWrite(callable $callback)
    {
        static::addEventCallback('before_write', $callback);
    }

    public static function afterWrite(callable $callback)
    {
        static::addEventCallback('after_write', $callback);
    }

    public static function beforeDelete(callable $callback)
    {
        static::addEventCallback('before_delete', $callback);
    }

    public static function afterDelete(callable $callback)
    {
        static::addEventCallback('after_delete', $callback);
    }

    public static function beforeRestore(callable $callback)
    {
        static::addEventCallback('before_restore', $callback);
    }

    public static function afterRestore(callable $callback)
    {
        static::addEventCallback('after_restore', $callback);
    }

    public static function onBeforeInsert($model)
    {
        return static::fireEvent('before_insert', $model);
    }

    public static function onAfterInsert($model)
    {
        return static::fireEvent('after_insert', $model);
    }

    public static function onBeforeUpdate($model)
    {
        return static::fireEvent('before_update', $model);
    }

    public static function onAfterUpdate($model)
    {
        return static::fireEvent('after_update', $model);
    }

    public static function onBeforeWrite($model)
    {
        static::normalizeDateAttributes($model);
        return static::fireEvent('before_write', $model);
    }

    public static function onAfterWrite($model)
    {
        return static::fireEvent('after_write', $model);
    }

    public static function onBeforeDelete($model)
    {
        return static::fireEvent('before_delete', $model);
    }

    public static function onAfterDelete($model)
    {
        return static::fireEvent('after_delete', $model);
    }

    public static function onBeforeRestore($model)
    {
        return static::fireEvent('before_restore', $model);
    }

    public static function onAfterRestore($model)
    {
        return static::fireEvent('after_restore', $model);
    }

    /**
     * 将表单提交的日期字符串转换为时间戳，空字符串按字段定义置为空值，避免写入 bigint 字段报错
     * @param mixed $model
     * @return void
     */
    protected static function normalizeDateAttributes($model)
    {
        static $fieldTypes = [];
        $table = $model->getTable();
        if (!isset($fieldTypes[$table])) {
            try {
                $fieldTypes[$table] = \think\facade\Db::getFields($table);
            } catch (\Throwable $e) {
                $fieldTypes[$table] = [];
            }
        }

        foreach ($model->getData() as $name => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $value)) {
                $model->setAttr($name, strtotime($value));
                continue;
            }
            if ($value !== '' || !isset($fieldTypes[$table][$name])) {
                continue;
            }
            // 空字符串写入数值或时间字段会导致数据截断，按字段定义置为空值或默认值
            $field = $fieldTypes[$table][$name];
            if (!empty($field['null'])) {
                $model->setAttr($name, null);
            } elseif ($field['default'] !== null) {
                $model->setAttr($name, $field['default']);
            }
        }
    }

    /**
     * 兼容原 FastAdmin 模型的 getOriginData 方法
     * @return array
     */
    public function getOriginData()
    {
        return $this->getOrigin();
    }

    /**
     * 校验模型数据：兼容原 FastAdmin 模型的 validate 调用（think-orm 4 的 validate 语义不同，故改名）
     * @param string|array $rule    验证器类名（可带场景，如 App\Validate.add）或规则数组
     * @param array        $message 错误提示
     * @param string       $scene   验证场景
     * @return $this
     */
    public function validateData($rule, array $message = [], string $scene = '')
    {
        $data = $this->getData();

        if (is_array($rule)) {
            $validate = new \think\Validate($rule, $message);
        } elseif (is_string($rule) && str_contains($rule, '.')) {
            [$class, $sceneName] = explode('.', $rule, 2);
            if (!class_exists($class)) {
                return $this;
            }
            $validate = new $class($message);
            $scene = $scene ?: $sceneName;
        } elseif (is_string($rule) && class_exists($rule)) {
            $validate = new $rule($message);
        } else {
            return $this;
        }

        if ($scene && method_exists($validate, 'scene')) {
            $validate->scene($scene);
        }

        if (!$validate->check($data)) {
            $this->error = $validate->getError();
            throw new \think\exception\ValidateException($this->error);
        }

        return $this;
    }

    /**
     * 兼容原 FastAdmin 模型的 getError 方法
     * @return mixed
     */
    public function getError()
    {
        return $this->error ?? '';
    }

    /**
     * 兼容原 FastAdmin 的 allowField(true) 调用：true 表示允许写入所有字段
     * @param array|bool $allow
     * @return $this
     */
    public function allowField(array|bool $allow)
    {
        if ($allow === true) {
            return $this;
        }
        parent::allowField($allow);
        return $this;
    }

    /**
     * 兼容原 FastAdmin 模型的 validateFailException 方法
     * ps: think-orm 4 的 validate() 校验失败时默认抛出异常，此处仅保留链式调用兼容
     * @param bool $failException
     * @return $this
     */
    public function validateFailException(bool $failException = true)
    {
        return $this;
    }

    /**
     * 注册事件回调
     * @param string   $event
     * @param callable $callback
     * @return void
     */
    protected static function addEventCallback(string $event, callable $callback)
    {
        static::$eventCallbacks[static::class][$event][] = $callback;
    }

    /**
     * 触发已注册的事件回调
     * @param string $event
     * @param mixed  $model
     * @return mixed
     */
    protected static function fireEvent(string $event, $model)
    {
        foreach (static::$eventCallbacks[static::class][$event] ?? [] as $callback) {
            $result = $callback($model);
            if ($result === false) {
                return false;
            }
        }
        return true;
    }
}
