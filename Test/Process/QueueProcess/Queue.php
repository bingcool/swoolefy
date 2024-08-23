<?php
namespace Test\Process\QueueProcess;

use Swoolefy\Core\Process\AbstractProcess;
use Test\App;

class Queue extends AbstractProcess {
    const queue_order_list = 'queue:order:delay';
    public function run()
    {
        goAfter(2000, function () {
            App::getDelayQueue()
                ->addItem(["order_id" => 1111], 2)
                ->addItem(["order_id" => 2222], 2)
                ->push();
        });

        while (true) {
            $items = App::getDelayQueue()->pop();
//            var_dump($items);
            foreach ($items as $item) {
                var_dump($item);
                //App::getDelayQueue()->retry($item, 5);
            }

            sleep(1);
        }
    }
}