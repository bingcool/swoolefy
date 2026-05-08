<?php
namespace Test\Module\Demo\Controller;

use Swoolefy\Core\Controller\BController;
use Test\Module\Demo\Validation\DemoValidation;

class DemoController extends BController
{

    /**
     * @see DemoValidation::test()
     * @return void
     */
    public function test(): array
    {
        return ['desc' => 'this is a demo'];
    }

    public function test1(): array
    {
        return ['desc' => 'this is a demo2-'.rand(1,10000)];
    }
}