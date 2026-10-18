<?php

declare(strict_types=1);

namespace Iperson1337\NctClientBundle\Http;

/**
 * Один запрос к НКТ в терминах клиента: что и куда отправить и как трактовать ответ.
 *
 * @param string|null                     $body            JSON-тело для POST/PUT
 * @param array<string, string>|null      $headers         заголовки вместо стандартных для цели
 * @param float|null                      $timeoutOverride таймаут только этого вызова (секунды, дробные) —
 *                                                         для синхронных проверок в клике интерфейса
 * @param int|null                        $maxAttempts     число попыток только для этого вызова;
 *                                                         1 — без повтора после 429, сразу исключение
 * @param bool                            $notFoundAsEmpty 404 с телом «… not found» вернуть пустым массивом,
 *                                                         а не ошибкой (поиск карточки, которой может не быть)
 * @param list<array<string, mixed>>|null $multipart       части multipart/form-data (формат Guzzle: name,
 *                                                         contents, filename) — для загрузки файлов; тело тогда не задаётся
 */
final readonly class NctRequest
{
    public function __construct(
        public string $method,
        public string $url,
        public string $operation,
        public ?string $body = null,
        public ?array $headers = null,
        public NctTarget $target = NctTarget::Portal,
        public ?float $timeoutOverride = null,
        public ?int $maxAttempts = null,
        public bool $notFoundAsEmpty = false,
        public ?array $multipart = null,
    ) {
    }

    public static function get(string $url, string $operation): self
    {
        return new self('GET', $url, $operation);
    }

    public static function put(string $url, string $operation, ?string $body = null): self
    {
        return new self('PUT', $url, $operation, $body);
    }

    public static function post(string $url, string $operation, ?string $body = null): self
    {
        return new self('POST', $url, $operation, $body);
    }

    public static function delete(string $url, string $operation): self
    {
        return new self('DELETE', $url, $operation);
    }

    /**
     * Копия с другими параметрами выполнения; null — оставить как есть.
     *
     * @param array<string, string>|null      $headers
     * @param list<array<string, mixed>>|null $multipart
     */
    public function with(
        ?NctTarget $target = null,
        ?array $headers = null,
        ?float $timeoutOverride = null,
        ?int $maxAttempts = null,
        ?bool $notFoundAsEmpty = null,
        ?array $multipart = null,
    ): self {
        return new self(
            $this->method,
            $this->url,
            $this->operation,
            $this->body,
            $headers ?? $this->headers,
            $target ?? $this->target,
            $timeoutOverride ?? $this->timeoutOverride,
            $maxAttempts ?? $this->maxAttempts,
            $notFoundAsEmpty ?? $this->notFoundAsEmpty,
            $multipart ?? $this->multipart,
        );
    }
}
