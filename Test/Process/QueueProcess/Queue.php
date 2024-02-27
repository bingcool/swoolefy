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
                ->addItem(["order_id" => 1111], 10)
                ->addItem(["order_id" => 2222], 10)
                ->push();
        });

        while (true) {
            $items = Factory::getDelayQueue()->pop();
            foreach ($items as $item) {
                var_dump(date("Y-m-d H:i:s"));
                var_dump($item);
            }
            sleep(1);
        }
    }
}