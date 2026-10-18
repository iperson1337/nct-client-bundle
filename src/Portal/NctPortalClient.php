<?php

declare(strict_types=1);

namespace Iperson1337\NctClientBundle\Portal;

use Iperson1337\NctClientBundle\Http\NctHttpClient;
use Iperson1337\NctClientBundle\Http\NctRequest;

/**
 * API портала nationalcatalog.kz (`/gwp/portal/api/v1`, авторизация X-API-KEY): заявки на
 * создание товара и их жизненный цикл, описания атрибутов, словари, карточка по GTIN.
 *
 * Ответы отдаются как разобранный JSON без интерпретации — форму ответа знает вызывающий код.
 */
class NctPortalClient
{
    private const string API_PREFIX = '/gwp/portal/api/v1';

    public function __construct(
        private readonly NctHttpClient $http,
        private readonly string $host,
    ) {
    }

    /**
     * POST /products/requests — новая заявка на создание товара.
     *
     * @param array<string, mixed> $payload тело заявки (oktru, autoPublication, attributes[])
     *
     * @return array<string, mixed> ответ портала; `id` — номер заявки в НКТ
     */
    public function submitProductRequest(array $payload): array
    {
        return $this->http->send(NctRequest::post($this->url('/products/requests'), 'submitProductRequest', self::json($payload)));
    }

    /**
     * PUT /products/requests/{id}/moderation — отправить заявку на модерацию.
     *
     * @return array<string, mixed>
     */
    public function submitToModeration(int $requestId): array
    {
        return $this->http->send(NctRequest::put($this->url("/products/requests/{$requestId}/moderation"), "submitProductRequestToModeration({$requestId})", '{}'));
    }

    /**
     * PUT /products/requests/{id} — изменить заявку.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function updateProductRequest(int $requestId, array $payload): array
    {
        return $this->http->send(NctRequest::put($this->url("/products/requests/{$requestId}"), "updateProductRequest({$requestId})", self::json($payload)));
    }

    /**
     * PUT /products/requests/{id}/publish — опубликовать товар по заявке.
     *
     * @return array<string, mixed>
     */
    public function publishRequest(int $requestId): array
    {
        return $this->http->send(NctRequest::put($this->url("/products/requests/{$requestId}/publish"), "publishRequest({$requestId})"));
    }

    /**
     * GET /products/requests/{id}/status.
     *
     * @return array<string, mixed> {"code": "new", "value": "Новая"}
     */
    public function fetchRequestStatus(int $requestId): array
    {
        return $this->http->send(NctRequest::get($this->url("/products/requests/{$requestId}/status"), "getRequestStatus({$requestId})"));
    }

    /**
     * GET /products/requests/{id}/details — статус, атрибуты, комментарий модератора.
     *
     * @param float|null $timeoutOverride короткий таймаут для синхронной проверки из интерфейса
     * @param int|null   $maxAttempts     1 — без повтора после 429
     *
     * @return array<string, mixed>
     */
    public function fetchRequestDetails(int $requestId, ?float $timeoutOverride = null, ?int $maxAttempts = null): array
    {
        return $this->http->send(
            NctRequest::get($this->url("/products/requests/{$requestId}/details"), "fetchProductRequestDetails({$requestId})")
                ->with(timeoutOverride: $timeoutOverride, maxAttempts: $maxAttempts),
        );
    }

    /**
     * GET /products/requests?page=&size= — заявки заявителя постранично.
     *
     * @return array<string, mixed>
     */
    public function fetchProductRequests(int $page = 1, int $size = 10): array
    {
        return $this->http->send(NctRequest::get($this->url("/products/requests?page={$page}&size={$size}"), "fetchProductRequests(page={$page}, size={$size})"));
    }

    /**
     * GET /products/{gtin} — карточка по GTIN на портале.
     *
     * @return array<string, mixed>
     */
    public function fetchProductByGtin(string $gtin, ?float $timeoutOverride = null, ?int $maxAttempts = null): array
    {
        return $this->http->send(
            NctRequest::get($this->url('/products/' . urlencode($gtin)), "fetchProductByGtin({$gtin})")
                ->with(timeoutOverride: $timeoutOverride, maxAttempts: $maxAttempts),
        );
    }

    /**
     * GET /products/requests/attributes — описания атрибутов заявки (код, тип данных, обязательность, словари).
     *
     * @return array<array-key, mixed>
     */
    public function fetchAttributes(): array
    {
        return $this->http->send(NctRequest::get($this->url('/products/requests/attributes'), 'fetchPortalAttributes'));
    }

    /**
     * GET /dictionaries/{code}/roots — корневые элементы словаря (COUNTRY, MEASURE_UNIT, TNVED …).
     *
     * @return array<array-key, mixed>
     */
    public function fetchDictionaryRoots(string $dictionaryCode): array
    {
        return $this->http->send(NctRequest::get($this->url('/dictionaries/' . urlencode($dictionaryCode) . '/roots'), "fetchDictionaryRoots({$dictionaryCode})"));
    }

    /**
     * GET /dictionaries/{code}/children/{parentId}.
     *
     * @return array<array-key, mixed>
     */
    public function fetchDictionaryChildren(string $dictionaryCode, int $parentId): array
    {
        return $this->http->send(NctRequest::get(
            $this->url('/dictionaries/' . urlencode($dictionaryCode) . "/children/{$parentId}"),
            "fetchDictionaryChildren({$dictionaryCode}, {$parentId})",
        ));
    }

    private function url(string $path): string
    {
        return rtrim($this->host, '/') . self::API_PREFIX . $path;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function json(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
}
