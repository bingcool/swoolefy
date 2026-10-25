<?php

declare(strict_types=1);

namespace Test\Controller;

use Swoolefy\Annotation\ApiOperation;
use Swoolefy\Core\Controller\BController;
use Test\Common\MongodbVerify;

/**
 * Mongodb 模块联调入口。
 *
 * Route: GET /api/mongodb/verify
 *
 * ```bash
 * curl -X GET 'http://127.0.0.1:9501/api/mongodb/verify' -H 'Accept: application/json'
 * ```
 */
class MongodbController extends BController
{
    #[ApiOperation(description: 'Mongodb 模块端到端验证')]
    public function mongodbverify(): array
    {
        return MongodbVerify::run();
    }
}
