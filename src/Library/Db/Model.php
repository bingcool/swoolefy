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
use Closure;
use DateTime;
use JsonSerializable;
use Swoolefy\Core\Application;
use think\contract\Arrayable;
use think\contract\Jsonable;
use think\db\BaseQuery as Query;
use think\helper\Str;

/**
 * Class Model
 * @package think
 * @mixin Query
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
abstract class Model implements JsonSerializable, ArrayAccess, Arrayable, Jsonable
{
    use Concern\Attribute;
    use Concern\RelationShip;
    use Concern\ModelEvent;
    use Concern\TimeStamp;
    use Concern\Conversion;

    /**
     * 数据是否存在
     * @var bool
     */
    private $exists = false;

    /**
     * 是否强制更新所有数据
     * @var bool
     */
    private $force = false;

    /**
     * 是否Replace
     * @var bool
     */
    private $replace = false;

    /**
     * 数据表后缀
     * @var string
     */
    protected $suffix;

    /**
     * 更新条件
     * @var array
     */
    private $updateWhere;

    /**
     * 数据库配置
     * @var PDOConnection
     */
    protected $connection;

    /**
     * 主键值
     * @var string
     */
    protected $key;

    /**
     * 数据表名称
     * @var string
     */
    protected $table;

    /**
     * 延迟保存信息
     * @var bool
     */
    private $lazySave = false;

    /**
     * 架构函数
     * @access public
     * @param array $data 数据
     */
    public function __construct()
    {
        // 执行初始化操作
        $this->initialize();

    }

    /**
     * 设置模型的数据库连接
     * @param PDOConnection $connection
     */
    public function setConnection(PDOConnection $connection)
    {
        $this->connection = $connection;
        return $this;
    }

    /**
     * 获取当前模型的数据库连接标识
     * @return PDOConnection
     */
    public function getConnection()
    {

    }

    /**
     * 创建新的模型实例
     * @access public
     * @param array $data  数据
     * @param mixed $where 更新条件
     * @return Model
     */
    public function newInstance($where = null): Model
    {
        $model = new static();

        if ($this->suffix) {
            $model->setSuffix($this->suffix);
        }

        if (empty($data)) {
            return $model;
        }

        $model->exists(true);

        $model->setUpdateWhere($where);

        $model->trigger('AfterRead');

        return $model;
    }

    /**
     * 设置模型的更新条件
     * @access protected
     * @param mixed $where 更新条件
     * @return void
     */
    protected function setUpdateWhere($where): void
    {
        $this->updateWhere = $where;
    }

    /**
     * 设置当前模型数据表的后缀
     * @access public
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
     * @access public
     * @return string
     */
    public function getSuffix(): string
    {
        return $this->suffix ?: '';
    }

    /**
     *  初始化模型
     * @access private
     * @return void
     */
    private function initialize(): void
    {
        $this->init();
    }

    /**
     * 初始化处理
     * @access protected
     * @return void
     */
    protected function init()
    {
    }

    protected function checkData(): void
    {
    }

    protected function checkResult($result): void
    {
    }

    /**
     * 更新是否强制写入数据 而不做比较（亦可用于软删除的强制删除）
     * @access public
     * @param bool $force
     * @return $this
     */
    public function force(bool $force = true)
    {
        $this->force = $force;
        return $this;
    }

    /**
     * 判断force
     * @access public
     * @return bool
     */
    public function isForce(): bool
    {
        return $this->force;
    }

    /**
     * 新增数据是否使用Replace
     * @access public
     * @param bool $replace
     * @return $this
     */
    public function replace(bool $replace = true)
    {
        $this->replace = $replace;
        return $this;
    }

    /**
     * 刷新模型数据
     * @access public
     * @param bool $relation 是否刷新关联数据
     * @return $this
     */
    public function refresh(bool $relation = false)
    {
        if ($this->exists) {
            $this->data   = $this->connection->findOne($this->getKey());
            $this->origin = $this->data;

            if ($relation) {
                $this->relation = [];
            }
        }

        return $this;
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
     * 判断模型是否为空
     * @access public
     * @return bool
     */
    public function isEmpty(): bool
    {
        return empty($this->data);
    }

    /**
     * 延迟保存当前数据对象
     * @access public
     * @param array|bool $data 数据
     * @return void
     */
    public function lazySave($data = []): void
    {
        if (false === $data) {
            $this->lazySave = false;
        } else {
            if (is_array($data)) {
                $this->setAttrs($data);
            }

            $this->lazySave = true;
        }
    }

    /**
     * 保存当前数据对象
     * @access public
     * @param array  $data     数据
     * @param string $sequence 自增序列名
     * @return bool
     */
    public function save(): bool
    {
        if ($this->isEmpty() || false === $this->trigger('BeforeWrite')) {
            return false;
        }

        $result = $this->exists ? $this->updateData() : $this->insertData();

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
     * 检查数据是否允许写入
     * @access protected
     * @return array
     */
    protected function checkAllowFields(): array
    {
        // 检测字段
        $table = $this->table ? $this->table . $this->suffix : $this->table;
        $fields = $this->getConnection()->getFields($table);

        if(!empty($this->disuse)) {
            // 废弃字段
            $fields = array_diff($fields, $this->disuse);
        }

        return $fields;
    }

    /**
     * 保存写入数据
     * @access protected
     * @return bool
     */
    protected function updateData(): bool
    {
        // 事件回调
        if (false === $this->trigger('BeforeUpdate')) {
            return false;
        }

        $this->checkData();

        // 获取有更新的数据
        $data = $this->getChangedData();

        if (empty($data)) {
            // 关联更新
            if (!empty($this->relationWrite)) {
                $this->autoRelationUpdate();
            }

            return true;
        }

        if ($this->autoWriteTimestamp && $this->updateTime && !isset($data[$this->updateTime])) {
            // 自动写入更新时间
            $data[$this->updateTime]       = $this->autoWriteTimestamp($this->updateTime);
            $this->data[$this->updateTime] = $data[$this->updateTime];
        }

        // 检查允许字段
        $allowFields = $this->checkAllowFields();

        foreach ($this->relationWrite as $name => $val) {
            if (!is_array($val)) {
                continue;
            }

            foreach ($val as $key) {
                if (isset($data[$key])) {
                    unset($data[$key]);
                }
            }
        }

        // 模型更新
        $db = $this->db();

        $db->transaction(function () use ($data, $allowFields, $db) {
            $this->key = null;
            $where     = $this->getWhere();

            $result = $db->where($where)
                ->strict(false)
                ->cache(true)
                ->setOption('key', $this->key)
                ->field($allowFields)
                ->update($data);

            $this->checkResult($result);

            // 关联更新
            if (!empty($this->relationWrite)) {
                $this->autoRelationUpdate();
            }
        });

        // 更新回调
        $this->trigger('AfterUpdate');

        return true;
    }

    protected function transaction(callable $callback)
    {

        try {
            $result = null;
            $this->connection->beginTransaction();
            if (is_callable($callback)) {
                $result = call_user_func($callback);
            }

            $this->connection->commit();
            return $result;
        } catch (\Exception | \Throwable $e) {
            $this->connection->rollback();
            throw $e;
        }
    }

    /**
     * 新增写入数据
     * @access protected
     * @param string $sequence 自增名
     * @return bool
     * @throws \Throwable
     */
    protected function insertData(string $sequence = null): bool
    {

        if(false === $this->trigger('BeforeInsert')) {
            return false;
        }

        $this->checkData();

        // 检查允许字段
        $allowFields = $this->checkAllowFields();

        $this->getConnection()->beginTransaction();

        $this->transaction(function () use ($sequence, $allowFields) {

            list($sql, $bindParams) = $this->parseInsertSql($allowFields);
            $numRows = $this->getConnection()->createCommand($sql)->insert($bindParams);
            // 获取自动增长主键
            $pk = $this->getPk();
            // 获取插入的主键值
            $result = $this->getConnection()->getLastInsID($this->getPk());
            if($result) {
                if (is_string($pk) && (!isset($this->data[$pk]) || '' == $this->data[$pk])) {
                    $this->data[$pk] = $result;
                }
            }
        });

        // 标记数据已经存在
        $this->exists = true;

        // 新增回调
        $this->trigger('AfterInsert');

        return true;
    }

    protected function parseInsertSql($allowFields) {
        $columns = $bindParams = [];
        foreach($allowFields as $field) {
            $column = ':'.$field;
            $columns[] = $column;
            $bindParams[$column] = $this->data[$field];
        }
        $fields = implode(',', $allowFields);
        $columns = implode(',', $columns);
        $sql = "INSERT INTO {$this->table} ({$fields}) VALUES ({$columns}) ";
        return [$sql, $bindParams] ;
    }

    /**
     * 获取当前的更新条件
     * @access public
     * @return mixed
     */
    public function getWhere()
    {
        $pk = $this->getPk();

        if (is_string($pk) && isset($this->origin[$pk])) {
            $where     = [[$pk, '=', $this->origin[$pk]]];
            $this->key = $this->origin[$pk];
        } elseif (is_array($pk)) {
            foreach ($pk as $field) {
                if (isset($this->origin[$field])) {
                    $where[] = [$field, '=', $this->origin[$field]];
                }
            }
        }

        if (empty($where)) {
            $where = empty($this->updateWhere) ? null : $this->updateWhere;
        }

        return $where;
    }

    /**
     * 保存多个数据到当前数据对象
     * @access public
     * @param iterable $dataSet 数据
     * @param boolean  $replace 是否自动识别更新和写入
     * @return Collection
     * @throws \Exception
     */
    public function saveAll(iterable $dataSet, bool $replace = true): Collection
    {
        $db = $this->db();

        $result = $db->transaction(function () use ($replace, $dataSet) {

            $pk = $this->getPk();

            if (is_string($pk) && $replace) {
                $auto = true;
            }

            $result = [];

            $suffix = $this->getSuffix();

            foreach ($dataSet as $key => $data) {
                if ($this->exists || (!empty($auto) && isset($data[$pk]))) {
                    $result[$key] = static::update($data, [], [], $suffix);
                } else {
                    $result[$key] = static::create($data, $this->field, $this->replace, $suffix);
                }
            }

            return $result;
        });

        return $this->toCollection($result);
    }

    /**
     * 删除当前的记录
     * @access public
     * @return bool
     */
    public function delete(): bool
    {
        if (!$this->exists || $this->isEmpty() || false === $this->trigger('BeforeDelete')) {
            return false;
        }

        // 读取更新条件
        $where = $this->getWhere();

        $db = $this->db();

        $db->transaction(function () use ($where, $db) {
            // 删除当前模型数据
            $db->where($where)->delete();

            // 关联删除
            if (!empty($this->relationWrite)) {
                $this->autoRelationDelete();
            }
        });

        $this->trigger('AfterDelete');

        $this->exists   = false;
        $this->lazySave = false;

        return true;
    }

    /**
     * 写入数据
     * @access public
     * @param array  $data       数据数组
     * @param array  $allowField 允许字段
     * @param bool   $replace    使用Replace
     * @param string $suffix     数据表后缀
     * @return static
     */
    public static function create(array $data, array $allowField = [], bool $replace = false, string $suffix = ''): Model
    {
        $model = new static();

        if (!empty($allowField)) {
            $model->allowField($allowField);
        }

        if (!empty($suffix)) {
            $model->setSuffix($suffix);
        }

        $model->replace($replace)->save($data);

        return $model;
    }

    /**
     * 更新数据
     * @access public
     * @param array  $data       数据数组
     * @param mixed  $where      更新条件
     * @param array  $allowField 允许字段
     * @param string $suffix     数据表后缀
     * @return static
     */
    public static function update(array $data, $where = [], array $allowField = [], string $suffix = '')
    {
        $model = new static();

        if (!empty($allowField)) {
            $model->allowField($allowField);
        }

        if (!empty($where)) {
            $model->setUpdateWhere($where);
        }

        if (!empty($suffix)) {
            $model->setSuffix($suffix);
        }

        $model->exists(true)->save($data);

        return $model;
    }

    /**
     * 删除记录
     * @access public
     * @param mixed $data  主键列表 支持闭包查询条件
     * @param bool  $force 是否强制删除
     * @return bool
     */
    public static function destroy($data, bool $force = false): bool
    {
        if (empty($data) && 0 !== $data) {
            return false;
        }

        $model = new static();

        $query = $model->db();

        if (is_array($data) && key($data) !== 0) {
            $query->where($data);
            $data = null;
        } elseif ($data instanceof \Closure) {
            $data($query);
            $data = null;
        }

        $resultSet = $query->select($data);

        foreach ($resultSet as $result) {
            $result->force($force)->delete();
        }

        return true;
    }

    /**
     * 解序列化后处理
     */
    public function __wakeup()
    {
        $this->initialize();
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

        if(isset($this->set[$name])) {
            return;
        }

        // 源数据
        $this->originData[$name] = $value;

        $method = 'set' . Str::studly($name);

        if (method_exists($this, $method)) {
            // 返回 修改器 处理过的数据
            $value = $this->$method($value);
            $this->set[$name] = true;
            if(is_null($value)) {
                return;
            }
        }elseif (isset($this->type[$name])) {
            // 类型转换
            $value = $this->writeTransform($value, $this->type[$name]);
        }

        // 设置数据对象属性
        $this->data[$name] = $value;
    }

    /**
     * 数据写入 类型转换
     * @access protected
     * @param  mixed        $value 值
     * @param  string|array $type  要转换的类型
     * @return mixed
     */
    protected function writeTransform($value, $type)
    {
        if (is_null($value)) {
            return;
        }

        if (is_array($type)) {
            [$type, $param] = $type;
        } elseif (strpos($type, ':')) {
            [$type, $param] = explode(':', $type, 2);
        }

        switch ($type) {
            case 'integer':
                $value = (int) $value;
                break;
            case 'float':
                if (empty($param)) {
                    $value = (float) $value;
                } else {
                    $value = (float) number_format($value, (int) $param, '.', '');
                }
                break;
            case 'boolean':
                $value = (bool) $value;
                break;
            case 'timestamp':
                if (!is_numeric($value)) {
                    $value = strtotime($value);
                }
                break;
            case 'datetime':
                $value = is_numeric($value) ? $value : strtotime($value);
                $value = $this->formatDateTime('Y-m-d H:i:s.u', $value, true);
                break;
            case 'object':
                if (is_object($value)) {
                    $value = json_encode($value, JSON_FORCE_OBJECT);
                }
                break;
            case 'array':
                $value = (array) $value;
            case 'json':
                $option = !empty($param) ? (int) $param : JSON_UNESCAPED_UNICODE;
                $value  = json_encode($value, $option);
                break;
            case 'serialize':
                $value = serialize($value);
                break;
            default:
                if (is_object($value) && false !== strpos($type, '\\') && method_exists($value, '__toString')) {
                    // 对象类型
                    $value = $value->__toString();
                }
        }

        return $value;
    }

    /**
     * 时间日期字段格式化处理
     * @access protected
     * @param  mixed $format    日期格式
     * @param  mixed $time      时间日期表达式
     * @param  bool  $timestamp 时间表达式是否为时间戳
     * @return mixed
     */
    protected function formatDateTime($format, $time = 'now', bool $timestamp = false)
    {
        if (empty($time)) {
            return;
        }

        if (false === $format) {
            return $time;
        } elseif (false !== strpos($format, '\\')) {
            return new $format($time);
        }

        if ($time instanceof DateTime) {
            $dateTime = $time;
        } elseif ($timestamp) {
            $dateTime = new DateTime();
            $dateTime->setTimestamp((int) $time);
        } else {
            $dateTime = new DateTime($time);
        }

        return $dateTime->format($format);
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
        try {
            $value    = $this->getData($name);
        } catch (\Exception $e) {
            $relation = $this->isRelationAttr($name);
            $value    = null;
        }

        return $this->getValue($name, $value, $relation);
    }

    /**
     * 获取对象原始数据 如果不存在指定字段返回false
     * @access public
     * @param  string $fieldName 字段名 留空获取全部
     * @return mixed
     * @throws Exception
     */
    public function getData(string $fieldName = null)
    {
        if (is_null($fieldName)) {
            return $this->data;
        }

        if (array_key_exists($fieldName, $this->data)) {
            return $this->data[$fieldName];
        } elseif (array_key_exists($fieldName, $this->relation)) {
            return $this->relation[$fieldName];
        }

        throw new \Exception('Property not exists:' . static::class . '->' . $fieldName);
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
     * 销毁数据对象的值
     * @access public
     * @param string $name 名称
     * @return void
     */
    public function __unset(string $name): void
    {
        unset($this->data[$name], $this->relation[$name]);
    }

    // ArrayAccess
    public function offsetSet($name, $value)
    {
        $this->setAttr($name, $value);
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
        return $this->getAttr($name);
    }

    /**
     * 切换后缀进行查询
     * @access public
     * @param string $suffix 切换的表后缀
     * @return Model
     */
    public static function suffix(string $suffix)
    {
        $model = new static();
        $model->setSuffix($suffix);

        return $model;
    }

    /**
     * 切换数据库连接进行查询
     * @access public
     * @param PDOConnection $connection 数据库连接标识
     * @return Model
     */
    public static function connect(PDOConnection $connection)
    {
        $model = new static();
        $model->setConnection($connection);

        return $model;
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
