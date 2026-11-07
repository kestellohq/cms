<?php

declare(strict_types=1);

namespace Kestello\Tests\Helpers;

use Kestello\Framework\Testing\TestCase;
use Kestello\Support\Clock;
use Kestello\Support\Jwt\Base64Url;
use Kestello\Support\Jwt\Jwt;
use Kestello\Support\Jwt\JwtException;
use Kestello\Support\Jwt\JwtKey;

final class JwtTest extends TestCase
{
    protected function tearDown(): void
    {
        Clock::unfreeze();
    }

    /** RFC 7515 A.1 (HS256) and A.2 (RS256) example tokens verify. */
    public function testRfc7515Vectors(): void
    {
        Clock::freeze('@1300819000');
        $hsKey = Base64Url::decode('AyM1SysPpbyDfgZld3umj1qzKObwVMkoqQ-EstJQLr_T-1qS0gZH75aKtMN3Yj0iPS4hcgUuTwjAzZr1Z9CAow');
        $hsToken = 'eyJ0eXAiOiJKV1QiLA0KICJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJqb2UiLA0KICJleHAiOjEzMDA4MTkzODAsDQogImh0dHA6Ly9leGFtcGxlLmNvbS9pc19yb290Ijp0cnVlfQ.dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $claims = Jwt::decode($hsToken, [JwtKey::hmac($hsKey)], iss: 'joe');
        $this->assertSame(true, $claims['http://example.com/is_root']);

        $rsToken = 'eyJhbGciOiJSUzI1NiJ9.eyJpc3MiOiJqb2UiLA0KICJleHAiOjEzMDA4MTkzODAsDQogImh0dHA6Ly9leGFtcGxlLmNvbS9pc19yb290Ijp0cnVlfQ.cC4hiUPoj9Eetdgtv3hF80EGrhuB__dzERat0XF9g2VtQgr9PJbu3XOiZj5RZmh7AAuHIm4Bh-0Qc_lF5YKt_O8W2Fp5jujGbds9uJdbF9CUAr7t1dnZcAcQjbKBYNX4BAynRFdiuB--f_nZLgrnbyTyWzO75vRK5h6xBArLIARNPvkSjtQBMHlb1L07Qe7K0GarZRmB_eSN9383LcOLn6_dO--xi12jzDwusC-eOkHWEsqtFZESc6BfI7noOPqvhJ1phCnvWh6IeYI2w9QOYEUipUTI8np6LbgGY9Fs98rqVt5AXLIhWkWywlVmtVrBp0igcN_IoypGlUPQGe77Rw';
        $jwk = [
            'kty' => 'RSA',
            'n' => 'ofgWCuLjybRlzo0tZWJjNiuSfb4p4fAkd_wWJcyQoTbji9k0l8W26mPddxHmfHQp-Vaw-4qPCJrcS2mJPMEzP1Pt0Bm4d4QlL-yRT-SFd2lZS-pCgNMsD1W_YpRPEwOWvG6b32690r2jZ47soMZo9wGzjb_7OMg0LOL-bSf63kpaSHSXndS5z5rexMdbBYUsLA9e-KXBdQOS-UTo7WTBEMa2R2CapHg665xsmtdVMTBQY4uDZlxvb3qCo5ZwKh9kG4LT6_I5IhlJH7aGhyxXFvUK-DWNmoudF8NAco9_h9iaGNj8q2ethFkMLs91kzk2PAcDTW9gb54h4FRWyuXpoQ',
            'e' => 'AQAB',
        ];
        $this->assertSame('joe', Jwt::decode($rsToken, [JwtKey::fromJwk($jwk)])['iss']);

        Clock::freeze('@1300819500');
        $this->assertThrows(JwtException::class, fn() => Jwt::decode($hsToken, [JwtKey::hmac($hsKey)]), 'expired (past leeway)');
    }

    public function testRoundTripsEveryAlgorithm(): void
    {
        $claims = ['sub' => '42', 'exp' => time() + 60];

        $hs = JwtKey::hmac(str_repeat('k', 32));
        $this->assertSame('42', Jwt::decode(Jwt::encode($claims, $hs), [$hs])['sub']);

        foreach (['RS256' => [OPENSSL_KEYTYPE_RSA, null], 'ES256' => [OPENSSL_KEYTYPE_EC, 'prime256v1'], 'ES384' => [OPENSSL_KEYTYPE_EC, 'secp384r1']] as $alg => [$type, $curve]) {
            $pk = openssl_pkey_new(['private_key_type' => $type, 'private_key_bits' => 2048] + ($curve ? ['curve_name' => $curve] : []));
            openssl_pkey_export($pk, $pem);
            $public = openssl_pkey_get_details($pk)['key'];
            // Many signatures: ECDSA r/s with leading zero bytes must survive the DER ↔ raw conversion.
            for ($i = 0; $i < ($type === OPENSSL_KEYTYPE_EC ? 40 : 2); $i++) {
                $token = Jwt::encode($claims + ['i' => $i], JwtKey::fromPem($pem, $alg));
                $this->assertSame($i, Jwt::decode($token, [JwtKey::fromPem($public, $alg)])['i'], "{$alg} #{$i}");
            }
        }

        $kp = sodium_crypto_sign_keypair();
        $token = Jwt::encode($claims, JwtKey::ed25519(sodium_crypto_sign_secretkey($kp)));
        $this->assertSame('42', Jwt::decode($token, [JwtKey::ed25519(sodium_crypto_sign_publickey($kp))])['sub']);
    }

    public function testAlgorithmConfusionAndNoneAreRefused(): void
    {
        $pk = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $publicPem = openssl_pkey_get_details($pk)['key'];
        $rsa = JwtKey::fromPem($publicPem, 'RS256');

        // Classic attack: sign HS256 with the RSA public key as the HMAC secret.
        $forged = Jwt::encode(['sub' => 'admin', 'exp' => time() + 60], JwtKey::hmac($publicPem));
        $e = $this->assertThrows(JwtException::class, fn() => Jwt::decode($forged, [$rsa]));
        $this->assertContains('No key matches', $e->getMessage());

        $none = Base64Url::encode('{"alg":"none","typ":"JWT"}') . '.' . Base64Url::encode('{"sub":"admin","exp":' . (time() + 60) . '}') . '.';
        $this->assertThrows(JwtException::class, fn() => Jwt::decode($none, [$rsa]));
        $noneCase = Base64Url::encode('{"alg":"NoNe"}') . '.' . Base64Url::encode('{"sub":"x"}') . '.';
        $this->assertThrows(JwtException::class, fn() => Jwt::decode($noneCase, [$rsa]));

        $this->assertThrows(JwtException::class, fn() => JwtKey::hmac('short'), 'weak HMAC secret');
        $this->assertThrows(JwtException::class, fn() => JwtKey::fromPem($publicPem, 'ES256'), 'key type must match');
        $this->assertThrows(JwtException::class, fn() => Jwt::encode([], $rsa), 'public keys cannot sign');
    }

    public function testClaimChecks(): void
    {
        Clock::freeze('@1800000000');
        $k = JwtKey::hmac(str_repeat('s', 32), kid: 'a');
        $t = static fn(array $c) => Jwt::encode($c, $k);
        $now = 1800000000;

        $this->assertSame('x', Jwt::decode($t(['sub' => 'x', 'exp' => $now - 30]), [$k])['sub'], 'within leeway');
        $this->assertThrows(JwtException::class, fn() => Jwt::decode($t(['exp' => $now - 61]), [$k]), 'expired');
        $this->assertThrows(JwtException::class, fn() => Jwt::decode($t(['sub' => 'x']), [$k]), 'no exp');
        $this->assertSame('x', Jwt::decode($t(['sub' => 'x']), [$k], requireExp: false)['sub']);
        $this->assertThrows(JwtException::class, fn() => Jwt::decode($t(['exp' => $now + 60, 'nbf' => $now + 120]), [$k]), 'not yet valid');
        $this->assertThrows(JwtException::class, fn() => Jwt::decode($t(['exp' => $now + 60, 'iat' => $now + 120]), [$k]), 'issued in the future');
        $this->assertThrows(JwtException::class, fn() => Jwt::decode($t(['exp' => $now + 60, 'iat' => $now - 4000]), [$k], maxAge: 3600), 'too old');
        $this->assertThrows(JwtException::class, fn() => Jwt::decode($t(['exp' => $now + 60, 'iss' => 'a']), [$k], iss: 'b'));
        $this->assertSame(['x', 'y'], Jwt::decode($t(['exp' => $now + 60, 'aud' => ['x', 'y']]), [$k], aud: 'y')['aud']);
        $this->assertThrows(JwtException::class, fn() => Jwt::decode($t(['exp' => $now + 60, 'aud' => 'x']), [$k], aud: 'z'));

        $other = JwtKey::hmac(str_repeat('o', 32), kid: 'b');
        $this->assertSame(1, Jwt::decode($t(['exp' => $now + 60, 'n' => 1]), [$other, $k])['n'], 'picked by kid');
        $this->assertThrows(JwtException::class, fn() => Jwt::decode($t(['exp' => $now + 60]), [$other]), 'unknown kid');

        [$h, $p, $s] = explode('.', $t(['exp' => $now + 60, 'role' => 'user']));
        $tampered = $h . '.' . Base64Url::encode('{"exp":' . ($now + 60) . ',"role":"admin"}') . '.' . $s;
        $this->assertThrows(JwtException::class, fn() => Jwt::decode($tampered, [$k]), 'tampered payload');
        $this->assertThrows(JwtException::class, fn() => Jwt::decode('a.b', [$k]), 'malformed');
        $this->assertThrows(JwtException::class, fn() => Jwt::decode('a!.b.c', [$k]), 'bad base64url');
    }
}
