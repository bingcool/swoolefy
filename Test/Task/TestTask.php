<?php

namespace Test\Task;

use Swoolefy\Core\Application;
use Swoolefy\Core\EventController;
use Swoolefy\Core\Task\TaskController;

class TestTask extends TaskController
{

    public function doRun(array $data)
    {
        $fromWorkerId = $this->getFromWorkerId();
        $msg = is_array($data) ? json_encode($data) : $data;
        var_dump("Task Process Receive data from workerId={$fromWorkerId} and msg=".$msg);

        $userId = $data['user_id'];
        /**
         * @var \Common\Library\Db\Mysql $db
         */
        $db = Application::getApp()->get('db');
        $result = $db->createCommand('select * from tbl_users where id=:user_id limit 1')->queryAll([
            ':user_id' => $userId
        ]);

        var_dump('Task Process Find Db Result as：');
        print_r($result);

        $cid = \Co::getCid();
        $db1 = $this->get('db');
        var_dump("协程Cid={$cid}, Db spl_object_id=".spl_object_id($db).', spl_object_id='.spl_object_id($db1));

        // 创建协程单例，所有组件互相隔离在不同协程，特别是DB|Redis组件，必须要隔离，否则造成上下文污染
        \Swoole\Coroutine::create(function () {
            $eventApp = (new \Swoolefy\Core\EventApp())->registerApp(function ($event) {
                // registerApp闭包里面必须通过这样的组件获取组件实例
                $db = $this->get('db');
                $db1 = $this->get('db');
                $cid = \Co::getCid();
                // 再嵌套协程单例应用
                go(function () {
                    $eventApp = (new \Swoolefy\Core\EventApp())->registerApp(function ($event) {
                        // registerApp闭包里面必须通过这样的组件获取组件实例
                        $db = Application::getApp()->get('db');
                        $db1 = $this->get('db');
                        $cid = \Co::getCid();
                        var_dump("协程Cid={$cid}, Db spl_object_id=".spl_object_id($db).', spl_object_id='.spl_object_id($db1));
                    });
                });
                var_dump("协程Cid={$cid}, Db spl_object_id=".spl_object_id($db).', spl_object_id='.spl_object_id($db1));

            });
        });

        $this->finishTask($data);
    }
}