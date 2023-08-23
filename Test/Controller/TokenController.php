<?php
namespace Test\Controller;

use Common\Library\Jwt\Encoding\ChainedFormatter;
use Common\Library\Jwt\Encoding\JoseEncoder;
use Common\Library\Jwt\Signer\Hmac\Sha256;
use Common\Library\Jwt\Signer\Key\InMemory;
use Common\Library\Jwt\Token\Builder;
use Common\Library\Jwt\Validation\Constraint\HasClaimWithValue;
use Common\Library\Jwt\Validation\Constraint\RelatedTo;
use Common\Library\Jwt\Validation\Constraint\ValidAt;
use Common\Library\Jwt\Validation\Validator;
use DateTimeImmutable;
use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;

class TokenController extends BController
{
    public function jwt()
    {
        $key = "kkkkkkkkkkkkhyggggggggsddddddddddd";
        $tokenBuilder = (new Builder(new JoseEncoder(), ChainedFormatter::default()));
        $algorithm    = new Sha256();
        $signingKey   = InMemory::plainText($key);

        $now   = new DateTimeImmutable();

        $token = $tokenBuilder
            // Configures the issuer (iss claim)
            ->issuedBy('http://example.com')
            // Configures the audience (aud claim)
            ->permittedFor('http://example.org')
            // Configures the id (jti claim)
            ->identifiedBy('4f1g23a12aa')
            // Configures the time that the token was issue (iat claim)
            ->issuedAt($now)
            // Configures the time that the token can be used (nbf claim)
            ->canOnlyBeUsedAfter($now->modify('+1 minute'))
            // Configures the expiration time of the token (exp claim)
            ->expiresAt($now->modify('+10 second'))
            // Configures a new claim, called "uid"
            ->withClaim('uid', 1)
            ->relatedTo('1234567891')
            // Configures a new header, called "foo"
            ->withHeader('foo', 'bar')
            // Builds a new token
            ->getToken($algorithm, $signingKey);

        $tokenStr = $token->toString();


        $parser = new \Common\Library\Jwt\Token\Parser(new JoseEncoder());
        $tokenObj = $parser->parse($tokenStr);
        //var_dump($tokenObj->headers()->all());

        $validator = new Validator();

        if (!$validator->validate($tokenObj, new RelatedTo('1234567891'))) {

            echo 'Invalid token (1)!', PHP_EOL; // will print this
        }

        if (!$validator->validate($tokenObj, new RelatedTo('1234567890'))) {
            //var_dump($validator->getErrorMsg());
            echo 'Invalid token (2)!', PHP_EOL; // will not print this
        }

        if (!$validator->validate($tokenObj, new HasClaimWithValue('uid',1))) {
            echo 'Invalid token (3)!', PHP_EOL; // will not print this
        }

        if (!$validator->validate($tokenObj, new ValidAt())) {
            echo 'Invalid token  expire (4)!', PHP_EOL; // will not print this
        }

        $this->returnJson(['token' =>$tokenStr]);

    }
}