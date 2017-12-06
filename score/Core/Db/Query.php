<?php
namespace Swoolefy\Core\Db;

use Swoolefy\Core\Db\Mysql;

class Query {

	// 数据库Connection对象实例
    protected $connection;
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
     * @param Connection $connection 数据库对象实例
     * @param string     $model      模型名
     */
    public function __construct(Connection $connection = null, $model = '')
    {
        $this->connection = $connection;
        $this->prefix     = $this->connection->getConfig('prefix');
        $this->model      = $model;
    }

    /**
     * 获取当前的数据库Connection对象
     * @return Connection
     */
    public function getConnection()
    {
        return $this->connection;
    }

    public function __call($method, $args) {

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
    public function query($sql, $bind = [], $master = false, $class = false)
    {
        return $this->connection->query($sql, $bind, $master, $class);
    }

    /**
     * 指定当前操作的数据表
     * @access public
     * @param mixed $table 表名
     * @return $this
     */
    public function table($table)
    {
        if (is_string($table)) {
            if (strpos($table, ')')) {
                // 子查询
            } elseif (strpos($table, ',')) {
                $tables = explode(',', $table);
                $table  = [];
                foreach ($tables as $item) {
                    list($item, $alias) = explode(' ', trim($item));
                    if ($alias) {
                        $this->alias([$item => $alias]);
                        $table[$item] = $alias;
                    } else {
                        $table[] = $item;
                    }
                }
            } elseif (strpos($table, ' ')) {
                list($table, $alias) = explode(' ', $table);

                $table = [$table => $alias];
                $this->alias($table);
            }
        } else {
            $tables = $table;
            $table  = [];
            foreach ($tables as $key => $val) {
                if (is_numeric($key)) {
                    $table[] = $val;
                } else {
                    $this->alias([$key => $val]);
                    $table[$key] = $val;
                }
            }
        }
        $this->options['table'] = $table;
        return $this;
    }

    public function where($map = []) {
    	
    }



}