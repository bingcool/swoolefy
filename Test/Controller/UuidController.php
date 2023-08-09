<?php
namespace Test\Controller;

use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;

class UuidController extends BController
{
    public function getUuid()
    {
        $uuid = Application::getApp()->get('uuid');
        $ids  = $uuid->getIncrIds(100);
        $this->returnJson($ids);
    }
}