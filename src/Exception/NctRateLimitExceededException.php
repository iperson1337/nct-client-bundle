<?php

declare(strict_types=1);

namespace Iperson1337\NctClientBundle\Exception;

/**
 * Портал ответил 429 (или 422 с кодом TOO_MANY_REQUESTS), и попытки повтора исчерпаны —
 * либо их и не было (`maxAttempts: 1`).
 */
final class NctRateLimitExceededException extends \RuntimeException implements NctExceptionInterface
{
}
