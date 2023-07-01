<?php
namespace Test\Controller;

use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;

class UuidController extends BController
{
    public function getUuid()
    {
        $redis = Application::getApp()->get('redis')->getObject();
        $ids   = \Common\Library\Uuid\UuidManager::getInstance()->getIncrIds($redis,500);
        var_dump($ids);
    }
}