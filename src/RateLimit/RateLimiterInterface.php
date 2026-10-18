<?php

declare(strict_types=1);

namespace Iperson1337\NctClientBundle\RateLimit;

/**
 * Упреждающее ограничение частоты запросов к одному хосту НКТ: вызывается перед каждым запросом
 * и блокирует процесс, пока окно не освободится. Реактивная часть (повтор после 429) — в
 * {@see \Iperson1337\NctClientBundle\Http\NctHttpClient}.
 */
interface RateLimiterInterface
{
    public function waitIfNeeded(): void;
}
