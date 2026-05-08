<?php
namespace Test\Controller;

use Swoolefy\Core\Controller\BController;
use Test\App;

class QueueController extends BController
{
    public function push(): array
    {
        App::getQueue()->push(['id' => rand(1,1000)]);
        return ['id' => rand(1, 2)];
    }
}