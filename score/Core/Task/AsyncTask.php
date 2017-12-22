<?php
namespace Swoolefy\Core\Task;

use Swoolefy\Core\Swfy;

class AsyncTask {

    public static function registerTask($route, $data) {
        $route = '/'.trim($route,'/');
        array_push($data,$route);
        Swfy::$server->task($data);
    }

    public static function finish() {

    }
}