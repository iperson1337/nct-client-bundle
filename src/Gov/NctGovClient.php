<?php

declare(strict_types=1);

namespace Iperson1337\NctClientBundle\Gov;

use Iperson1337\NctClientBundle\Exception\NctProductNotFoundException;
use Iperson1337\NctClientBundle\Http\NctHttpClient;
use Iperson1337\NctClientBundle\Http\NctRequest;
use Iperson1337\NctClientBundle\Http\NctTarget;

/**
 * API nct.gov.kz. Поиск карточек (`search_ofd`) открыт без авторизации; выгрузка изменённых
 * товаров по времени (`ofd_product`, `ofd_product_count`) требует JWT-токен учётной записи
 * nct.gov.kz ({@see login()}) и ограничена 120 запросами в минуту.
 *
 * nct.gov.kz допускает всплеск в ~3 запроса, дальше 429 на скользящем окне около секунды, поэтому
 * у него свой лимитер и короткая пауза перед повтором (конфигурация `gov`).
 *
 * Поля карточки: `id`, `name_ru`, `name_kk`, `gtin`, `ntin_code`, `is_social` (СЗПТ), `modified`
 * (timestamp), `status`, `is_markedeac`, `measure`, `product_type` (1 товар, 2 работа, 3 услуга),
 * `ntin_isdeactivated`, `ntin_deactivationreason`, `ntin_duplicateofproduct`.
 */
class NctGovClient
{
    /** Предел `search_ofd` по числу кодов в одном запросе. */
    public const int MAX_TINS_PER_SEARCH = 100;

    public function __construct(
        private readonly NctHttpClient $http,
        private readonly string $host,
    ) {
    }

    // --- Поиск карточек (без авторизации) -----------------------------------------------------

    /**
     * GET /api/integration/ofd/search_ofd/?tin={gtin} — все карточки с таким GTIN или NTIN, включая
     * деактивированные дубликаты. Неизвестный код портал отдаёт как 404 {"Message":"product … not
     * found"} — штатное «карточки нет», возвращается пустой список.
     *
     * @return list<array<string, mixed>>
     */
    public function searchByGtin(string $gtin, ?float $timeoutOverride = null, ?int $maxAttempts = null): array
    {
        return $this->searchByTins([$gtin], $timeoutOverride, $maxAttempts);
    }

    /**
     * То же для пачки кодов NTIN/GTIN (до {@see MAX_TINS_PER_SEARCH}) одним запросом.
     *
     * @param list<string> $tins
     *
     * @return list<array<string, mixed>>
     */
    public function searchByTins(array $tins, ?float $timeoutOverride = null, ?int $maxAttempts = null): array
    {
        $tins = array_values(array_filter(array_map(static fn (string $tin): string => trim($tin), $tins), static fn (string $tin): bool => $tin !== ''));
        if ($tins === [] || \count($tins) > self::MAX_TINS_PER_SEARCH) {
            throw new \InvalidArgumentException(sprintf('search_ofd accepts 1..%d codes per request', self::MAX_TINS_PER_SEARCH));
        }

        $items = $this->http->send(
            NctRequest::get($this->url('/api/integration/ofd/search_ofd/?tin=' . urlencode(implode(',', $tins))), 'fetchProductByGtinFromNctGov(' . implode(',', $tins) . ')')
                ->with(target: NctTarget::Gov, headers: self::ACCEPT_JSON, timeoutOverride: $timeoutOverride, maxAttempts: $maxAttempts, notFoundAsEmpty: true),
        );

        return array_values(array_filter($items, 'is_array'));
    }

    /**
     * GET /api/integration/ofd/search_ofd/?q={название} — поиск по наименованию, постранично:
     * `count`, `next`, `previous`, `last_modified`, `result[]`.
     *
     * @return array<string, mixed>
     */
    public function searchByName(string $query, int $limit = 1000, int $offset = 0, ?float $timeoutOverride = null, ?int $maxAttempts = null): array
    {
        $params = ['q' => $query, 'limit' => $limit, 'offset' => $offset];

        return $this->http->send(
            NctRequest::get($this->url('/api/integration/ofd/search_ofd/?' . http_build_query($params)), "searchByName({$query})")
                ->with(target: NctTarget::Gov, headers: self::ACCEPT_JSON, timeoutOverride: $timeoutOverride, maxAttempts: $maxAttempts, notFoundAsEmpty: true),
        );
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
            NctRequest::get($this->url("/api/core/products/{$productId}"), "fetchNctGovProduct({$productId})")
                ->with(target: NctTarget::Gov, headers: self::ACCEPT_JSON, timeoutOverride: $timeoutOverride, maxAttempts: $maxAttempts, notFoundAsEmpty: true),
        );
    }

    // --- Выгрузка изменений (по JWT учётной записи nct.gov.kz) --------------------------------

    /**
     * POST /api/auth/login/ — токен учётной записи nct.gov.kz для методов выгрузки. Ответ
     * возвращается как есть: токен из него передаётся в {@see exportProducts()} и
     * {@see countChangedProducts()}. После нескольких неверных попыток портал блокирует учётную
     * запись на 10 минут (403 с `locked_seconds`).
     *
     * @return array<string, mixed>
     */
    public function login(string $username, string $password): array
    {
        return $this->http->send(
            NctRequest::post($this->url('/api/auth/login/'), 'login', (string) json_encode(['username' => $username, 'password' => $password], JSON_UNESCAPED_UNICODE))
                ->with(target: NctTarget::Gov, headers: self::ACCEPT_JSON + ['Content-Type' => 'application/json'], maxAttempts: 1),
        );
    }

    /**
     * GET /api/integration/ofd/ofd_product/?from={timestamp}&limit=&offset= — товары, изменённые
     * после `from` (unix-время; при следующей выгрузке — `modified` последнего полученного).
     * Постранично: `count`, `next`, `previous`, `last_modified`, `result[]`. Лимит портала —
     * 120 запросов в минуту.
     *
     * @param string $jwt токен из {@see login()}
     *
     * @return array<string, mixed>
     */
    public function exportProducts(string $jwt, int $from, int $limit = 1000, int $offset = 0): array
    {
        $params = ['from' => $from, 'limit' => $limit, 'offset' => $offset];

        return $this->http->send(
            NctRequest::get($this->url('/api/integration/ofd/ofd_product/?' . http_build_query($params)), "exportProducts(from={$from}, offset={$offset})")
                ->with(target: NctTarget::Gov, headers: self::authHeaders($jwt)),
        );
    }

    /**
     * GET /api/integration/ofd/ofd_product_count/?from={timestamp} — сколько товаров изменилось
     * после `from`: `count`.
     *
     * @return array<string, mixed>
     */
    public function countChangedProducts(string $jwt, int $from): array
    {
        return $this->http->send(
            NctRequest::get($this->url("/api/integration/ofd/ofd_product_count/?from={$from}"), "countChangedProducts(from={$from})")
                ->with(target: NctTarget::Gov, headers: self::authHeaders($jwt)),
        );
    }

    private const array ACCEPT_JSON = ['Accept' => 'application/json'];

    /**
     * @return array<string, string>
     */
    private static function authHeaders(string $jwt): array
    {
        // Схема из WWW-Authenticate портала: JWT realm="api".
        return self::ACCEPT_JSON + ['Authorization' => 'JWT ' . $jwt];
    }

    private function url(string $path): string
    {
        return rtrim($this->host, '/') . $path;
    }
}
