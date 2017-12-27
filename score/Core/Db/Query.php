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
    // 回调事件
    private static $event = [];
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

    public function getFields($tableName) {
        return ;
    }

    /**
     * 指定当前操作的数据表
     * @access public
     * @param mixed $table 表名
     * @return $this
     */
    public function table($table) {
        if(is_string($table)) {
            $this->options['table'] = $table;
        }else {
            return false;
        }
        return $this;
    }

    public function field($fields) {
        if(is_string($fields)) {

        }elseif(is_array($fields)) {

        }elseif($fields == '*') {

        }
    }

    public function where($map = [], $op="AND") {
    	if(is_array($map)) {

        }
    }

    public function limit() {

    }

    public function group() {

    }

    public function order() {

    }

    public function __call($method, $args) {
        
    }



}