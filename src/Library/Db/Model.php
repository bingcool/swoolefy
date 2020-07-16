<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
 * +----------------------------------------------------------------------
 */

namespace Swoolefy\Library\Db;

use ArrayAccess;
use think\contract\Jsonable;

/**
 * Class Model
 * @package think
 * @method mixed onBeforeInsert(Model $model) static before_insert事件定义
 * @method void onAfterInsert(Model $model) static after_insert事件定义
 * @method mixed onBeforeUpdate(Model $model) static before_update事件定义
 * @method void onAfterUpdate(Model $model) static after_update事件定义
 * @method mixed onBeforeDelete(Model $model) static before_delete事件定义
 * @method void onAfterDelete(Model $model) static after_delete事件定义
 */
abstract class Model implements ArrayAccess, Jsonable
{
    use Concern\Attribute;
    use Concern\ModelEvent;
    use Concern\ParseSql;
    use Concern\TimeStamp;

    const BEFORE_INSERT = 'BeforeInsert';
    const AFTER_INSERT = 'AfterInsert';
    const BEFORE_UPDATE = 'BeforeUpdate';
    const AFTER_UPDATE = 'AfterUpdate';
    const BEFORE_DELETE = 'BeforeDelete';
    const AFTER_DELETE = 'AfterDelete';

    /**
     * @var string
     */
    protected $table;

    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    /**
     * @var boolean
     */
    protected $exists = false;

    /**
     * @var bool
     */
    protected $isNew = false;

    /**
     * @var string
     */
    protected $suffix = '';

    /**
     * @var array
     */
    protected $tableFields = [];

    /**
     * @var int
     */
    protected $numRows = 0;

    /**
     * @var array
     */
    protected $attributes = null;

    /**
     * @var bool
     */
    protected $force = false;

    /**
     * @var bool
     */
    protected $lazySave = false;

    /**
     * Model constructor.
     */
    public function __construct()
    {
        $this->init();
    }

    /**
     * 获取当前模型的数据库连接标识
     * @return PDOConnection
     */
    abstract public function getConnection();

    /**
     * 自定义创建primary key的值,一般默认是数据库自增
     */
    public function createPkValue(){}

    protected function init() {}

    protected function checkData() {}

    protected function checkResult($result) {}

    /**
     * 设置数据是否存在
     * @param bool $exists
     * @return $this
     */
    protected function exists(bool $exists = true)
    {
        $this->exists = $exists;
        return $this;
    }

    /**
     * 判断数据是否存在数据库
     * @return bool
     */
    public function isExists(): bool
    {
        return $this->exists;
    }

    protected function setIsNew(bool $isNew)
    {
        $this->isNew = $isNew;
    }

    /**
     * @return int
     */
    public function getNumRows() {
        return $this->numRows;
    }

    /**
     * @param callable $callback
     * @return mixed|null
     * @throws Throwable
     */
    protected function transaction(callable $callback)
    {
        try {
            $result = null;
            $this->getConnection()->beginTransaction();
            if (is_callable($callback)) {
                $result = call_user_func($callback);
            }
            $this->getConnection()->commit();
            return $result;
        } catch (\Exception | \Throwable $e) {
            $this->getConnection()->rollback();
            throw $e;
        }
    }

    /**
     * 修改器 设置数据对象的值处理
     * @param string $name  名称
     * @param mixed  $value 值
     * @return void
     */
    public function __set(string $name, $value): void
    {
        $this->setAttribute($name, $value);
    }

    /**
     * @param $name
     * @param $value
     */
    public function setAttribute($name, $value) {

        if($this->isExists() && $name == $this->getPk()) {
            return;
        }
        // 源数据
        if(!$this->isExists()) $this->origin[$name] = $value;

        $method = 'set' . self::studly($name) . 'Attr';

        if(method_exists($this, $method)) {
            // 返回修改器处理过的数据
            $value = $this->$method($value);
            $this->set[$name] = true;
            if(is_null($value)) {
                return;
            }
        }else if(isset($this->type[$name])) {
            //类型转换
            $value = $this->writeTransform($value, $this->type[$name]);
        }
        // 设置数据对象属性
        $this->data[$name] = $value;
    }

    /**
     * 保存当前数据对象
     * @param array  $data     数据
     * @return bool
     */
    public function save(): bool
    {
        if ($this->isEmpty() || false === $this->trigger('BeforeWrite')) {
            return false;
        }

        $result = $this->isExists() ? $this->updateData() : $this->insertData();

        if (false === $result) {
            return false;
        }

        // 写入回调
        $this->trigger('AfterWrite');

        // 重新记录原始数据
        $this->origin   = $this->data;
        $this->set      = [];
        $this->lazySave = false;

        return true;
    }

    /**
     * 新增写入数据
     * @return bool
     * @throws Exception
     */
    protected function insertData(): bool
    {
        // 标记为新数据
        $this->setIsNew(true);

        if(false === $this->trigger('BeforeInsert')) {
            return false;
        }

        $this->checkData();

        try {
            // 检查允许字段
            $allowFields = $this->checkAllowFields();
            $pk = $this->getPk();
            // 对于自定义的主键值，需要设置
            $pkValue = $this->createPkValue();
            if($pkValue) {
                $this->data[$pk] = $pkValue;
            }else {
                // 数据表设置自增pk的，则不需要设置允许字段
                $allowFields = array_diff($allowFields, [$pk]);
            }
            list($sql, $bindParams) = $this->parseInsertSql($allowFields);
            $this->numRows = $this->getConnection()->createCommand($sql)->insert($bindParams);
            // 获取插入的主键值
            $lastId = $this->getConnection()->getLastInsID($this->getPk());

        }catch (\Exception $exception) {
            throw $exception;
        }

        if($lastId) {
            if(is_string($pk) && (!isset($this->data[$pk]) || '' == $this->data[$pk])) {
                $this->data[$pk] = $lastId;
            }
            // 标记数据已经存在
            $this->exists(true);
        }
        // 所有的数据表原始字段值设置
        $this->buildAttributes();
        // 新增回调
        $this->trigger('AfterInsert');

        return $lastId ?? false;
    }

    /**
     * 检查数据是否允许写入
     * @return array
     */
    protected function checkAllowFields(): array
    {
        if(empty($this->tableFields)) {
            // 检测字段
            $table = $this->table ? $this->table . $this->suffix : $this->table;
            $fieldInfo = $this->getConnection()->getFields($table);
            $fields = array_keys($fieldInfo);
            if(!empty($this->disuse)) {
                // 废弃字段
                $fields = array_diff($fields, $this->disuse);
            }
            $this->tableFields = $fields;
        }

        return $this->tableFields;
    }

    /**
     * buildAttributes
     */
    protected function buildAttributes() {
        list($sql, $bindParams) = $this->parseFindSqlByPk();
        $this->getConnection()->createCommand($sql)->findOne($bindParams);
    }

    /**
     * 保存写入更新数据
     * @param array $attributes
     * @return bool
     */
    protected function updateData(array $attributes = []): bool
    {
        // 标记为新数据
        $this->setIsNew(false);

        // 事件回调
        if(false === $this->trigger('BeforeUpdate')) {
            return false;
        }

        $this->checkData();
        // 自动获取有更新的数据
        if(!$attributes) {
            $diffData = $this->getChangedData();
        }else {
            // 指定字段更新
            $diffData = $this->getCustomData($attributes);
        }
        // 检查允许字段
        $allowFields = $this->checkAllowFields();
        list($sql, $bindParams) = $this->parseUpdateSql($diffData, $allowFields);
        $this->numRows = $this->getConnection()->createCommand($sql)->update($bindParams);

        $this->origin = $this->data;

        $this->checkResult($this->data);

        // 更新回调
        $this->trigger('AfterUpdate');

        return true;
    }

    /**
     * 指定字段更新
     * @param array $attributes
     * @return bool
     */
    public function update(array $attributes): bool
    {
        $this->force = false;
        return $this->updateData($attributes);
    }

    /**
     * @return bool
     */
    public function delete(): bool
    {
        // 标记为新数据
        $this->setIsNew(false);

        if(!$this->exists || false === $this->trigger('BeforeDelete')) {
            return false;
        }

        list($sql, $bindParams) = $this->parseDeleteSql();
        $this->numRows = $this->getConnection()->createCommand($sql)->delete($bindParams);
        $this->trigger('AfterDelete');
        $this->exists   = false;
        $this->lazySave = false;
        return true;
    }

    /**
     * 检测数据对象的值
     * @param string $name 名称
     * @return bool
     */
    public function __isset(string $name): bool
    {
        return !is_null($this->getAttribute($name));
    }

    /**
     * 获取器 获取数据对象的值
     * @param string $name 名称
     * @return mixed
     * @throws \Exception
     */
    public function __get(string $name)
    {
        return $this->getAttribute($name);
    }

    /**
     * 获取器 获取数据对象的值
     * @param  string $name 名称
     * @return mixed
     * @throws Exception
     */
    public function getAttribute(string $name)
    {
        $value = $this->getData($name);
        return $this->getValue($name, $value);
    }

    /**
     * @param string $fieldName
     * @param $value
     * @return mixed
     */
    protected function getValue(string $fieldName, $value)
    {
        $method = 'get' . self::studly($fieldName) . 'Attr';
        if(method_exists($this, $method)) {
            $value = $this->$method($value);
        }else if(isset($this->type[$fieldName])) {
            // 类型转换
            $value = $this->readTransform($value, $this->type[$fieldName]);
        }
        return $value;
    }

    /**
     * 获取对象Formatter处理后的真实存在的业务目标数据 如果不存在指定字段返回null
     * @return array|null
     */
    public function getAttributes() {
        if($this->isExists() && $this->origin) {
            foreach($this->origin as $fieldName=>$value) {
                if(in_array($fieldName, $this->tableFields)) {
                    $attributes[$fieldName] = $this->getValue($fieldName, $value);
                }
                unset($this->origin[$fieldName]);
            }
        }
        $this->attributes = $attributes ?? null;
        return $this->attributes;
    }

    /**
     * 判断模型是否为空
     * @return bool
     */
    public function isEmpty(): bool
    {
        return empty($this->data);
    }

    /**
     * 下划线转驼峰(首字母大写)
     * @param  string $value
     * @return string
     */
    public static function studly(string $value): string
    {
        $value = ucwords(str_replace(['-', '_'], ' ', $value));
        return str_replace(' ', '', $value);
    }

    /**
     * 销毁数据对象的值
     * @param string $name 名称
     * @return void
     */
    public function __unset(string $name): void
    {
        unset($this->data[$name]);
    }

    // ArrayAccess
    public function offsetSet($name, $value)
    {
        $this->setAttribute($name, $value);
    }

    /**
     * @param mixed $name
     * @return bool
     */
    public function offsetExists($name): bool
    {
        return $this->__isset($name);
    }

    /**
     * @param mixed $name
     */
    public function offsetUnset($name)
    {
        $this->__unset($name);
    }

    /**
     * @param mixed $name
     * @return mixed
     */
    public function offsetGet($name)
    {
        return $this->getAttribute($name);
    }

    /**
     * 转换当前模型对象源数据转为JSON字符串
     * @param  integer $options json参数
     * @return string
     */
    public function toJson(int $options = JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->origin, $options);
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->toJson();
    }

    /**
     * 析构方法
     * @access public
     */
    public function __destruct()
    {
        if($this->lazySave) {
            $this->save();
        }
    }
}