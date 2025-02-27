<?php
namespace Test\Controller;

use Swoolefy\Core\Controller\BController;
use Test\Model\ClientModel;
use Test\Module\Order\OrderEntity;
use Test\Module\Order\OrderFormatter;
use Test\Module\Order\OrderList;

class ObjectController extends BController
{
    /**
     * saveOrder
     */
    public function saveOrder()
    {
        $userId = 10000;

        $struct = new \Swoolefy\Core\Struct();
        $struct->set('name', 'bingcool');

        $orderObject = new \Test\Module\Order\OrderEntity();
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

    /**
     * @throws \Exception
     */
    public function updateOrder()
    {
        $userId = 10000;

        $orderId = 1698317204;

        $orderObject = new \Test\Module\Order\OrderEntity($userId);
        if($orderObject->isExists()) {
            $orderObject->user_id = $userId;
            $orderObject->inc('order_amount', 1);
            //$orderObject->exp('order_amount', 'order_amount * 2');
            //$orderObject->order_product_ids = [1222,345,567,rand(1,1000)];
            $orderObject->json_data = ['name'=>'xiaomi', 'phone'=>123456789];
            $orderObject->order_status = 1;
            $orderObject->remark = 'test-remark-'.rand(1,1000);
            $orderObject->save();
            $lastSql = $orderObject->getLastSql();
            $this->returnJson($orderObject->getAttributes());
        }else {
            throw new \Exception('OrderEntity is not exist');
        }

    }


    /**
     * @throws \Exception
     */
    public function list()
    {
        $list = new OrderList();
        $list->setOrderStatus(1);
        $list->setPage(0);
        $list->setPageSize(10);
        //$list->setOrderId(1687344505);
        $result = $list->find();
        $count = $list->total();
        $this->returnJson([
            'total' => $count,
            'list' => $result
        ]);
    }

    public function addBank()
    {
        /**
         * @var ClientModel $model
         */
        $model = new class extends ClientModel
        {
            /**
             * @var string
             */
            protected static $table = 'tbl_banks';

            /**
             * @var string
             */
            protected $pk = 'id';

            protected $casts = [
                'address' => 'array'
            ];

            /**
             * @return int|mixed
             */
            public function createPkValue()
            {

            }
        };

        $model->setData([
            'name' => 'bank-'.rand(1, 1000),
            'address' => [
                'sheng' => '广东省',
                'city' => '深圳市'
            ],
        ]);

        $model->save();

        $this->returnJson($model->getAttributes());

//        $id = $model->getConnection()->newQuery()->table('tbl_banks')->insert([
//            'name' => 'bank-'.rand(1, 1000),
//            'address' => [
//                'sheng' => '广东省',
//                'city' => '深圳市'
//            ],
//        ]);
//        var_dump($id);


    }
}