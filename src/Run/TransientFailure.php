<?php

declare(strict_types=1);

namespace App\Run;

use App\Ai\InvalidApiKeyException;
use App\Ai\MissingApiKeyException;
use App\Ai\UnsupportedModelException;
use Symfony\AI\Platform\Exception\JobTimeoutException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\RetryableExceptionInterface;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Classifies provider failures and is what a handler throws to get a retry. It carries only the error code: provider
 * messages and previous exceptions may hold answer text or key fragments and would reach the worker log and the failed queue.
 */
final class TransientFailure extends \RuntimeException
{
    /** Timeouts, rate limits, server errors and network errors; everything else will fail again. */
    public static function isTransient(\Throwable $error): bool
    {
        $status = self::httpStatus($error);

        return $error instanceof self
            || $error instanceof RetryableExceptionInterface
            || $error instanceof TransportExceptionInterface
            || $error instanceof ProcessTimedOutException
            || $error instanceof JobTimeoutException
            || 429 === $status
            || (null !== $status && 500 <= $status);
    }

    /** A fixed code for RunResult::error, never the exception message. */
    public static function errorCode(\Throwable $error): string
    {
        $code = match (true) {
            $error instanceof self => $error->getMessage(),
            $error instanceof MissingApiKeyException => 'no_api_key',
            $error instanceof InvalidApiKeyException => 'invalid_api_key',
            $error instanceof UnsupportedModelException => 'unsupported_model',
            default => trim(substr(strrchr('\\'.$error::class, '\\') ?: '', 1).' '.self::httpStatus($error)),
        };

        return substr($code, 0, 255);
    }

    private static function httpStatus(\Throwable $error): ?int
    {
        return match (true) {
            $error instanceof RateLimitExceededException => 429,
            $error instanceof ServerException => $error->getStatusCode(),
            $error instanceof HttpExceptionInterface => $error->getResponse()->getStatusCode(),
            default => null,
        };
    }
}
