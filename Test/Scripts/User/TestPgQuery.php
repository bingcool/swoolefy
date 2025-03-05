<?php
namespace Test\Scripts\User;

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
        $list = $pg->newQuery()->table('tbl_user')->select();
        var_dump($list);
    }
}