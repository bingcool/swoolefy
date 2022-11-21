<?php

namespace Test\Scripts;

use Swoolefy\Core\Application;

class FixedUser extends \Swoolefy\Script\MainCliScript
{
    public function fixName()
    {
        try {
            var_dump('CID='.\Co::getCid());
            var_dump('Script test');
            sleep(100);
            $db = Application::getApp()->get('db');
            $result = $db->createCommand('select * from tbl_users limit 1')->queryAll();
            dump($result);
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