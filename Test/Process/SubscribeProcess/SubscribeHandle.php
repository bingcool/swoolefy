<?php
namespace Test\Process\SubscribeProcess;

use Swoolefy\Core\Application;
use Swoolefy\Core\EventController;

class SubscribeHandle extends EventController
{
    public function doRun($msg)
    {
        var_dump('handle msg = '. $msg);
    }
}