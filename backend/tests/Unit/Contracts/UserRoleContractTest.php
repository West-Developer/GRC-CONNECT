<?php

namespace Tests\Unit\Contracts;

use App\Domain\Identity\UserRole;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class UserRoleContractTest extends TestCase
{
    public function test_openapi_user_roles_match_backend_and_frontend_runtime_catalogs(): void
    {
        $repositoryRoot = dirname(__DIR__, 4);
        $openApi = Yaml::parseFile($repositoryRoot.'/docs/api/openapi.yaml');
        $openApiRoles = $openApi['components']['schemas']['UserResource']['properties']['role']['enum'];
        $backendRoles = array_column(UserRole::cases(), 'value');

        $frontendSource = file_get_contents(
            $repositoryRoot.'/frontend/src/features/auth/roles.ts',
        );
        self::assertIsString($frontendSource);
        self::assertSame(1, preg_match(
            '/export const userRoles = \[(?<roles>.*?)\] as const/s',
            $frontendSource,
            $matches,
        ));
        self::assertSame(count($backendRoles), preg_match_all(
            '/"([a-z_]+)"/',
            $matches['roles'],
            $roleMatches,
        ));
        $frontendRoles = $roleMatches[1];

        self::assertSame($backendRoles, $openApiRoles);
        self::assertSame($backendRoles, $frontendRoles);
    }
}
