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

namespace Test;

use Common\Library\HttpClient\CurlHttpClient;

class LoadEnv
{
    public static function load(string $host, string $dataId, string $groupId, string $username, string $password) {
        $client = new CurlHttpClient();
        $loginUrl = "http://{$host}/nacos/v1/auth/login";
        $response = $client->post($loginUrl, [
            'username' => $username,
            'password' => $password
        ]);

        $accessToken = $response->toArray()['accessToken'];
        $url = "http://{$host}/nacos/v1/cs/configs?dataId={$dataId}&group={$groupId}&accessToken={$accessToken}";

        $client = new CurlHttpClient();
        $response = $client->get($url);
        if (!empty($response->getBody())) {
            file_put_contents(APP_PATH. '/.env', $response->getBody());
            fmtPrintInfo(".env文件加载成功");
        }
    }
}