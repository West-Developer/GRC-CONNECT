<?php

namespace App\Support\Http;

use App\Domain\Identity\Exceptions\ActingContextChangedException;
use App\Domain\Identity\Exceptions\GoogleAccountNotFoundException;
use App\Domain\Identity\Exceptions\InvalidCredentialsException;
use App\Domain\Identity\Exceptions\InvalidGoogleCredentialException;
use App\Domain\Identity\Exceptions\LoginOtpDeliveryFailedException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PDOException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class ApiExceptionRenderer
{
    public static function render(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        if ($exception instanceof ValidationException) {
            return ApiErrorResponse::make(
                $request,
                ApiErrorCode::ValidationFailed,
                'The submitted data is invalid.',
                422,
                $exception->errors(),
            );
        }

        // Deliberately identical for a missing account, a wrong password, and
        // a disabled account, so the API cannot be used to enumerate users.
        if ($exception instanceof InvalidCredentialsException) {
            return ApiErrorResponse::make(
                $request,
                ApiErrorCode::Unauthenticated,
                'The provided credentials are incorrect.',
                401,
            );
        }

        if ($exception instanceof LoginOtpDeliveryFailedException) {
            return ApiErrorResponse::make(
                $request,
                ApiErrorCode::ServerError,
                'We could not send your verification code. Please try again in a moment.',
                503,
            );
        }

        if ($exception instanceof InvalidGoogleCredentialException) {
            return ApiErrorResponse::make(
                $request,
                ApiErrorCode::Unauthenticated,
                'Your Google sign-in could not be verified. Please try again.',
                401,
            );
        }

        // Deliberately identical whether the Google email matches no account
        // at all or matches a Disabled one — same enumeration-safety
        // reasoning as InvalidCredentialsException above.
        if ($exception instanceof GoogleAccountNotFoundException) {
            return ApiErrorResponse::make(
                $request,
                ApiErrorCode::NotFound,
                $exception->getMessage(),
                404,
            );
        }

        if ($exception instanceof ActingContextChangedException) {
            return ApiErrorResponse::make(
                $request,
                ApiErrorCode::ActingContextChanged,
                $exception->getMessage(),
                409,
            );
        }

        if ($exception instanceof AuthenticationException) {
            return ApiErrorResponse::make(
                $request,
                ApiErrorCode::Unauthenticated,
                'Authentication is required.',
                401,
            );
        }

        if ($exception instanceof AuthorizationException) {
            return ApiErrorResponse::make(
                $request,
                ApiErrorCode::Forbidden,
                'You are not authorized to perform this action.',
                403,
            );
        }

        if ($exception instanceof NotFoundHttpException) {
            return ApiErrorResponse::make(
                $request,
                ApiErrorCode::NotFound,
                'The requested resource was not found.',
                404,
            );
        }

        if ($exception instanceof MethodNotAllowedHttpException) {
            return ApiErrorResponse::make(
                $request,
                ApiErrorCode::MethodNotAllowed,
                'The HTTP method is not allowed for this endpoint.',
                405,
                headers: $exception->getHeaders(),
            );
        }

        if ($exception instanceof HttpExceptionInterface) {
            return self::renderHttpException($exception, $request);
        }

        return ApiErrorResponse::make(
            $request,
            ApiErrorCode::ServerError,
            self::databaseBehindCodeHint($exception) ?? 'An unexpected server error occurred.',
            500,
        );
    }

    /**
     * On a developer's own machine, a query that names a column or table the database does not have
     * almost always means a migration has not been run yet. Say so, with the command, instead of the
     * generic message, so the cause is read at the first error rather than traced from the log. Only
     * in the `local` environment: elsewhere the message stays generic and nothing about the schema
     * leaves the server (the query and the column stay in the log).
     */
    private static function databaseBehindCodeHint(Throwable $exception): ?string
    {
        if (! app()->environment('local') || ! $exception instanceof QueryException) {
            return null;
        }

        $driverError = $exception->getPrevious();
        $sqlState = $driverError instanceof PDOException ? ($driverError->errorInfo[0] ?? null) : null;

        // 42S22: unknown column. 42S02: unknown table.
        if (! in_array($sqlState, ['42S22', '42S02'], true)) {
            return null;
        }

        return 'The database is behind this version of the app. Open a terminal in the backend folder, run "php artisan migrate", then try again. Nothing was saved.';
    }

    private static function renderHttpException(
        HttpExceptionInterface $exception,
        Request $request,
    ): JsonResponse {
        [$code, $message] = match ($exception->getStatusCode()) {
            400 => [ApiErrorCode::BadRequest, 'The request could not be processed.'],
            401 => [ApiErrorCode::Unauthenticated, 'Authentication is required.'],
            403 => [ApiErrorCode::Forbidden, 'You are not authorized to perform this action.'],
            404 => [ApiErrorCode::NotFound, 'The requested resource was not found.'],
            409 => [ApiErrorCode::Conflict, 'The request conflicts with the current resource state.'],
            422 => [ApiErrorCode::ValidationFailed, 'The submitted data is invalid.'],
            429 => [ApiErrorCode::TooManyRequests, 'Too many requests. Please retry later.'],
            default => [ApiErrorCode::ServerError, 'An unexpected server error occurred.'],
        };

        // For 429 (Too Many Requests), always use the fixed non-enumerating text
        // regardless of what the exception's own message says — the LoginEndpointTest
        // documents this contract explicitly: "ApiExceptionRenderer substitutes one
        // fixed, already non-enumerating message for every 429 in the app".
        // For other statuses, pass through a non-empty exception message (e.g. the
        // 409 "Deactivate instead — this account has history." message from
        // DeleteUnusedUserAccount or the 409 from SetUserAccountStatus).
        if ($exception->getMessage() !== '' && $exception->getStatusCode() !== 429) {
            $message = $exception->getMessage();
        }

        $safeStatuses = [400, 401, 403, 404, 409, 422, 429];
        $status = in_array($exception->getStatusCode(), $safeStatuses, true)
            ? $exception->getStatusCode()
            : 500;

        return ApiErrorResponse::make(
            $request,
            $code,
            $message,
            $status,
            headers: $exception->getHeaders(),
        );
    }
}
