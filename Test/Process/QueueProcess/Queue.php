<?php
namespace Test\Process\QueueProcess;

use Swoolefy\Core\Process\AbstractProcess;
use Test\Factory;

class Queue extends AbstractProcess {
    const queue_order_list = 'queue:order:delay';
    public function run()
    {
        goAfter(2000, function () {
            Factory::getDelayQueue()
                ->addItem(["order_id" => 1111], 2)
                ->addItem(["order_id" => 2222], 2)
                ->push();
        });

        while (true) {
            $items = Factory::getDelayQueue()->pop();
//            var_dump($items);
            foreach ($items as $item) {
                var_dump($item);
                //Factory::getDelayQueue()->retry($item, 5);
            }

            sleep(1);
        }
    }
}