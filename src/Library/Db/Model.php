<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

namespace Swoolefy\Library\Db;

use ArrayAccess;

/**
 * Class Model
 * @package Swoolefy\Library\Db
 */

abstract class Model implements ArrayAccess
{
    use Concern\Attribute;
    use Concern\ModelEvent;
    use Concern\ParseSql;
    use Concern\TimeStamp;
    use Concern\Util;

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
     * @var bool
     */
    protected $exists = false;

    /**
     * @var bool
     */
    protected $isNew = true;

    /**
     * @var string
     */
    protected $suffix = '';

    /**
     * @var array
     */
    protected $tableFields = [];

    /**
     * @var array
     */
    protected $schemaInfo = [];

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
     * Model constructor.
     * @param mixed ...$params
     */
    public function __construct(...$params)
    {
        $this->init();
    }

    /**
     * 获取当前模型的数据库
     * @return PDOConnection
     */
    abstract public function getConnection();

    /**
     * @return Model
     */
    abstract public static function model(): Model;

    /**
     * 获取当前模型的数据库从库设置
     * @param mixed ...$args
     */
    public function getSlaveConnection(...$args) {
        return $this->getConnection();
    }

    /**
     * 自定义创建primary key的值.数据库自增的则忽略该函数处理
     * @return mixed
     */
    public function createPkValue() {}

    /**
     * @param $pk
     * @param mixed ...$params
     */
    public function loadByPk($pk, ...$params) {}

    /**
     * @return bool
     */
    protected function onBeforeInsert(): bool
    {
        return true;
    }

    /**
     * return void
     */
    protected function onAfterInsert() {}

    /**
     * @return bool
     */
    protected function onBeforeUpdate(): bool
    {
        return true;
    }

    /**
     * @return void
     */
    protected function onAfterUpdate() {}

    /**
     * @return bool
     */
    protected function onBeforeDelete(): bool
    {
        return true;
    }

    /**
     * @return void
     */
    protected function onAfterDelete() {}

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

    /**
     * @param bool $isNew
     */
    protected function setIsNew(bool $isNew): void
    {
        $this->isNew = $isNew;
    }

    /**
     * @return bool
     */
    public function isNew(): bool
    {
        return $this->isNew;
    }

    /**
     * @return int
     */
    public function getNumRows(): int
    {
        return $this->numRows;
    }

    /**
     * @return string
     */
    public function getTableName() {
        return $this->table;
    }

    /**
     * @param \Closure $callback
     * @return mixed|null
     * @throws Throwable
     */
    protected function transaction(\Closure $callback)
    {
        try {
            $result = null;
            $this->getConnection()->beginTransaction();
            $result = $callback->call($this);
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
     * @return void
     */
    public function setAttribute($name, $value): void
    {
        if($this->isExists() && $name == $this->getPk()) {
            return;
        }

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
        // 源数据
        if(!$this->isExists()) $this->origin[$name] = $value;

        // 设置数据对象属性
        $this->data[$name] = $value;
    }

    /**
     * 保存当前数据对象
     * @param array  $data 数据
     * @return bool
     */
    public function save(): bool
    {
        $result = $this->isExists() ? $this->updateData() : $this->insertData();
        if(false === $result) {
            return false;
        }
        // 重新记录原始数据
        $this->origin   = $this->data;
        $this->set      = [];
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
            $allowFields = $this->getAllowFields();
            $pk = $this->getPk();
            // 对于自定义的主键值，需要设置
            $pkValue = $this->createPkValue();
            if(empty($pkValue)) {
                $this->data[$pk] = $pkValue;
            }else {
                // 数据表设置自增pk的，则不需要设置允许字段
                $allowFields = array_diff($allowFields, [$pk]);
            }
            list($sql, $bindParams) = $this->parseInsertSql($allowFields);
            $this->numRows = $this->getConnection()->createCommand($sql)->insert($bindParams);
            // 对于自增的pk,插入成功,需要赋值
            if(!isset($this->data[$pk]))  {
                $this->data[$pk] = $this->getConnection()->getLastInsID($pk);
            }
        }catch (\Throwable $exception) {
            throw $exception;
        }
        // 标记数据已经存在
        $this->exists(true);
        // 所有的数据表原始字段值设置
        $this->buildAttributes();
        // 新增回调
        $this->trigger('AfterInsert');
        return $this->data[$pk] ?? false;
    }

    /**
     * @return int
     */
    public function getLastInsertId(): int
    {
        if($this->isNew() && $this->isExists()) {
            return $this->getPkValue();
        }
    }

    /**
     * 检查数据是否允许写入
     * @return array
     */
    protected function getAllowFields(): array
    {
        if(empty($this->tableFields)) {
            $schemaInfo = $this->getSchemaInfo();
            $fields = $schemaInfo['fields'];
            if(!empty($this->disuse)) {
                // 废弃字段
                $fields = array_diff($fields, $this->disuse);
            }
            $this->tableFields = $fields;
        }

        return $this->tableFields;
    }

    /**
     * @return array|mixed
     */
    protected function getFieldType(): array
    {
        $schemaInfo = $this->getSchemaInfo();
        return $schemaInfo['type'] ?? [];
    }

    /**
     * @return array
     */
    protected function getSchemaInfo(): array
    {
        if(empty($this->schemaInfo)) {
            // 检测字段
            $table = $this->table ? $this->table . $this->suffix : $this->table;
            $schemaInfo = $this->getConnection()->getSchemaInfo($table);
            $this->schemaInfo = $schemaInfo;
        }

        return $this->schemaInfo;
    }

    /**
     * buildAttributes
     * @return $this|boolean
     */
    protected function buildAttributes()
    {
        list($sql, $bindParams) = $this->parseFindSqlByPk();
        $attributes = $this->getConnection()->createCommand($sql)->findOne($bindParams);
        if($attributes) {
            $this->parseOrigin($attributes);
            return $this;
        }else {
            $this->exists(false);
            return false;
        }

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
            $diffData = $this->getChangeData();
        }else {
            // 指定字段更新
            $diffData = $this->getCustomData($attributes);
        }
        // 检查允许字段
        $allowFields = $this->getAllowFields();
        if($diffData) {
            list($sql, $bindParams) = $this->parseUpdateSql($diffData, $allowFields);
            $this->numRows = $this->getConnection()->createCommand($sql)->update($bindParams);
            $this->origin = $this->data;
            $this->checkResult($this->data);
            $this->trigger('AfterUpdate');
        }

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
     * @param bool $force 强制物理删除
     * @return bool
     */
    public function delete(bool $force = false): bool
    {
        if(!$this->isExists()) {
            throw new \RuntimeException('Active object is not exist');
        }

        $this->setIsNew(false);

        if(!$this->exists || false === $this->trigger('BeforeDelete')) {
            return false;
        }

        if($force) {
            list($sql, $bindParams) = $this->parseDeleteSql();
            $this->numRows = $this->getConnection()->createCommand($sql)->delete($bindParams);
        }else {
            if($this->processDelete() === false) {
                throw new \RuntimeException('ProcessDelete Failed');
            }
        }
        $this->exists(false);
        $this->trigger('AfterDelete');
        return true;
    }

    /**
     * 自定义逻辑删除过程
     * @return bool
     */
    protected function processDelete(): bool
    {
        //todo
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
     * 获取对象经过属性的getter函数处理后的真实存在的业务目标数据
     * @return array|null
     */
    public function getAttributes() {
        if($this->isExists() && $this->origin) {
            foreach($this->origin as $fieldName=>$value) {
                if(in_array($fieldName, $this->getAllowFields())) {
                    $attributes[$fieldName] = $this->getValue($fieldName, $value);
                }else {
                    unset($this->origin[$fieldName]);
                }
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
     * 设置当前模型数据表的后缀
     * @param string $suffix 数据表后缀
     * @return $this
     */
    public function setSuffix(string $suffix)
    {
        $this->suffix = $suffix;
        return $this;
    }

    /**
     * 获取当前模型的数据表后缀
     * @return string
     */
    public function getSuffix(): string
    {
        return $this->suffix ?: '';
    }

    /**
     * 切换后缀进行查询
     * @param string $suffix 切换的表后缀
     * @return Model
     */
    public static function modelSuffix(string $suffix)
    {
        $model = new static();
        $model->setSuffix($suffix);
        return $model;
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

    }
}