<?php
namespace Test\Controller;

use Test\App;
use Swoolefy\Annotation\ApiOperation;
use Swoolefy\Core\Controller\BController;

class RateLimitController extends BController
{
    /**
     * 测试接口限流 RateLimit。
     *
     * Route: GET /api/rate-test1
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/rate-test1' \
       -H 'Accept: application/json'
     ```
     */
    #[ApiOperation(description: '测试接口限流 RateLimit')]
    public function ratetest1(): array
    {
        $rateLimit = App::getRateLimit();
        $rateLimit->setRateKey('rate-order-search');
        $rateLimit->setLimitParams(5, 5);
        if (!$rateLimit->isLimit()) {
            return ['msg' => 'ok-'.rand(1, 1000)];
        }else {
            return ['msg' => '流量过大'];
        }
    }

}
