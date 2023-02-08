<?php
namespace Test\Controller;

use Swoolefy\Core\Controller\BController;

class ObjectController extends BController
{
    /**
     * saveOrder
     */
    public function saveOrder()
    {
        $userId = 10000;

        $struct = new \Swoolefy\Core\Struct();
        $struct->set('name','bingcool');

        $orderObject = new \Test\Module\Order\OrderObject($userId);
        $orderObject->user_id = $userId;
        $orderObject->receiver_user_name = "张三";
        $orderObject->receiver_user_phone = "12344556";
        $orderObject->order_amount = 123.50;
        $orderObject->address = "广东省深圳xxxxxx";
        $orderObject->order_product_ids = [1222,345,567,rand(1,1000)];
        $orderObject->json_data = ['name'=>'xiaomi', 'phone'=>123456789];
        $orderObject->order_status = 1;
        $orderObject->remark = 'test-remark-'.rand(1,1000).'-'.$struct->get('name');
        $orderObject->save();
        if($orderObject->isExists()) {
            $this->returnJson($orderObject->getAttributes());
        }
    }

    /**
     * @throws \Exception
     */
    public function updateOrder()
    {
        $userId = 10000;

        $orderId = 1633494944;

        $orderObject = new \Test\Module\Order\OrderObject($userId, $orderId);
        if($orderObject->isExists())
        {
            $orderObject->user_id = $userId;
            $orderObject->order_amount = 123.50;
            $orderObject->order_product_ids = [1222,345,567,rand(1,1000)];
            $orderObject->json_data = ['name'=>'xiaomi', 'phone'=>123456789];
            $orderObject->order_status = 1;
            $orderObject->remark = 'test-remark-'.rand(1,1000);

            $orderObject->save();
            dump($orderObject);
        }else
        {
            throw new \Exception('OrderObject is not exist');
        }

    }
}