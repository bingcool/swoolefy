<?php
namespace Test\Controller;

use Swoolefy\Annotation\ApiOperation;
use Swoolefy\Core\CommandRunner;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;
use Test\App;

class UuidController extends BController
{
    /**
     * 测试获取自增 UUID 列表。
     *
     * Route: GET /api/getUuid
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/getUuid' \
       -H 'Accept: application/json'
     ```
     */
    #[ApiOperation(description: '测试获取自增 UUID 列表')]
    public function getUuid(RequestInput $requestInput): array
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
        $is = json_validate("\"ggggggggggg\"");
        var_dump($is);
        return $ids;
    }
}
