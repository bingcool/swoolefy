<?php

/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

use Common\Library\Amqp\AmqpStreamConnectionFactory;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Swoolefy\Core\Application;
use Symfony\Component\Translation\Loader\PhpFileLoader;
use Symfony\Component\Translation\Translator;
use Test\Config\AmqpConfig;
use Test\Config\KafkaConfig;

$dc = \Swoolefy\Core\SystemEnv::loadDcEnv();

return [
    'translator' => function() use($dc) {
        if (\Swoolefy\Core\Coroutine\Context::has('lang_locale')) {
            $locale = \Swoolefy\Core\Coroutine\Context::get('lang_locale');
        } else {
            $locale = 'en';
        }
        // 创建 Translator 实例
        $translator = new Translator($locale); // 设置默认语言
        // 创建 PhpFileLoader 实例
        $loader = new PhpFileLoader();
        $translator->addLoader('php', $loader);
        $translator->addResource('php', APP_PATH."/Resource/Translations/{$locale}/messages.php", $locale);
        return $translator;
    }
];