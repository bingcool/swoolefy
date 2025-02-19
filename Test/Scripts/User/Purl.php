<?php
namespace Test\Scripts\User;

use Common\Library\HttpClient\CurlHttpClient;
use Common\Library\Purl\Url;
use Swoolefy\Script\MainCliScript;

class Purl extends MainCliScript
{
    const command = 'purl:test';

    public function handle()
    {
        $url = (new Url('http://jwage.com'))
            ->set('scheme', 'https')
            ->set('port', '443')
            ->set('user', 'jwage')
            ->set('pass', 'password')
            ->set('path', 'about/me')
            ->set('query', 'param1=value1&param2=value2')
            ->set('fragment', 'about/me?param1=value1&param2=value2');

        $newUrl = $url->getUrl();
        var_dump(parse_url($newUrl));
        $curl = new CurlHttpClient();
        $url1 = $curl->parseUrl($newUrl,['age'=> 1999]);
        var_dump($url1);
    }
}