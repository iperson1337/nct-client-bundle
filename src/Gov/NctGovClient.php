<?php

declare(strict_types=1);

namespace Iperson1337\NctClientBundle\Gov;

use Iperson1337\NctClientBundle\Exception\NctProductNotFoundException;
use Iperson1337\NctClientBundle\Http\NctHttpClient;
use Iperson1337\NctClientBundle\Http\NctRequest;
use Iperson1337\NctClientBundle\Http\NctTarget;

/**
 * Публичное API nct.gov.kz без авторизации: поиск карточек по GTIN и карточка по id.
 *
 * nct.gov.kz допускает всплеск в ~3 запроса, дальше 429 на скользящем окне около секунды, поэтому
 * у него свой лимитер и короткая пауза перед повтором (конфигурация `gov`).
 */
class NctGovClient
{
    public function __construct(
        private readonly NctHttpClient $http,
        private readonly string $host,
    ) {
    }

    /**
     * GET /api/integration/ofd/search_ofd/?tin={gtin} — все карточки с таким GTIN, включая
     * деактивированные дубликаты (`ntin_isdeactivated`, `ntin_duplicateofproduct`).
     *
     * Неизвестный GTIN портал отдаёт как 404 {"Message":"product (tin=…) not found"} — это
     * штатное «карточки нет», возвращается пустой список.
     *
     * @return list<array<string, mixed>>
     */
    public function searchByGtin(string $gtin, ?float $timeoutOverride = null, ?int $maxAttempts = null): array
    {
        $items = $this->http->send(
            NctRequest::get(rtrim($this->host, '/') . '/api/integration/ofd/search_ofd/?tin=' . urlencode($gtin), "fetchProductByGtinFromNctGov({$gtin})")
                ->with(target: NctTarget::Gov, headers: ['Accept' => 'application/json'], timeoutOverride: $timeoutOverride, maxAttempts: $maxAttempts, notFoundAsEmpty: true),
        );

        return array_values(array_filter($items, 'is_array'));
    }

    /**
     * Действующая карточка по GTIN: первая с `ntin_isdeactivated === false`.
     *
     * @return array<string, mixed>|null null — карточки есть, но все деактивированы (валидного NTIN нет)
     *
     * @throws NctProductNotFoundException карточек с таким GTIN нет вообще
     */
    public function findActiveCard(string $gtin, ?float $timeoutOverride = null, ?int $maxAttempts = null): ?array
    {
        $items = $this->searchByGtin($gtin, $timeoutOverride, $maxAttempts);
        if ($items === []) {
            throw NctProductNotFoundException::forGtin($gtin, 'nct.gov.kz');
        }

        foreach ($items as $item) {
            if (($item['ntin_isdeactivated'] ?? null) === false) {
                return $item;
            }
        }

        return null;
    }

    /**
     * NTIN по GTIN в форме, совместимой с ответом портала: всегда ключи `gtin` и `ntin_code`
     * (null — валидного NTIN нет).
     *
     * @return array{gtin: string, ntin_code: string|null}
     *
     * @throws NctProductNotFoundException
     */
    public function lookupNtin(string $gtin, ?float $timeoutOverride = null, ?int $maxAttempts = null): array
    {
        $card = $this->findActiveCard($gtin, $timeoutOverride, $maxAttempts);
        if ($card === null) {
            return ['gtin' => $gtin, 'ntin_code' => null];
        }

        return [
            'gtin' => \is_string($card['gtin'] ?? null) ? $card['gtin'] : $gtin,
            'ntin_code' => \is_string($card['ntin_code'] ?? null) ? $card['ntin_code'] : null,
        ];
    }

    /**
     * GET /api/core/products/{id} — карточка по внутреннему id nct.gov.kz (поле `id` в поиске);
     * содержит категорию ОКТРУ (`category.full_code`, `category.parent`, `category.level`).
     *
     * @return array<string, mixed> [] — карточки нет
     */
    public function fetchProduct(int $productId, ?float $timeoutOverride = null, ?int $maxAttempts = null): array
    {
        return $this->http->send(
            NctRequest::get(rtrim($this->host, '/') . "/api/core/products/{$productId}", "fetchNctGovProduct({$productId})")
                ->with(target: NctTarget::Gov, headers: ['Accept' => 'application/json'], timeoutOverride: $timeoutOverride, maxAttempts: $maxAttempts, notFoundAsEmpty: true),
        );
    }
}
