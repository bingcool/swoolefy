<?php
namespace Test\Scripts\User;

use Common\Library\Db\Query;
use Swoolefy\Core\Application;
use Swoolefy\Script\MainCliScript;
use Test\App;

class TestPgQuery extends MainCliScript
{
    const command = 'test:pg:query';
    public function init()
    {

    }

    public function handle()
    {
        $pg = App::getPgSql();
        $list = $pg->newQuery()->table('tbl_user','a')->whereExists(function ($query) {
            /**
             * @var Query $query
             */
            $query->fieldRaw(1)->table('tbl_user','b')->where('b.id', '=', 10)->whereColumn('a.id', '=', 'b.id');
        })->select();
        var_dump($list);
    }
}