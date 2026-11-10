<?php

namespace Tests\Feature\Api\V1;

use App\Http\Middleware\AssignRequestId;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use PDOException;
use RuntimeException;
use Tests\TestCase;
use Throwable;

final class ApiErrorEnvelopeTest extends TestCase
{
    public function test_unknown_api_route_returns_the_safe_not_found_envelope(): void
    {
        $this->assertErrorEnvelope(
            method: 'getJson',
            uri: '/api/v1/does-not-exist',
            requestId: 'not-found-correlation',
            status: 404,
            code: 'NOT_FOUND',
            message: 'The requested resource was not found.',
        );
    }

    public function test_unsupported_api_method_returns_the_safe_method_not_allowed_envelope(): void
    {
        $this->assertErrorEnvelope(
            method: 'postJson',
            uri: '/api/v1/health',
            requestId: 'method-correlation',
            status: 405,
            code: 'METHOD_NOT_ALLOWED',
            message: 'The HTTP method is not allowed for this endpoint.',
        );
    }

    public function test_unhandled_api_exception_returns_a_safe_server_error_envelope(): void
    {
        Route::middleware('api')->get('/api/v1/test/unhandled', function (): never {
            throw new RuntimeException('Sensitive internal exception detail.');
        });

        $response = $this
            ->withHeader(AssignRequestId::HEADER, 'server-error-correlation')
            ->getJson('/api/v1/test/unhandled');

        $response
            ->assertStatus(500)
            ->assertHeader(AssignRequestId::HEADER, 'server-error-correlation')
            ->assertExactJson([
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'An unexpected server error occurred.',
                    'errors' => [],
                    'request_id' => 'server-error-correlation',
                ],
            ])
            ->assertDontSee('Sensitive internal exception detail.');
    }

    public function test_on_a_local_machine_a_database_that_is_behind_the_code_says_so_and_names_the_fix(): void
    {
        $this->app['env'] = 'local';
        $this->routeThatFailsWith($this->databaseError('42S22', "Unknown column 'queue_tickets.student_profile_id' in 'where clause'"));

        $response = $this->withHeader(AssignRequestId::HEADER, 'schema-correlation')->getJson('/api/v1/test/database');

        $response->assertStatus(500)->assertJsonPath('error.code', 'INTERNAL_ERROR');
        self::assertStringContainsString('php artisan migrate', (string) $response->json('error.message'));
        // The fix is named; the query and the column are not (they stay in the log).
        $response->assertDontSee('queue_tickets')->assertDontSee('student_profile_id');
    }

    public function test_a_missing_table_gets_the_same_hint_on_a_local_machine(): void
    {
        $this->app['env'] = 'local';
        $this->routeThatFailsWith($this->databaseError('42S02', "Table 'grc.academic_terms' doesn't exist"));

        $this->getJson('/api/v1/test/database')
            ->assertStatus(500)
            ->assertSee('php artisan migrate', false);
    }

    public function test_outside_a_local_machine_the_same_database_error_stays_generic(): void
    {
        $this->app['env'] = 'production';
        $this->routeThatFailsWith($this->databaseError('42S22', "Unknown column 'queue_tickets.student_profile_id' in 'where clause'"));

        $this->getJson('/api/v1/test/database')
            ->assertStatus(500)
            ->assertJsonPath('error.message', 'An unexpected server error occurred.')
            ->assertDontSee('migrate')
            ->assertDontSee('queue_tickets');
    }

    public function test_any_other_database_error_stays_generic_even_on_a_local_machine(): void
    {
        $this->app['env'] = 'local';
        $this->routeThatFailsWith($this->databaseError('23000', "Duplicate entry 'x' for key 'users_email_unique'"));

        $this->getJson('/api/v1/test/database')
            ->assertStatus(500)
            ->assertJsonPath('error.message', 'An unexpected server error occurred.');
    }

    private function databaseError(string $sqlState, string $detail): QueryException
    {
        $driverError = new PDOException("SQLSTATE[{$sqlState}]: {$detail}");
        $driverError->errorInfo = [$sqlState, 1054, $detail];

        return new QueryException('mariadb', 'select * from `queue_tickets` where `student_profile_id` in (?)', [1], $driverError);
    }

    private function routeThatFailsWith(Throwable $exception): void
    {
        Route::middleware('api')->get('/api/v1/test/database', function () use ($exception): never {
            throw $exception;
        });
    }

    private function assertErrorEnvelope(
        string $method,
        string $uri,
        string $requestId,
        int $status,
        string $code,
        string $message,
    ): void {
        $response = $this
            ->withHeader(AssignRequestId::HEADER, $requestId)
            ->{$method}($uri);

        $response
            ->assertStatus($status)
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeader(AssignRequestId::HEADER, $requestId)
            ->assertExactJson([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'errors' => [],
                    'request_id' => $requestId,
                ],
            ]);
    }
}
