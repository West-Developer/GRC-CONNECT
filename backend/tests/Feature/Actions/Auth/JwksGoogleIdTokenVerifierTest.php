<?php

namespace Tests\Feature\Actions\Auth;

use App\Domain\Identity\Exceptions\InvalidGoogleCredentialException;
use App\Support\Auth\JwksGoogleIdTokenVerifier;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;
use Tests\TestCase;

/**
 * The one test in the suite touching real JWT/JWKS mechanics: a locally
 * generated RS256 keypair signs tokens with `firebase/php-jwt`'s own
 * `JWT::encode()`, and `Http::fake()` serves that keypair's public JWK in
 * place of Google's real certs endpoint. Every other Google-related test
 * (`GoogleLoginEndpointTest`) swaps the `GoogleIdTokenVerifier` binding for a
 * fake instead — no test anywhere in this suite needs network access or a
 * real Google account.
 */
final class JwksGoogleIdTokenVerifierTest extends TestCase
{
    private const KID = 'test-key-1';

    private const CLIENT_ID = 'test-client-id.apps.googleusercontent.com';

    private OpenSSLAsymmetricKey $keyPair;

    private string $privateKeyPem;

    /** The openssl.cnf path that worked for key generation, if a fallback was needed — export needs the same one. */
    private ?string $opensslConfigPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        // The `array` cache store used in tests is in-memory per process and
        // is not reset by RefreshDatabase, so a JWKS cached by an earlier
        // test would otherwise mask this test's own faked response.
        Cache::forget('google-jwks');

        $this->keyPair = $this->newRsaKeyPair();
        $exportOptions = $this->opensslConfigPath === null ? [] : ['config' => $this->opensslConfigPath];
        // Exporting straight into an uninitialized typed property by
        // reference errors once a 4th (options) argument is also passed —
        // a PHP/ext-openssl quirk, not a real failure; a local variable
        // sidesteps it.
        openssl_pkey_export($this->keyPair, $privateKeyPem, null, $exportOptions);
        $this->privateKeyPem = $privateKeyPem;

        config(['services.google.client_id' => self::CLIENT_ID]);

        $details = openssl_pkey_get_details($this->keyPair)['rsa'];

        Http::fake([
            'https://www.googleapis.com/oauth2/v3/certs' => Http::response([
                'keys' => [[
                    'kty' => 'RSA',
                    'alg' => 'RS256',
                    'use' => 'sig',
                    'kid' => self::KID,
                    'n' => $this->base64UrlEncode($details['n']),
                    'e' => $this->base64UrlEncode($details['e']),
                ]],
            ]),
        ]);
    }

    /**
     * `openssl_pkey_new()` silently returns `false` on some Windows PHP
     * installs (this one included) unless `openssl.cnf` is pointed to
     * explicitly — the extension can't find it via its usual search path.
     * Only ever affects this local test's own throwaway keypair, never
     * anything shipped. `OPENSSL_CONF` is the standard escape hatch for any
     * other Windows setup this doesn't already cover.
     */
    private function newRsaKeyPair(): OpenSSLAsymmetricKey
    {
        $options = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $keyPair = @openssl_pkey_new($options);

        if ($keyPair !== false) {
            return $keyPair;
        }

        foreach ($this->candidateOpensslConfigPaths() as $path) {
            if (! is_file($path)) {
                continue;
            }

            $keyPair = @openssl_pkey_new($options + ['config' => $path]);

            if ($keyPair !== false) {
                $this->opensslConfigPath = $path;

                return $keyPair;
            }
        }

        throw new \RuntimeException(
            'openssl_pkey_new() failed and no working openssl.cnf was found. '
            .'Set the OPENSSL_CONF environment variable to a valid openssl.cnf path.',
        );
    }

    /** @return list<string> */
    private function candidateOpensslConfigPaths(): array
    {
        $fromEnv = getenv('OPENSSL_CONF');

        return array_values(array_filter([
            $fromEnv !== false ? $fromEnv : null,
            'C:\\xampp\\apache\\conf\\openssl.cnf',
            'C:\\xampp\\php\\extras\\ssl\\openssl.cnf',
        ]));
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** @param array<string, mixed> $overrides */
    private function token(array $overrides = []): string
    {
        $now = time();
        $claims = array_merge([
            'iss' => 'https://accounts.google.com',
            'aud' => self::CLIENT_ID,
            'sub' => 'google-subject-123',
            'email' => 'someone@grc.test',
            'email_verified' => true,
            'iat' => $now,
            'exp' => $now + 3600,
        ], $overrides);

        return JWT::encode($claims, $this->privateKeyPem, 'RS256', self::KID);
    }

    public function test_a_well_formed_token_is_accepted(): void
    {
        $claims = app(JwksGoogleIdTokenVerifier::class)->verify($this->token());

        self::assertSame('someone@grc.test', $claims['email']);
        self::assertTrue($claims['email_verified']);
        self::assertSame('google-subject-123', $claims['sub']);
    }

    public function test_a_wrong_audience_is_rejected(): void
    {
        $this->expectException(InvalidGoogleCredentialException::class);

        app(JwksGoogleIdTokenVerifier::class)->verify($this->token(['aud' => 'someone-elses-client-id']));
    }

    public function test_a_wrong_issuer_is_rejected(): void
    {
        $this->expectException(InvalidGoogleCredentialException::class);

        app(JwksGoogleIdTokenVerifier::class)->verify($this->token(['iss' => 'https://evil.example']));
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $this->expectException(InvalidGoogleCredentialException::class);

        app(JwksGoogleIdTokenVerifier::class)->verify(
            $this->token(['iat' => time() - 7200, 'exp' => time() - 3600]),
        );
    }

    public function test_an_unverified_email_is_rejected(): void
    {
        $this->expectException(InvalidGoogleCredentialException::class);

        app(JwksGoogleIdTokenVerifier::class)->verify($this->token(['email_verified' => false]));
    }

    public function test_a_tampered_signature_is_rejected(): void
    {
        $this->expectException(InvalidGoogleCredentialException::class);

        $tampered = substr($this->token(), 0, -5).'AAAAA';

        app(JwksGoogleIdTokenVerifier::class)->verify($tampered);
    }
}
