<?php
namespace App\Controller;

use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;

class SessionController extends BController{
    public function test() {
        dump($_SESSION);
        $_SESSION['app'.rand(1,1000)] = 'android';
        Application::$app->session->save();
    }

    public function getSession() {
        dump($_SESSION);
    }
    
}