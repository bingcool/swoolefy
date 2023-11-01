<?php
namespace Test\Controller;

use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;
use Test\Module\Order\OrderEntity;
use Test\Module\Order\OrderFormatter;

class PgController extends BController
{
    public function savePgOrder()
    {
        $userId = 10000;
        /**
         * @var \Common\Library\Db\Pgsql $pg
         */

        $userId = 10000;
        $receiver_user_name = '李四';
        $receiver_user_phone = '12344556';
        $order_amount =100;
        $address = "广东省深圳xxxxxx";
        $order_product_ids = [1222,345,567,rand(1,1000)];
        $json_data = ['name'=>'xiaomi', 'phone'=>123456789];
        $order_status = 1;
        $remark = 'test-remark-'.rand(1,1000);

        $pg = Application::getApp()->get('pg');
        $query = $pg->newQuery();
        $query->table('tbl_order')->insert([
            'order_id' => time(),
            'user_id' => 10000,
            'receiver_user_name' => $receiver_user_name,
            'receiver_user_phone' => $receiver_user_phone,
            'order_amount' => $order_amount,
            'address' => $address,
            'order_product_ids' => json_encode($order_product_ids),
            'json_data' => json_encode($json_data),
            'order_status' => $order_status,
            'remark' => $remark
        ]);
        $id = $query->getLastInsID();

        $client = new \GuzzleHttp\Client([
            'handler' => \Common\Library\CurlProxy\CurlProxyHandler::getStackHandler(), // 只需把handler注入进来即可
            'base_uri' => "http://127.0.0.1:9501",
        ]);
        $response = $client->get('/api/send-task-worker');
        $result = $response->getBody()->getContents();
        $result = json_decode($result, true);
        var_dump($result);

        $this->returnJson(['id' => $id]);

    }

    public function savePgOrder1()
    {
        $userId = 10000;

        $struct = new \Swoolefy\Core\Struct();
        $struct->set('name', 'bingcool');

        $orderObject = new \Test\Module\Order\OrderPgEntity($userId);
        $orderObject->user_id = $userId;
        $orderObject->receiver_user_name = "张三";
        $orderObject->receiver_user_phone = "12344556";
        $orderObject->order_amount = 123.50;
        $orderObject->address = "广东省深圳xxxxxx";
        $orderObject->order_product_ids = [1222, 345, 567, rand(1, 1000)];
        $orderObject->json_data = ['name' => 'xiaomi', 'phone' => 123456789];
        $orderObject->order_status = 1;
        $orderObject->remark = 'test-remark-' . rand(1, 1000) . '-' . $struct->get('name');
        $orderObject->setFormatter(new OrderFormatter());
        //$orderObject->skipEvent(OrderEntity::AFTER_INSERT);

        // 自定义 事件覆盖原事件
        $orderObject->setEventHandle(OrderEntity::AFTER_INSERT, function () {
            /**
             * @var \Test\Module\Order\OrderEntity $this
             */
            $this->onAfterInsert();
            //var_dump('next onAfterInsert');

        });

        $orderObject->save();

        if ($orderObject->isExists()) {
            $this->returnJson($orderObject->getAttributes());
        }

    }

}