<?php

namespace App\Support\Http;

enum ApiErrorCode: string
{
    case BadRequest = 'BAD_REQUEST';
    case Unauthenticated = 'UNAUTHENTICATED';
    case Forbidden = 'FORBIDDEN';
    case NotFound = 'NOT_FOUND';
    case MethodNotAllowed = 'METHOD_NOT_ALLOWED';
    case Conflict = 'CONFLICT';
    case ValidationFailed = 'VALIDATION_FAILED';
    case TooManyRequests = 'THROTTLED';
    case ServerError = 'INTERNAL_ERROR';
    case ActingContextChanged = 'ACTING_CONTEXT_CHANGED';
}
