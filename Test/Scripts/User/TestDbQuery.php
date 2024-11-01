<?php
namespace Test\Scripts\User;

use Common\Library\Db\Query;
use Test\App;
use Test\Module\Order\OrderEntity;
class TestDbQuery extends \Swoolefy\Script\MainCliScript
{
    const command = 'test:db:query';

    public function init()
    {
        parent::init();
        $uid = 100;
        $db = App::getDb()->getObject();
        $sql = (new OrderEntity())
            ->setConnection($db)
            ->getQuery()
            ->alias('a')
            ->when($uid > 90, function (Query $query) {
                $query->where('a.user_id', '=', 101)->limit(0, 10);
            })
            ->field('a.*,b.user_name')
            //->whereNotDelete('tbl_users.deleted_at')
            ->leftJoin('tbl_users b', 'a.user_id=b.user_id')
            ->buildSql();

        // 构建子句
        $list = (new OrderEntity())->newQuery()->from($sql, 'aa')->buildSql(false);

        //var_dump($list);

        $order = new OrderEntity();
        $order->loadById(1685959471);
//        OrderEntity::withoutTrashed()
//            ->where([
//                'order_id' => 1685959471
//            ])->restore();
//        $order->json_data = [1111, 2222, 3333, 44444, rand(100,2000)];
//        $order->save();
//
        //$order->delete();

        /**
         * @var OrderEntity $order1
         */
        $order1 = OrderEntity::connection($db)
            ->where('user_id', '=',10003)
            ->first();

        //var_dump($order1);
        //var_dump($order1->getAttributes());

        // 进行软删
//        OrderEntity::query()
//            ->where('user_id', '=',102)
//            ->useSoftDelete('deleted_at', date('Y-m-d H:i:s'))
//            ->delete();


        $orderList = (new OrderEntity())->getQuery()
            ->field('*')
            ->where('user_id', '=', 101)
            ->json(['json_data'])
            ->filter(function ($result) {
                return $result;
            })
            ->each(function ($item) {
                $item['name'] = 'bingcool';
                return $item;
            })
            ->select()
            ->toArray();

        //var_dump($orderList);
        return;
        /**
         * @var OrderEntity $orderItem
         */
        foreach ($orderList as $orderItem) {
            // 填充到Model实体,方便IDE提示
            $orderItem = (new OrderEntity())->fill($orderItem);
            var_dump($orderItem->json_data);
        }
        var_dump("bbbb");
        //var_dump($order2);

        // var_dump($order->getAttributes());
        return;
    }

    public function testDbQuery()
    {

    }
}
