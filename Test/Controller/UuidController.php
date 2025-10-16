<?php
namespace Test\Controller;

use Swoolefy\Core\CommandRunner;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;
use Test\App;

class UuidController extends BController
{
    public function getUuid(RequestInput $requestInput)
    {
        $ids = App::getUUid()->getIncrIds(10);
        foreach ($ids as &$id) {
            $id = (string)$id;
        }
        $array = [1, 3, 5, 8, 10];
        $result = array_find($array, function($value) {
            if ($value % 2 === 0) {
                return true;
            }
        });
        var_dump($result);

        $is = json_validate("ggggggggggg");

        var_dump($is);


        $this->returnJson($ids);
    }
}