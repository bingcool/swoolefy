<?php
namespace App\Controller;

use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\Task\AsyncTask;

class AsyncTaskController extends BController {
    public function test() {
        $taskData = $this->request->taskData;
        var_dump($taskData);
        AsyncTask::registerTaskfinish([new \App\Controller\AsyncTaskController, 'finish'], ['name'=>'bingcool']);
    }

    public function  finish($name) {
      var_dump($name);
    }
  }