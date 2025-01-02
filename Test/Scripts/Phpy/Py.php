<?php


namespace Test\Scripts\Phpy;

use Common\Library\Captcha\CaptchaBuilder;
use Common\Library\Db\Facade\Db;
use Swoolefy\Core\Coroutine\Context;
use Swoolefy\Core\Coroutine\Parallel;
use Swoolefy\Util\Log;
use Test\App;
use Test\Logger\RunLog;

class Py extends \Swoolefy\Script\MainCliScript
{
    const command = 'test:phpy';

    public function handle()
    {
        if (!class_exists('\PyCore')) {
            fmtPrintError("请安装phpy扩展");
            return;
        }
        var_dump('以下是测试phpy');
        // 设置 Python 路径
        \PyCore::import('sys')->path->append(APP_PATH.'/Python');

        // 导入 Python 文件
        $a = \PyCore::import('a');
        // 调用 Python 函数
        $res = $a->get_dict();
        foreach ($res as $key=>$item) {
            var_dump(str_replace("\'","\"", $item->__toString()));
        }

        // phpy调用python发生IO阻塞时，并不会协程调度
        goApp(function () {
            // 导入 Python 文件
            $b = \PyCore::import('sub.b');
            $b->hello1();
            // 创建类对象
            $person = $b->Person('dabing',50);
            var_dump("姓名：".$person->getName()->__toString());
            var_dump("年龄：".$person->age);
        });

        $word = \PyCore::import('sub.word');
        // 创建类对象
//        $inputFile = 'example.docx';
//        $outputFile = 'example.pdf';
//        $docx = $word->Docx($inputFile);
//        var_dump($docx->create_doc()->__toString());
//        var_dump($docx->docx_to_pdf($inputFile, $outputFile)->__toString());


        //$pinyin = \PyCore::import('sub.pinyin');


        \PyCore::import('sub.json');

        $dict = \PyCore::import('sub.dict');
        $dict->test1();

        var_dump('测试phpy结束');
    }
}