<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Library\Oauth;

use PhpUintTest\TestCase;
use Swoolefy\Library\Oauth\Config\OauthConfig;
use Swoolefy\Library\Oauth\Exception\InvalidProviderException;
use Swoolefy\Library\Oauth\Exception\OauthConfigException;
use Swoolefy\Library\Oauth\Exception\OauthStateException;
use Swoolefy\Library\Oauth\Exception\UnsupportedOauthCapabilityException;
use Swoolefy\Library\Oauth\Oauth;
use Swoolefy\Library\Oauth\OauthFactory;
use Swoolefy\Library\Oauth\OauthManager;
use Swoolefy\Library\Oauth\Support\OpenidModeMapper;
use Swoolefy\Library\Oauth\Testing\FakeOauthClient;
use Yurun\OAuthLogin\QQ\OAuth2 as QQOAuth2;
use Yurun\OAuthLogin\Weixin\OAuth2 as WeixinOAuth2;
use Yurun\OAuthLogin\WeWork\OAuth2 as WeWorkOAuth2;

/**
 * Oauth 模块单元测试（无真实 API Key；默认不要求联网）。
 *
 * 按需求先写好用例，暂不执行：phpunit --filter OauthModuleTest
 */
final class OauthModuleTest extends TestCase
{
    protected function tearDown(): void
    {
        Oauth::clearManager();
        parent::tearDown();
    }

    public function testConfigParsesNestedOauthKey(): void
    {
        $config = OauthConfig::fromArray([
            'oauth' => [
                'oauth_providers' => [
                    'fake' => ['driver' => 'fake'],
                ],
            ],
        ]);

        $this->assertTrue($config->hasProvider('fake'));
        $this->assertFalse($config->hasProvider('missing'));
    }

    public function testOpenidModeMapper(): void
    {
        $this->assertSame(1, OpenidModeMapper::toInt('openid'));
        $this->assertSame(2, OpenidModeMapper::toInt('unionid'));
        $this->assertSame(3, OpenidModeMapper::toInt('unionid_first'));
        $this->assertSame(1, OpenidModeMapper::toInt(null));
        $this->assertSame(2, OpenidModeMapper::toInt(2));
    }

    public function testManagerCachesClientAndFlush(): void
    {
        $mgr = new OauthManager([
            'oauth_providers' => [
                'fake' => ['driver' => 'fake'],
            ],
        ]);

        $a = $mgr->provider('fake');
        $b = $mgr->provider('fake');
        $this->assertSame($a, $b);
        $this->assertInstanceOf(FakeOauthClient::class, $a);

        $mgr->flushClients();
        $c = $mgr->provider('fake');
        $this->assertNotSame($a, $c);
    }

    public function testManagerThrowsOnUnknownProvider(): void
    {
        $mgr = new OauthManager([
            'oauth_providers' => [
                'fake' => ['driver' => 'fake'],
            ],
        ]);

        $this->expectException(InvalidProviderException::class);
        $mgr->provider('not_exists');
    }

    public function testManagerThrowsOnEmptyProviderName(): void
    {
        $mgr = new OauthManager([
            'oauth_providers' => [
                'fake' => ['driver' => 'fake'],
            ],
        ]);

        $this->expectException(InvalidProviderException::class);
        $mgr->provider('  ');
    }

    public function testFakeAuthFlow(): void
    {
        $mgr = new OauthManager([
            'oauth_providers' => [
                'fake' => ['driver' => 'fake'],
            ],
        ]);
        $client = $mgr->provider('fake');

        $url = $client->getAuthUrl('https://app.test/cb', 'state-1');
        $this->assertStringContainsString('fake-oauth.test', $url);
        $this->assertSame('state-1', $client->getState());

        $token = $client->getAccessToken('state-1', 'code-xyz', 'state-1');
        $this->assertSame('fake-access-token', $token);
        $this->assertSame('fake-openid', $client->getOpenId());

        $user = $client->getUserInfo();
        $this->assertSame('Fake User', $user['nickname']);
        $this->assertTrue($client->validateAccessToken());
        $this->assertTrue($client->refreshToken('rt'));
    }

    public function testFakeStateMismatchThrows(): void
    {
        $client = new FakeOauthClient();
        $client->getAuthUrl(null, 'good');

        $this->expectException(OauthStateException::class);
        $client->getAccessToken('good', 'code', 'bad');
    }

    public function testFakeMiniAndWeworkHelpers(): void
    {
        $client = new FakeOauthClient();
        $sessionKey = $client->getSessionKey('js-code');
        $this->assertNotSame('', $sessionKey);
        $this->assertSame('fake-mini-openid', $client->getOpenId());

        $profile = $client->decryptData('enc', 'iv', $sessionKey);
        $this->assertSame('Fake Mini User', $profile['nickName']);

        $webUrl = $client->getWebAuthUrl('https://app.test/cb', 's2');
        $this->assertStringContainsString('wework/web', $webUrl);
    }

    public function testFactoryBuildsRealDriversWithoutNetwork(): void
    {
        $factory = new OauthFactory();

        $qq = $factory->make('qq', [
            'driver' => 'qq',
            'app_id' => 'qq-app',
            'app_secret' => 'qq-secret',
            'callback_url' => 'https://app.test/oauth/qq',
            'openid_mode' => 'unionid',
        ]);
        $this->assertSame('qq', $qq->driver());
        $this->assertInstanceOf(QQOAuth2::class, $qq->raw());
        $authUrl = $qq->getAuthUrl();
        $this->assertStringContainsString('graph.qq.com', $authUrl);
        $this->assertNotNull($qq->getState());

        $wxQr = $factory->make('weixin_qr', [
            'driver' => 'weixin_qr',
            'app_id' => 'wx-app',
            'app_secret' => 'wx-secret',
            'callback_url' => 'https://app.test/oauth/wxqr',
        ]);
        $this->assertInstanceOf(WeixinOAuth2::class, $wxQr->raw());
        $this->assertStringContainsString('qrconnect', $wxQr->getAuthUrl());

        $wxOa = $factory->make('weixin_oa', [
            'driver' => 'weixin_oa',
            'app_id' => 'wx-oa',
            'app_secret' => 'wx-secret',
            'callback_url' => 'https://app.test/oauth/wxoa',
        ]);
        $oaUrl = $wxOa->getAuthUrl();
        $this->assertStringContainsString('oauth2/authorize', $oaUrl);
        $this->assertStringNotContainsString('qrconnect', $oaUrl);

        $mini = $factory->make('weixin_mini', [
            'driver' => 'weixin_mini',
            'app_id' => 'wx-mini',
            'app_secret' => 'wx-secret',
        ]);
        $this->assertSame('weixin_mini', $mini->driver());

        $wework = $factory->make('wework', [
            'driver' => 'wework',
            'app_id' => 'corp-id',
            'app_secret' => 'corp-secret',
            'callback_url' => 'https://app.test/oauth/wework',
            'agent_id' => '1000001',
        ]);
        $this->assertInstanceOf(WeWorkOAuth2::class, $wework->raw());
        $webUrl = $wework->getWebAuthUrl();
        $this->assertStringContainsString('login.work.weixin.qq.com', $webUrl);

        foreach (['feishu', 'dingtalk'] as $driver) {
            $client = $factory->make($driver, [
                'driver' => $driver,
                'app_id' => "{$driver}-app",
                'app_secret' => "{$driver}-secret",
                'callback_url' => "https://app.test/oauth/{$driver}",
            ]);
            $this->assertSame($driver, $client->driver());
            $this->assertNotSame('', $client->getAuthUrl());
        }

        $alipay = $factory->make('alipay', [
            'driver' => 'alipay',
            'app_id' => 'alipay-app',
            'callback_url' => 'https://app.test/oauth/alipay',
            'app_private_key' => "-----BEGIN RSA PRIVATE KEY-----\nMIIEowIBAAKCAQEA0Z3VS5JJcds3xfn/ygWyF6PZX6V\n-----END RSA PRIVATE KEY-----",
        ]);
        $this->assertSame('alipay', $alipay->driver());
        $this->assertStringContainsString('openauth.alipay.com', $alipay->getAuthUrl());
    }

    public function testWeixinMiniRejectsBrowserFlow(): void
    {
        $client = (new OauthFactory())->make('weixin_mini', [
            'driver' => 'weixin_mini',
            'app_id' => 'wx-mini',
            'app_secret' => 'wx-secret',
        ]);

        $this->expectException(UnsupportedOauthCapabilityException::class);
        $client->getAuthUrl();
    }

    public function testQqRejectsWebAuthUrl(): void
    {
        $client = (new OauthFactory())->make('qq', [
            'driver' => 'qq',
            'app_id' => 'qq-app',
            'app_secret' => 'qq-secret',
            'callback_url' => 'https://app.test/cb',
        ]);

        $this->expectException(UnsupportedOauthCapabilityException::class);
        $client->getWebAuthUrl();
    }

    public function testFactoryRequiresCredentials(): void
    {
        $factory = new OauthFactory();

        $this->expectException(OauthConfigException::class);
        $factory->make('qq', [
            'driver' => 'qq',
            'app_id' => '',
            'app_secret' => 'x',
            'callback_url' => 'https://app.test/cb',
        ]);
    }

    public function testFactoryRequiresAlipayPrivateKey(): void
    {
        $factory = new OauthFactory();

        $this->expectException(OauthConfigException::class);
        $factory->make('alipay', [
            'driver' => 'alipay',
            'app_id' => 'alipay-app',
            'callback_url' => 'https://app.test/cb',
        ]);
    }

    public function testFactoryUnknownDriver(): void
    {
        $this->expectException(InvalidProviderException::class);
        (new OauthFactory())->make('x', ['driver' => 'github']);
    }

    public function testStaticOauthHelper(): void
    {
        $mgr = new OauthManager([
            'oauth_providers' => [
                'fake' => ['driver' => 'fake'],
            ],
        ]);
        Oauth::setManager($mgr);
        $this->assertSame($mgr, Oauth::manager());
        $this->assertInstanceOf(FakeOauthClient::class, Oauth::provider('fake'));
    }
}
