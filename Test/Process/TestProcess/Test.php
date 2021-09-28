<?php
namespace Test\Process\TestProcess;

use Swoolefy\Core\Application;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Process\AbstractProcess;

class Test extends AbstractProcess
{

    /**
     * @inheritDoc
     */
    public function run()
    {
        while (true)
        {
            try {
                $cid = \Co::getCid();
                /**
                 * @var \Common\Library\Db\Mysql $db
                 */
                $db = Application::getApp()->get('db');
                //var_dump("This is Test Process, cid={$cid}, class=".__CLASS__.", db_object_id=".spl_object_id($db));
                sleep(5);
            }catch (\Throwable $e)
            {
                BaseServer::catchException($e);
            }
        }
    }

    public function onReceive($msg, ...$args)
    {
        // receive from worker process
        var_dump('This is Test Process Receive Msg From Worker, Msg='.$msg);

        // write to worker process
        $this->getProcess()->write('hello, I am Test Process');
    }
}