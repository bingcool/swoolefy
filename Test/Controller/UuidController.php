<?php
namespace Test\Controller;

use Swoolefy\Core\Controller\BController;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;
use Test\Factory;

class UuidController extends BController
{
    public function getUuid(RequestInput $requestInput)
    {
        $requestInput->setTrustedProxies(['127.0.0.1']);
        $input = $requestInput->input();

        $ids = Factory::getUUid()->getIncrIds(10);
        foreach ($ids as &$id) {
            $id = (string)$id;
        }
        $this->returnJson($ids);
    }
}