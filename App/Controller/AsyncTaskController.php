<?php
namespace App\Controller;

use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\Task\AsyncTask;

class AsyncTaskController extends BController {
    public $name = null;
    public function test() {
        $taskData = $this->request->taskData;
        AsyncTask::registerTaskfinish([$this, 'finish'], ['hhhhhhhhh']);
    }

    public function anyncMail() {
      $mailer = Application::$app->mailer;
      $mailer->message = [
        //邮箱主题
        "subject"=>"test",
        //发送者邮箱与定义的名称，邮箱与上面定义的user_name这里必须一致
        "from"   =>["13560491950@163.com"=>"bingcool"],
        //定义多个收件人和对应的名称
        "to"     =>['2437667702@qq.com'=>"bingcool","bingcoolhuang@gmail.com"=>"dabing"],
        //定义邮件的内容，格式可以包含html
        "body"   =>"<p>this is a mail</p>",
        // body文档类型
        "mime"   =>"text/html",
        //定义要上传的附件，可以多个，附件的大小，由代理的邮件服务器定义提供,key值代表是文件路径，name值代表是发送后的文件显示的别名，如果没设置name值，则以原文件名作为别名
        "attach" =>["/home/wwwroot/default/swoolefy/score/Test/test.docx"=>"my.docx"],
      ];

      $mailer->sendEmail();
    }

    public function  finish($name) {
      // $this->name = $name;
      var_dump($this->name);
      return ;
    }

    public static function  asyncStaticTest($data) {
      var_dump('jjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjj');
      var_dump($data);
    }
  }