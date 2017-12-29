<?php
namespace Swoolefy\Core\Db;

use Swoolefy\Core\Db\Mysql;

class Query {

	// 数据库Connection对象实例
    protected $Driver;
    // 数据库Builder对象实例
    protected $builder;
    // 当前模型类名称
    protected $model;
    // 当前数据表名称（含前缀）
    protected $table = '';
    // 当前数据表名称（不含前缀）
    protected $name = '';
    // 当前数据表主键
    protected $pk;
    // 当前数据表前缀
    protected $prefix = '';
    // 查询参数
    protected $options = [];
    // 参数绑定
    protected $bind = [];
    // 数据表信息
    protected static $info = [];
    // 数据表子段
    protected static $tables_fields = [];
	/**
     * 构造函数
     * @param Driver $Driver 数据库对象实例
     * @param string     $model      模型名
     */
    public function __construct(Driver $Driver = null, $model = '')
    {
        $this->Driver = $Driver;
        $this->prefix    = $this->Driver->getConfig('prefix');
    }

    /**
     * 获取当前的数据库Driver对象
     * @return Driver
     */
    public function getDriver()
    {
        return $this->Driver;
    }

    /**
     * 执行查询 返回数据集
     * @param string      $sql    sql指令
     * @param array       $bind   参数绑定
     * @param boolean     $master 是否在主服务器读操作
     * @param bool|string $class  指定返回的数据集对象
     * @return mixed
     * @throws BindParamException
     * @throws PDOException
     */
    public function query($sql, $bind = [], $master = false, $class = false) {
        return $this->Driver->query($sql, $bind);
    }

     /**
     * 执行语句
     * @param string $sql  sql指令
     * @param array  $bind 参数绑定
     * @return int
     * @throws BindParamException
     * @throws PDOException
     */
    public function execute($sql, $bind = []) {
        return $this->Driver->execute($sql, $bind);
    }

    public function getFields($tableName=null) {
        if(!$tableName) {
            $tableName = $this->options['table'];
        }
        if(!empty(self::$tables_fields[$tableName])) {
            return self::$tables_fields[$tableName];
        }
        $fields_info = $this->Driver->getFields($tableName);
        if($fields_info) {
            return self::$tables_fields[$tableName];
        }
    }

    public function getTable() {
        return $this->options['table'];
    }

    /**
     * 指定当前操作的数据表
     * @access public
     * @param mixed $table 表名
     * @return $this
     */
    public function table($table) {
        if(is_string($table)) {
            $this->options['table'] = "FROM $table";
        }else {
            return false;
        }
        return $this;
    }

    public function field($fields) {
        $this->options['fields'] = '*';
        if($fields == '*') {
            $this->options['fields'] = $fileds;
            return $this;
        }
        if(empty($fields)) {
            return false;
        }
        $tables_fields = array_keys($this->getFields());
        if(is_string($fields)) {
            $fields = explode(',', $fields);
            foreach($fields as $k=>$field) {
                if(strpos($field,' ')) {
                    $field = substr($field, 0, 8);
                }
                if(!in_array($field, $tables_fields)) {
                    unset($fields[$k]);
                }
            }
            if($fields) {
                $this->options['fields'] = implode(',', $fields);
            }
        }elseif(is_array($fields)) {
            foreach($fields as $k=>$field) {
                if(!in_array($field, $tables_fields)) {
                    unset($fields[$k]);
                }
            }
            if($fields) {
                $this->options['fields'] = implode(',', $fields);
            }
        }
        return $this;
    }

    public function where($mapSql, $bind=[]) {
    	if(is_string($mapSql)) {
           $this->options['where'] = $mapSql;
        }
        $this->bind = array_merge($this->bind, $bind);
        return $this;
    }

    public function limit($start, $offset=20) {
        if(!is_int($start) || !is_int($offset)) {
            throw new \Exception(__NAMESPACE__.'::'.__FUNCTION__.'()'.' the params must be int');
        }
        $this->options['limit'] = "limit $start,$offset";
        return $this;
    }

    public function group($groupField) {
        if(is_string($groupField)) {
            $groupField = explode(',', $groupField);
        }

        $tables_fields = array_keys($this->getFields());
        foreach($groupField as $group) {
            if(!in_array($group, $tables_fields)) {
                throw new \Exception(__NAMESPACE__.'::'.__FUNCTION__.'()'.' the field:'.$group.' is not in table');
            }
        }
        $groupField = implode($groupField);
        $this->options['group'] = "GROUP BY $groupField";
        return $this;
    }

    public function order($field) {
        $tables_fields = array_keys($this->getFields());
        if(is_string($field)) {
            $fields = explode(' ', $field);
            if(!in_array($fields[0], $tables_fields)) {
                throw new \Exception(__NAMESPACE__.'::'.__FUNCTION__.'()'.' the field:'.$group.' is not in table');
            }
            if(isset($fields[1]) && !in_array($fields[1], ['DESC', 'desc'])) {
                throw new \Exception(__NAMESPACE__.'::'.__FUNCTION__.'()'.' the order commend must be DESC or desc');
            }

            $this->options['order'] = $field;
        }
        return $this;
    }

    public function join() {

    }

    public function findAll() {
        $sql = $this->parseSql();

        $result = $this->query($sql, $this->bind);
        return $result;

    }

    public function parseSql() {
        $sql = "SELECT";
        $sql .= $this->options['fields'];
        $sql .= $this->options['table'];
        $sql .= $this->options['join'];
        return $sql;
    }

    public function __call($method, $args) {
        
    }

    public function __destruct() {
        self::$tables_fields = [];
    }



}