<?php
namespace Swoolefy\Core\Db;

class Query {

	protected $connection = null;
	/**
     * 构造函数
     * @param Connection $connection 数据库对象实例
     * @param string     $model      模型名
     */
    public function __construct(Connection $connection = null, $model = '')
    {
        $this->connection = $connection ?: Db::connect([], true);
        $this->prefix     = $this->connection->getConfig('prefix');
        $this->model      = $model;
    }
}