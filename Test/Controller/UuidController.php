<?php
namespace Test\Controller;

use Swoolefy\Core\Controller\BController;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;
use Test\App;

class UuidController extends BController
{
    public function getUuid(RequestInput $requestInput)
    {
        $input = $requestInput->input();
        var_dump($input);

        var_dump($requestInput->get('id'));
        var_dump($requestInput->getProtocol());

        $ids = App::getUUid()->getIncrIds(10);
        foreach ($ids as &$id) {
            $id = (string)$id;
        }
        $this->returnJson($ids);
    }
}