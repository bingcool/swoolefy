<?php
namespace Test\Controller;

use Swoolefy\Annotation\ApiOperation;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;
use Swoolefy\Library\Captcha\CaptchaBuilder;

class CaptchaController extends BController
{
    /**
     * 测试生成验证码图片 base64。
     *
     * Route: GET /api/captcha/image (POST 亦可)
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/captcha/image' \
       -H 'Accept: application/json'
     ```
     */
    #[ApiOperation(description: '测试生成验证码图片 base64')]
    public function test(RequestInput $requestInput, ResponseOutput $responseOutput): array
    {
        //$responseOutput->withHeader('Content-Type', 'image/jpeg');
        $builder = new CaptchaBuilder();
        $builder->build();
        //$phrase = $builder->getPhrase();
        //var_dump($phrase);
        $inline = $builder->inline();
        return [
            'url' => $inline
        ];
    }
}
