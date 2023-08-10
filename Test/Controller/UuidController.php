<?php
namespace Test\Controller;

use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;
use Test\Factory;

class UuidController extends BController
{
    public function getUuid()
    {
        $ids = Factory::getUUid()->getIncrIds(10);
        $this->returnJson($ids);
    }
}