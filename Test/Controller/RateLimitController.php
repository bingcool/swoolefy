<?php
namespace Test\Controller;

use Test\App;
use Swoolefy\Core\Controller\BController;

class RateLimitController extends BController
{
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