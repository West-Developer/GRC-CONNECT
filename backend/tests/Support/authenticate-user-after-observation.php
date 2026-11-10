<?php

use App\Actions\Auth\AuthenticateUser;
use App\Actions\Auth\IssueSanctumToken;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\Exceptions\InvalidCredentialsException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$credentials = json_decode((string) fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
$email = $credentials['email'];
$password = $credentials['password'];
$requestId = $credentials['request_id'];

$observed = false;
DB::listen(static function (QueryExecuted $query) use (&$observed): void {
    if ($observed || preg_match('/\bfrom\s+[`"]?users[`"]?/i', $query->sql) !== 1) {
        return;
    }

    $observed = true;
    fwrite(STDOUT, "OBSERVED\n");
    fflush(STDOUT);
    fgets(STDIN);
});

// Mirrors LoginController exactly: AuthenticateUser alone issues no token —
// a token can only ever exist if BOTH steps run, so a stale/rotated password
// rejected by the re-lock-and-recheck inside AuthenticateUser must leave the
// kiosk with zero tokens, the same property this test verified before the
// AuthenticateUser/IssueSanctumToken split.
try {
    $context = new AuditRequestContext($requestId, '127.0.0.1');
    $user = $app->make(AuthenticateUser::class)->handle($email, $password, $context);
    $app->make(IssueSanctumToken::class)->handle($user, 'observed-login', 'password', $context);
    fwrite(STDOUT, "AUTHENTICATED\n");
} catch (InvalidCredentialsException) {
    fwrite(STDOUT, "REJECTED\n");
}
