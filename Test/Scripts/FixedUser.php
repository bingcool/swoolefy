<?php

namespace Test\Scripts;

use Common\Library\Db\Mysql;
use Swoolefy\Core\Application;

class FixedUser extends \Swoolefy\Script\MainCliScript
{
    /**
     * @var Mysql
     */
    protected $db;

    public function init()
    {
        parent::init(); // TODO: Change the autogenerated stub
        $this->db = Application::getApp()->get('db');
    }

    public function fixName()
    {
        try {
            $name = getenv('name');
            var_dump("name=".$name);
            var_dump('CID='.\Swoole\Coroutine::getCid());
            var_dump('Script test');
            sleep(2);

            var_dump('spl_object_id='.spl_object_id($this->db));
            $result1 = $this->db->newQuery()->table('tbl_users')->limit(1)->select()->toArray();
            //var_dump($result1);

            goApp(function () {
                try {
                    var_dump('CID11='.\Swoole\Coroutine::getCid());
                    var_dump('spl_object_id-11='.spl_object_id($this->db));
                    $result1 = Application::getApp()->get('db')->newQuery()->table('tbl_users')->limit(1)->select()->toArray();
                    var_dump($result1);
                }catch (\Throwable $exception) {
                    var_dump($exception->getMessage());
                }

            });

        }catch (\Throwable $exception) {
            var_dump($exception->getMessage());
        }
    }

    public function onHandleException(\Throwable $throwable, array $context = [])
    {
        parent::onHandleException($throwable, $context); // TODO: Change the autogenerated stub
    }

}