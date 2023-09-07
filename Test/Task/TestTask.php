<?php

namespace Test\Task;

use Swoolefy\Core\Application;
use Swoolefy\Core\Log\LogManager;
use Swoolefy\Core\Task\TaskController;
use Test\Factory;

class TestTask extends TaskController
{

    public function doRun(array $taskData)
    {
        $fromWorkerId = $this->getFromWorkerId();
        $msg = is_array($taskData) ? json_encode($taskData) : $taskData;
        var_dump("Task Process Receive data from workerId={$fromWorkerId} and msg=".$msg);

        $userId = $taskData['user_id'];
        $db = Factory::getDb();
        $result = $db->createCommand('select * from tbl_users where user_id=:user_id limit 1')->queryAll([
            ':user_id' => $userId
        ]);

        //var_dump('Task Process Find Db Result as：');
        //print_r($result);

        $cid = \Swoole\Coroutine::getCid();
        $db1 = $this->get('db');
        //var_dump("协程Cid={$cid}, Db spl_object_id=".spl_object_id($db).', spl_object_id='.spl_object_id($db1));

        // 创建协程单例，所有组件互相隔离在不同协程，特别是DB|Redis组件，必须要隔离，否则造成上下文污染
        goApp(function () {
            // registerApp闭包里面必须通过这样的组件获取组件实例
            $db  = $this->get('db');
            $db1 = $this->get('db');
            $cid = \Swoole\Coroutine::getCid();

            //var_dump("协程1-Cid={$cid}, Db spl_object_id=".spl_object_id($db).', spl_object_id='.spl_object_id($db1));

            // 再嵌套协程单例应用
            goApp(function () {
                // registerApp闭包里面必须通过这样的组件获取组件实例
                $db = Factory::getDb();
                $db1 = $this->get('db');
                $cid = \Swoole\Coroutine::getCid();
                //var_dump("协程2-Cid={$cid}, Db spl_object_id=".spl_object_id($db).', spl_object_id='.spl_object_id($db1));

                $log = LogManager::getInstance()->getLogger('log');
                $log->addInfo('task task-log-id='.rand(1,1000),true, ['name'=>'bingcoolhuang']);
            });
        });



        $this->finishTask($taskData);
    }
}