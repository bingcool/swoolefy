<?php
namespace Test\Module\Order\Service;

class LogOrderService
{
    public int $logId = 0;
    public function logOrder()
    {
        var_dump(LogOrderService::class.':logId='.$this->logId);
    }
}