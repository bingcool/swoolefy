<?php
namespace Test\Scripts\User;

use Common\Library\Db\Query;
use Test\App;
use Test\Module\Order\OrderEntity;
use Swoolefy\Script\MainCliScript;

class TestDbQuery extends MainCliScript
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

//        OrderEntity::withoutTrashed()
//            ->where([
//                'order_id' => 1685959471
//            ])->restore();


        $order = new OrderEntity();
        try {
            $order->getConnection()->beginTransaction();
            $order->loadById(1675835369);

            $order->json_data = ['1111llll', 2222, 3333, 44444, rand(1,1000)];

            $order->lockShareWhere([
                'user_id' => 10000
            ]);
            $order->save();

            $order->json_data = ['1111llll', 2222, 3333, 44444, rand(1,1000)];

            $order->lockShareWhere([
                'user_id' => 10000
            ]);

            $order->save();

            $order->getConnection()->commit();

            var_dump('commit success');
        }catch (\Throwable $throwable) {
            $order->getConnection()->rollBack();
            var_dump($throwable->getMessage());
        }

        return;

        //var_dump($order->getAttributes());
//
        //$order->delete();

        /**
         * @var OrderEntity $order1
         */
        $order1 = OrderEntity::connection($db)
            ->where('user_id', '=',10003)
            ->first();

        //var_dump($order1);
        var_dump($order1->getAttributes());

        // 进行软删
//        OrderEntity::query()
//            ->where('user_id', '=',102)
//            ->useSoftDelete('deleted_at', date('Y-m-d H:i:s'))
//            ->delete();


        $orderList = OrderEntity::connection($db)
            //->field('*')
            //->whereIn('user_id', [102])
            ->whereJsonContains('expend_data->address', ['add' => '深圳'])
            //->whereJsonContains('expend_data->phone', '123456')
            //->whereJsonContains('expend_data->name', 'xiaomi1')
            ->where('order_product_ids', '<>', '')
            ->whereJsonContains('order_product_ids','1222')
            ->json(['json_data'])
            ->filter(function ($result) {
                return $result;
            })
            ->each(function ($item) {
                $item['name'] = 'bingcool';
                return $item;
            })
            ->limit(5)
            ->select();

       // var_dump($orderList->toArray());

        /**
         * @var OrderEntity $orderItem
         */
        foreach ($orderList as $k=>$raw) {
            var_dump($raw['user_id']);
            // 填充到Model实体,方便IDE提示
            $orderItem = OrderEntity::fill($raw);
            var_dump($orderItem->order_id);
        }


//        $list = (new Query(Application::getApp()->get('pg')->getConnection()))
//            ->table('tbl_order')
//            ->json(['json_data'])
//            ->whereJsonContains('expend_data->>address',[['add1' => '深圳']])
//            ->limit(5)
//            ->select();
//        var_dump($list);


        //var_dump("bbbb");
        //var_dump($order2);

        // var_dump($order->getAttributes());
        return;
    }

    public function handle()
    {

    }
}
