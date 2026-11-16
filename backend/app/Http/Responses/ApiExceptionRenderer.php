<?php

namespace App\Http\Responses;

use App\Http\Middleware\AssignRequestId;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * One error envelope for every API failure (API conventions, A04):
 * { "error": { "code": "validation_failed", "message": "...", "details": {...} }, "request_id": "..." }
 */
class ApiExceptionRenderer
{
    private const CODES = [
        400 => 'bad_request',
        401 => 'unauthenticated',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        409 => 'conflict',
        419 => 'session_expired',
        422 => 'validation_failed',
        429 => 'too_many_requests',
        503 => 'service_unavailable',
    ];

    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if ($e instanceof ApiException) {
            return self::respond($e->status, $e->errorCode, $e->getMessage(), $e->details, $request);
        }

        if ($e instanceof ValidationException) {
            return self::respond(422, 'validation_failed', 'The given data was invalid.', $e->errors(), $request);
        }

        if ($e instanceof AuthenticationException) {
            return self::respond(401, 'unauthenticated', 'Authentication is required.', null, $request);
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $message = $e->getMessage() !== '' ? $e->getMessage() : (JsonResponse::$statusTexts[$status] ?? 'Error');
            if ($status === 404) {
                $message = 'The requested resource was not found.';
            }
            $response = self::respond($status, self::CODES[$status] ?? 'http_error', $message, null, $request);
            $response->headers->add($e->getHeaders());

            return $response;
        }

        report($e);

        return self::respond(
            500,
            'server_error',
            config('app.debug') ? $e->getMessage() : 'Something went wrong. Please try again.',
            null,
            $request,
        );
    }

    public static function respond(int $status, string $code, string $message, mixed $details, Request $request): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== null) {
            $error['details'] = $details;
        }

        return new JsonResponse([
            'error' => $error,
            'request_id' => AssignRequestId::current($request),
        ], $status);
    }
}
