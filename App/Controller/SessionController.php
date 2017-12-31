<?php
namespace App\Controller;

use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;

class SessionController extends BController{
    public function test() {
        $_SESSION['app'.rand(1,1000)] = 'android';
        dump($_SESSION);
    }

    public function getSession() {
        dump($_SESSION);
    }
    
}