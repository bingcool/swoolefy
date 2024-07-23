<?php
namespace Test\Controller;

use Swoolefy\Core\Controller\BController;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;
use Common\Library\Captcha\CaptchaBuilder;

class CaptchaController extends BController
{
    public function test(RequestInput $requestInput, ResponseOutput $responseOutput) {
        //$responseOutput->withHeader('Content-Type', 'image/jpeg');
        $builder = new CaptchaBuilder();
        $builder->build();
        //$phrase = $builder->getPhrase();
        //var_dump($phrase);
        $inline = $builder->inline();
        $this->returnJson([
            'url' => $inline
        ]);
    }
}