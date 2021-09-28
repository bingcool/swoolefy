<?php

namespace Test\Pools;

use Swoolefy\Core\ProcessPools\AbstractProcessPools;

class TestBindWorker extends AbstractProcessPools
{

    /**
     * @inheritDoc
     */
    public function run()
    {
        var_dump('this is bind worker process, bind workerId='.$this->getBindWorkerId());
    }

    // 接收绑定worker发过来的消息
    public function onReceive($msg, ...$args)
    {
        var_dump('Receive Worker send Msg='.$msg);
    }
}