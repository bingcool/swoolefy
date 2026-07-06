<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Swoolefy\Core\Coroutine\Context as SwooleContext;
use Swoolefy\Library\Db\Interceptor\TenantBootstrap;
use Swoolefy\Library\Db\Interceptor\TenantLineDemoHandler;
use Swoolefy\Library\Db\Mysql;
use Swoolefy\Library\Db\Query;
use Swoolefy\Library\Db\Sql;

define('START_DIR_ROOT', dirname(__DIR__, 2));
define('APP_PATH', START_DIR_ROOT);

Dotenv::createImmutable(START_DIR_ROOT)->safeLoad();

$dc = include START_DIR_ROOT . '/Config/dc.php';
$db = new Mysql($dc['mysql_db']);

TenantBootstrap::register(new TenantLineDemoHandler());

function printSql(Mysql $db, ?string $tenantId, string $label): void
{
    SwooleContext::set('tenant_id', $tenantId);

    $subTable = $db->newQuery()
        ->table('tbl_order')
        ->where(['user_id' => 10000])
        ->limit(1)
        ->buildSql();

    $t1 = Sql::table($subTable)->as('t1');
    $t2 = Sql::table('tbl_users')->as('t2');
    $t3 = Sql::table('tbl_banks')->as('t3');

    $querySql = $db->newQuery()
        ->field([$t1->C('user_id')])
        ->from($t1)
        ->whereExists(function (Query $query) use ($t1, $t2) {
            $query->table($t2)
                ->field($t2->C('*'))
                ->whereColumn($t1->C('user_id'), '=', $t2->C('user_id'));
        })
        ->when(true, function ($query) use ($t1) {
            $query->where($t1->C('user_id'), '<>', 1);
        })
        ->whereExists(function ($query) use ($t1, $t3) {
            $query->table($t3)
                ->field([1])
                ->whereColumn($t1->C('user_id'), '=', $t3->C('id'))
                ->where(function ($query) use ($t3) {
                    $query->whereOr($t3->C('name'), 'bank-198');
                    $query->whereOr([$t3->C('id') => 100011]);
                });
        })
        ->join($t3, Sql::andOn(
            Sql::on($t1->C('user_id'), $t3->C('id')),
            Sql::on($t1->C('user_id'), $t3->C('id')),
        ))
        ->whereRaw(sprintf('%s=%d', $t1->C('user_id'), 10000000000))
        ->order([$t1->C('user_id') => 'desc'])
        ->fetchSql()
        ->select();

    echo "\n========== {$label} ==========\n";
    echo "subTable SQL:\n{$subTable}\n\n";
    echo "main query SQL:\n{$querySql}\n";
}

printSql($db, null, '无 tenant_id（不拦截）');
printSql($db, '10001', 'tenant_id=10001（拦截）');
