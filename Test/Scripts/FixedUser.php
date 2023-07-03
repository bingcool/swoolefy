<?php

namespace Test\Scripts;

use Common\Library\Db\Mysql;
use Swoolefy\Core\Application;

class FixedUser extends \Swoolefy\Script\MainCliScript
{
    public function fixName()
    {
        try {
            var_dump('CID='.\Swoole\Coroutine::getCid());
            var_dump('Script test');
            sleep(4);
            /**
             * @var Mysql $db
             */
            $db = Application::getApp()->get('db');
            $result = $db->newQuery()->setDebug()->table('tbl_order')->limit(1)->select();
            var_dump($result);
        }catch (\Throwable $exception) {
            var_dump($exception->getMessage());
        }

        //$this->exitAll();
    }

    public function onHandleException(\Throwable $throwable, array $context = [])
    {
        parent::onHandleException($throwable, $context); // TODO: Change the autogenerated stub
    }

}