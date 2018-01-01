<?php
namespace App\Controller;

use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\MGeneral;

class SessionController extends BController{
    public function test() {
        $_SESSION['app'.rand(1,1000)] = 'android';
        dump($_SESSION);
    }

    public function getSession() {
        dump($_SESSION);
    }

    public function setTest() {
        $key = $_GET['key'].rand(1,9999);
        $res = $this->session->set($key,'ioscool');
        dump($res);
    }

    public function getTest() {
        MGeneral::xhprof();
        $data = $this->session->get();
        dump($data);
    }
    
}