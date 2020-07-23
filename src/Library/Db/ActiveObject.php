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

class ActiveObject extends Model {

    /**
     * 表名
     * @var string
     */
    protected $table;

    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    /**
     * 获取当前模型的数据库
     * @return PDOConnection
     */
    public function getConnection()
    {
        // TODO: Implement getConnection() method.
    }

    /**
     * 如果是主键没有设置自增，那么自定义创建primary key的值,需要开发者实现pk值生成
     */
    public function createPkValue()
    {

    }

    /**
     * 插入之前事件操作，返回false停止往下执行
     * @return mixed|void
     */
    protected function onBeforeInsert()
    {
        return true;
    }

    /**
     * 数据新增保存之后事件操作
     */
    protected function onAfterInsert()
    {

    }

    /**
     * 更新之前事件操作，返回false停止往下执行
     * @return mixed|void
     */
    protected function onBeforeUpdate()
    {
        return true;
    }

    /**
     * 更新之后事件操作
     */
    protected function onAfterUpdate()
    {

    }

    /**
     * 删除之前事件操作，返回false停止往下执行
     * @return mixed|void
     */
    protected function onBeforeDelete()
    {
        return true;
    }

    /**
     * 删除之后事件操作
     */
    protected function onAfterDelete()
    {

    }

}