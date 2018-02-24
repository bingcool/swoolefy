<?php
namespace Swoolefy\Core\Db;

use Swoolefy\Core\Db\Mysql;
use PDO;

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
        $this->prefix = $this->Driver->getConfig('prefix');
    }

    /**
     * 获取当前的数据库Driver对象
     * @return Driver
     */
    public function getDriver() {
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

    /**
     * getFields 获取表的所有字段
     * @param    $tableName 
     * @return   array
     */
    public function getFields($tableName=null) {
        if(!$tableName) {
            $tableName = $this->getTable();
        }
        if(!empty(self::$tables_fields[$tableName])) {
            return self::$tables_fields[$tableName];
        }
        $fields_info = $this->Driver->getFields($tableName);
        if($fields_info) {
            self::$tables_fields[$tableName] = $fields_info;
            return $fields_info;
        }
    }

    /**
     * getTable 获取当前表
     * @return   string
     */
    public function getTable() {
        $table = $this->options['table'];
        $table = trim($table);
        if(strpos($table,' ') !== false) {
            $table = end(explode(' ', $table));
        }
        return trim($table);
    }

    /**
     * 指定当前操作的数据表
     * @access public
     * @param mixed $table 表名
     * @return $this
     */
    public function table($table) {
        if(is_string($table)) {
            $table = trim($table);
            $this->options['table'] = "FROM $table ";
        }else {
            return false;
        }
        return $this;
    }

    /**
     * field 获取必要的字段
     * @param    string|array  $fields 
     * @return   $this
     */
    public function field($fields) {
        $this->options['fields'] = '*';
        if($fields == '*') {
            $this->options['fields'] = $fields.' ';
            return $this;
        }
        if(empty($fields)) {
            return false;
        }

        if(is_string($fields)) {
            $fields = explode(',', $fields);
            foreach($fields as $k => $field) {
                $field = trim($field);
            }
            if($fields) {
                $this->options['fields'] = implode(',', $fields).' ';
            }
        }elseif(is_array($fields)) {
            foreach($fields as $k=>$field) {
                $field = trim($field);
            }
            if($fields) {
                $this->options['fields'] = implode(',', $fields).' ';
            }
        }
        return $this;
    }

    /**
     * where 多条件查询
     * @param    $mapSql
     * @param    $bind
     * @return   $this
     */
    public function where($mapSql, $bind=[]) {
    	if(is_string($mapSql)) {
            // 第一次设置where条件时
            if(!isset($this->options['where']) && empty($this->options['where'])) {
                $this->options['where'] .= "WHERE $mapSql ";
            }else {
                // AND 多次设置条件时
                $this->options['where'] = $this->options['where'].' AND '.$mapSql.' ';
            }   
        }

        $this->bind = array_merge($this->bind, $bind);
        return $this;
    }

    /**
     * whereOr Or 查询设置
     * @param    string  $mapSql 
     * @param    array   $bind
     * @return   $this
     */
    public function whereOr($mapSql, $bind=[]) {
        if(is_string($mapSql)) {
            if(isset($this->options['where']) && !empty($this->options['where'])) {
                $this->options['where'] = $this->options['where'].' OR '.$mapSql.' ';
            }else {
                $this->options['where'] = "WHERE $mapSql "; 
            }
        }
        $this->bind = array_merge($this->bind, $bind);
        return $this;
    }

    /**
     * limit 限制
     * @param    $start
     * @param    $offset
     * @return   $this
     */
    public function limit($start, $offset=20) {
        if(!is_int($start) || !is_int($offset)) {
            throw new \Exception(__NAMESPACE__.'::'.__FUNCTION__.'()'.' the params must be int');
        }
        $this->options['limit'] = "limit $start,$offset ";
        return $this;
    }

    /**
     * group 分组
     * @param    string|array  $groupField
     * @return   $this                  
     */
    public function group($groupField) {
        if(is_string($groupField)) {
            $groupField = explode(',', $groupField);
        }

        foreach($groupField as $k=>$group) {
           $groupField[$k] = trim($group);
        }
        $groupField = implode(',', $groupField);
        $this->options['group'] = "GROUP BY $groupField ";
        return $this;
    }

    /**
     * order 排序
     * @param    string  $field
     * @return   $this        
     */
    public function order($field) {
        $tables_fields = array_keys($this->getFields());
        if(is_string($field)) {   
            $this->options['order'] = "ORDER BY $field ";
        }
        return $this;
    }

    /**
     * having 
     * @param    string $having
     * @return   $this
     */
    public function having($having) {
       if(!empty($having)) {
            $having = trim($having);
            $thin->options['having'] = "HAVING $having ";
       }
       return $this;  
    }


    /**
     * join 
     * @param    $join_table
     * @return   $this
     */
    public function join($join_table,$map) {
        if(is_string($join_table)) {
            $join_table = trim($join_table);
            $this->options['join'] = "INNER JOIN $join_table ON $map ";
        }else {
            return false;
        }
        return $this;
    }

    /**
     * ljoin 
     * @param    $join_table
     * @return   $this
     */
    public function ljoin($join_table,$map) {
        if(is_string($join_table)) {
            $join_table = trim($join_table);
            $this->options['join'] = "LEFT JOIN $join_table ON $map ";
        }else {
            return false;
        }
        return $this;
    }

     /**
     * rjoin 
     * @param    $join_table
     * @return   $this
     */
    public function rjoin($join_table,$map) {
        if(is_string($join_table)) {
            $join_table = trim($join_table);
            $this->options['join'] = "RIGHT JOIN $join_table ON $map ";
        }else {
            return false;
        }
        return $this;
    }

    /**
     * bind 绑定参数设置
     * @param    array   $bind
     * @return   $this
     */
    public function bind($bind = []) {
        if(is_array($bind) && !$bind) {
            $this->bind = array_merge($this->bind, $bind);
        }
        return $this;
    }

    /**
     * findOne 查询一条记录
     * @return   array
     */
    public function find() {
        $sql = $this->parseSql();
        $PDOStatement = $this->Driver->query($sql, $this->bind, $master = false, $pdo = true);
        $result  = $PDOStatement->fetch(PDO::FETCH_ASSOC);
        return $result;
    }

    /**
     * getColumn 获取某一列的值
     * @param    string  $field 字段名 多个字段用逗号分隔
     * @param    string  $key 索引
     * @return   array
     */
    public function getColumn($field='', $key='') {
        $result = false;
        $sql = $this->parseSql();
        $PDOStatement = $this->Driver->query($sql, $this->bind, $master = false, $pdo = true);
        if(false === $result) {

            if(isset($this->options['fields'])) {
                unset($this->options['fields']);
            }

            if(is_null($field)) {
                $field = '*';
            } elseif ($key && '*' != $field) {
                $field = $key . ',' . $field;
            }

            if(1 == $PDOStatement->columnCount()) {
                $result = $PDOStatement->fetchAll(PDO::FETCH_COLUMN);
            }else {
                $resultSet = $PDOStatement->fetchAll(PDO::FETCH_ASSOC);
                if($resultSet) {
                        $fields = array_keys($resultSet[0]);
                        $count  = count($fields);
                        $key1   = array_shift($fields);
                        $key2   = $fields ? array_shift($fields) : '';
                        $key    = $key ?: $key1;
                        if (strpos($key, '.')) {
                            list($alias, $key) = explode('.', $key);
                        }
                        foreach ($resultSet as $val) {
                            if ($count > 2) {
                                $result[$val[$key]] = $val;
                            } elseif (2 == $count) {
                                $result[$val[$key]] = $val[$key2];
                            } elseif (1 == $count) {
                                $result[$val[$key]] = $val[$key1];
                            }
                        }
                } else {
                    $result = [];
                }
            }
        }
        return $result;
    }

    /**
     * select   获取所有的查询数据
     * @return   mixed
     */
    public function select() {
        $sql = $this->parseSql();
        $result = $this->query($sql, $this->bind);
        return $result;
    }

    /**
     * findAll   获取所有的查询数据
     * @return   mixed
     */
    public function findAll() {
        $sql = $this->parseSql();
        $result = $this->query($sql, $this->bind);
        return $result;
    }

    /**
     * insert 
     * @param    array  $data 
     * @return   
     */
    public function insert($data = []) {
        if(is_array($data) && !$data) {
           $fields = array_keys($this->getFields());
           foreach($data as $k=>&$value) {
                if(!in_array($k,$fields)) {
                    throw new \Exception($k.' field is not a field in table');
                }
                
           }
        }
    }

    /**
     * parseSql  组合分析sql
     * @return   string
     */
    public function parseSql() {
        $sql = "SELECT ";
        $sql .= $this->options['fields'];
        $sql .= $this->options['table'];
        $sql .= $this->options['join'];
        $sql .= $this->options['where'];
        $sql .= $this->options['group'];
        $sql .= $this->options['order'];
        $sql .= $this->options['limit'];
        $sql .= $this->options['having'];
        return $sql;
    }

    public function __call($method, $args) {
        
    }

    public function __destruct() {
        self::$tables_fields = [];
    }

}