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
use think\helper\Str;

/**
 * Class Model
 * @package think
 * @method void onAfterRead(Model $model) static after_read事件定义
 * @method mixed onBeforeInsert(Model $model) static before_insert事件定义
 * @method void onAfterInsert(Model $model) static after_insert事件定义
 * @method mixed onBeforeUpdate(Model $model) static before_update事件定义
 * @method void onAfterUpdate(Model $model) static after_update事件定义
 * @method mixed onBeforeWrite(Model $model) static before_write事件定义
 * @method void onAfterWrite(Model $model) static after_write事件定义
 * @method mixed onBeforeDelete(Model $model) static before_write事件定义
 * @method void onAfterDelete(Model $model) static after_delete事件定义
 * @method void onBeforeRestore(Model $model) static before_restore事件定义
 * @method void onAfterRestore(Model $model) static after_restore事件定义
 */
abstract class Model implements ArrayAccess, Jsonable
{
    use Concern\Attribute;
    use Concern\ModelEvent;
    use Concern\ParseSql;
    use Concern\TimeStamp;

    /**
     * @var string
     */
    protected $table;

    /**
     * @var boolean
     */
    protected $exists = false;

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
    protected $attributes = [];

    /**
     * 架构函数
     * @access public
     * @param array $data 数据
     */
    public function __construct()
    {
        // 执行初始化操作
        $this->init();

    }

    protected function init() {

    }

    protected function checkData(): void
    {
    }

    protected function checkResult($result): void
    {

    }

    /**
     * 设置数据是否存在
     * @access public
     * @param bool $exists
     * @return $this
     */
    public function exists(bool $exists = true)
    {
        $this->exists = $exists;
        return $this;
    }

    /**
     * 判断数据是否存在数据库
     * @access public
     * @return bool
     */
    public function isExists(): bool
    {
        return $this->exists;
    }

    /**
     * 获取当前模型的数据库连接标识
     * @return PDOConnection
     */
    public function getConnection()
    {

    }

    /**
     * 自定义创建primary key的值,一般默认是数据库自增
     */
    public function createPkValue() {

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
     * 修改器 设置数据对象的值
     * @access public
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

        $method = 'set' . Str::studly($name) . 'Attr';

        if(method_exists($this, $method)) {
            // 返回 修改器 处理过的数据
            $value = $this->$method($value);
            $this->set[$name] = true;
            if(is_null($value)) {
                return;
            }
        }else if(isset($this->type[$name])) {
            // 类型转换
            $value = $this->writeTransform($value, $this->type[$name]);
        }
        // 设置数据对象属性
        $this->data[$name] = $value;
    }

    /**
     * 保存当前数据对象
     * @access public
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
     * @access protected
     * @param string $sequence 自增名
     * @return bool
     * @throws Throwable
     */
    protected function insertData(string $sequence = null): bool
    {
        if(false === $this->trigger('BeforeInsert')) {
            return false;
        }

        $this->checkData();

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
        if($lastId) {
            if(is_string($pk) && (!isset($this->data[$pk]) || '' == $this->data[$pk])) {
                $this->data[$pk] = $lastId;
            }
        }
        // 标记数据已经存在
        $lastId && $this->exists = true;
        // 所有的数据表原始字段值设置
        $this->buildAttributes();
        // 新增回调
        $this->trigger('AfterInsert');

        return $lastId ?? false;
    }

    /**
     * 检查数据是否允许写入
     * @access protected
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
        $this->findOne($sql, $bindParams);
    }

    /**
     * 保存写入数据
     * @access protected
     * @return bool
     */
    protected function updateData(): bool
    {
        // 事件回调
        if(false === $this->trigger('BeforeUpdate')) {
            return false;
        }

        $this->checkData();
        // 获取有更新的数据
        $diffData = $this->getChangedData();
        // 检查允许字段
        $allowFields = $this->checkAllowFields();
        list($sql, $bindParams) = $this->parseUpdateSql($diffData, $allowFields);
        $this->numRows = $this->getConnection()->createCommand($sql)->update($bindParams);
        $this->checkResult($this->data);
        // 更新回调
        $this->trigger('AfterUpdate');

        return true;
    }

    /**
     * 检测数据对象的值
     * @access public
     * @param string $name 名称
     * @return bool
     */
    public function __isset(string $name): bool
    {
        return !is_null($this->getAttribute($name));
    }

    /**
     * 获取器 获取数据对象的值
     * @access public
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
     * @access public
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
        $method = 'get' . Str::studly($fieldName) . 'Attr';
        if(method_exists($this, $method)) {
            $value = $this->$method($value);
        }else if(isset($this->type[$fieldName])) {
            // 类型转换
            $value = $this->readTransform($value, $this->type[$fieldName]);
        }
        return $value;
    }

    /**
     * 获取对象Formatter处理后的业务目标数据 如果不存在指定字段返回null
     * @return array|null
     */
    public function getAttributes() {
        if(!$this->attributes) {
            if($this->data) {
                foreach($this->data as $fieldName=>$value) {
                    if(in_array($fieldName, $this->tableFields)) {
                        $attributes[$fieldName] = $this->getValue($fieldName, $value);
                    }
                    unset($this->data[$fieldName]);
                }
            }
            $this->attributes = $attributes ?? null;
        }
        return $this->attributes;
    }

    /**
     * 判断模型是否为空
     * @access public
     * @return bool
     */
    public function isEmpty(): bool
    {
        return empty($this->data);
    }

    /**
     * 销毁数据对象的值
     * @access public
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

    public function offsetExists($name): bool
    {
        return $this->__isset($name);
    }

    public function offsetUnset($name)
    {
        $this->__unset($name);
    }

    public function offsetGet($name)
    {
        return $this->getAttribute($name);
    }

    /**
     * 转换当前模型对象为JSON字符串
     * @access public
     * @param  integer $options json参数
     * @return string
     */
    public function toJson(int $options = JSON_UNESCAPED_UNICODE): string
    {

    }

    public function __toString()
    {
        return $this->toJson();
    }

    public function __call($method, $args)
    {

    }

    public static function __callStatic($method, $args)
    {

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