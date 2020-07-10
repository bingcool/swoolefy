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

/**
 * Connection interface
 */
interface ConnectionInterface
{
    /**
     * 连接数据库方法
     * @access public
     * @param array   $config  接参数
     * @return mixed
     */
    public function connect(array $config = []);

    /**
     * 获取数据库的配置参数
     * @access public
     * @param string $config 配置名称
     * @return mixed
     */
    public function getConfig(string $config = '');

    /**
     * 关闭数据库（或者重新连接）
     * @access public
     * @return $this
     */
    public function close();

    /**
     * @param $sql
     * @param $bindParams
     * @param $timeOut
     * @return mixed
     */
    public function query(string $sql, array $bindParams): array;

    /**
     * @param string $sql
     * @param array $bindParams
     * @return int
     */
    public function execute(string $sql, array $bindParams = []): int;

    /**
     * 启动事务
     * @access public
     * @return void
     */
    public function beginTransaction();

    /**
     * 用于非自动提交状态下面的查询提交
     * @access public
     * @return void
     */
    public function commit();

    /**
     * 事务回滚
     * @access public
     * @return void
     */
    public function rollback();

    /**
     * 获取最近一次查询的sql语句
     * @access public
     * @return string
     */
    public function getLastSql(): string;

}
