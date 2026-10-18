<?php

declare(strict_types=1);

namespace Iperson1337\NctClientBundle\RateLimit;

/**
 * Без ограничения — когда Redis не настроен (`redis_dsn: null`) или в тестах.
 */
final class NullRateLimiter implements RateLimiterInterface
{
    public function waitIfNeeded(): void
    {
    }
}
