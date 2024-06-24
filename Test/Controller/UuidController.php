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
        $input = $requestInput->input();
        var_dump($input);

        var_dump($requestInput->get('id'));

        $ids = Factory::getUUid()->getIncrIds(10);
        foreach ($ids as &$id) {
            $id = (string)$id;
        }
        $this->returnJson($ids);
    }
}