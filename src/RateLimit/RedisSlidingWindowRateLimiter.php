<?php

declare(strict_types=1);

namespace Iperson1337\NctClientBundle\RateLimit;

use Predis\Client;
use Predis\Connection\ConnectionException as PredisConnectionException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\RedisAdapter;

/**
 * Скользящее окно в Redis, общее для всех процессов (кроны, воркеры, веб): не больше `$limit`
 * запросов за `$windowSeconds`. Проверка и запись атомарны (Lua), при недоступности Redis лимит
 * пропускается — основной процесс не должен останавливаться из-за лимитера.
 *
 * Ключ настраивается: у каждого хоста НКТ свой счётчик.
 */
final class RedisSlidingWindowRateLimiter implements RateLimiterInterface
{
    private const int TTL_MARGIN_SECONDS = 10;

    private readonly \Redis|Client $redis;

    public function __construct(
        string $redisDsn,
        private readonly LoggerInterface $logger,
        private readonly int $limit,
        private readonly int $windowSeconds,
        private readonly string $redisKey,
    ) {
        // RedisAdapter разбирает DSN с паролем и выбирает phpredis либо Predis — что установлено.
        $this->redis = RedisAdapter::createConnection($redisDsn);
    }

    public function waitIfNeeded(): void
    {
        $now = time();
        $result = $this->check($now);

        if ($result === null) {
            $this->logger->warning('NCT rate limiter: Redis error, skipping rate limit check', [
                'class' => self::class,
                'redis_key' => $this->redisKey,
            ]);

            return;
        }

        [$shouldWait, $waitTime, $currentCount] = $result;

        if ($shouldWait && $waitTime > 0 && $waitTime <= $this->windowSeconds) {
            $this->logger->info("NCT rate limit reached ({$currentCount}/{$this->limit}), waiting {$waitTime} seconds", [
                'class' => self::class,
                'redis_key' => $this->redisKey,
                'request_count' => $currentCount,
                'rate_limit' => $this->limit,
                'wait_time' => $waitTime,
            ]);

            usleep((int) ($waitTime * 1_000_000));
        }
    }

    /**
     * @return array{0: int, 1: float, 2: int}|null [should_wait, wait_time, current_count]; null — сбой Redis
     */
    private function check(int $now): ?array
    {
        return $this->safely(function () use ($now): ?array {
            $result = $this->evalLua(
                self::LUA,
                [$this->redisKey],
                [(string) $now, (string) $this->windowSeconds, (string) $this->limit, (string) ($this->windowSeconds + self::TTL_MARGIN_SECONDS)],
            );

            if (!\is_array($result) || \count($result) !== 3) {
                $this->logger->error('NCT rate limiter: Invalid Lua script result', [
                    'class' => self::class,
                    'result' => $result,
                ]);

                return null;
            }

            return [(int) $result[0], (float) $result[1], (int) $result[2]];
        });
    }

    /**
     * Predis: eval($script, $numKeys, ...$keys, ...$args); phpredis: eval($script, [...$keys, ...$args], $numKeys).
     *
     * @param list<string> $keys
     * @param list<string> $args
     */
    private function evalLua(string $script, array $keys, array $args): mixed
    {
        $keysAndArgs = [...$keys, ...$args];

        if ($this->redis instanceof Client) {
            return $this->redis->eval($script, \count($keys), ...$keysAndArgs);
        }

        return $this->redis->eval($script, $keysAndArgs, \count($keys));
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T|null
     */
    private function safely(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (\RedisException | PredisConnectionException $e) {
            $this->logger->error('Redis operation failed in rate limiter', [
                'class' => self::class,
                'error' => $e->getMessage(),
                'exception_class' => $e::class,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Unexpected error in rate limiter', [
                'class' => self::class,
                'error' => $e->getMessage(),
                'exception_class' => $e::class,
            ]);
        }

        return null;
    }

    /**
     * ZCOUNT в окне; при достижении лимита — время до выхода самого старого запроса из окна,
     * иначе ZADD текущего, чистка хвоста, EXPIRE. Возвращает {should_wait, wait_time, current_count}.
     */
    private const string LUA = <<<'LUA'
local key = KEYS[1]
local now = tonumber(ARGV[1])
local window = tonumber(ARGV[2])
local limit = tonumber(ARGV[3])
local ttl = tonumber(ARGV[4])

local windowStart = now - window
local count = redis.call('ZCOUNT', key, windowStart, now)

if count >= limit then
    local oldest = redis.call('ZRANGE', key, 0, 0, 'WITHSCORES')
    local waitTime = 0
    if #oldest >= 2 then
        local oldestTimestamp = tonumber(oldest[2])
        waitTime = window - (now - oldestTimestamp) + 1
        if waitTime < 0 then
            waitTime = 0
        end
        if waitTime > window then
            waitTime = window
        end
    end
    return {1, waitTime, count}
end

redis.call('ZADD', key, now, tostring(now))
redis.call('ZREMRANGEBYSCORE', key, '-inf', windowStart)
redis.call('EXPIRE', key, ttl)

return {0, 0, count + 1}
LUA;
}
