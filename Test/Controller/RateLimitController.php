<?php
namespace Test\Controller;

use Test\App;
use Swoolefy\Core\Controller\BController;

class RateLimitController extends BController
{
    public function ratetest1()
    {
        $rateLimit = App::getRateLimit();
        $rateLimit->setRateKey('rate-order-search');
        $rateLimit->setLimitParams(120, 30, 1800);
        if (!$rateLimit->isLimit()) {
            $this->returnJson(['msg' => 'ok-'.rand(1, 1000)]);
        }else {
            $this->returnJson(['msg' => '流量过大']);
        }
    }

}