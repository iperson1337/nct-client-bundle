<?php

declare(strict_types=1);

namespace Iperson1337\NctClientBundle\Portal;

use Iperson1337\NctClientBundle\Http\NctHttpClient;
use Iperson1337\NctClientBundle\Http\NctRequest;
use Iperson1337\NctClientBundle\Request\RequestStatus;

/**
 * API портала nationalcatalog.kz (`/gwp/portal/api`, авторизация X-API-KEY) — все операции из
 * официальной спецификации: заявки на товары и их жизненный цикл, файлы заявки, дубли, карточки
 * по NTIN/GTIN, атрибуты базовой и расширенной карточки, справочники.
 *
 * Ответы отдаются как разобранный JSON без интерпретации — форму ответа знает вызывающий код.
 * Операции, у которых портал отвечает пустым телом (публикация, модерация, отзыв, удаление),
 * возвращают пустой массив.
 */
class NctPortalClient
{
    private const string API_V1 = '/gwp/portal/api/v1';
    private const string API_V2 = '/gwp/portal/api/v2';

    public function __construct(
        private readonly NctHttpClient $http,
        private readonly string $host,
    ) {
    }

    // --- Заявки на товары -------------------------------------------------------------------

    /**
     * POST /products/requests — черновик заявки. С полем `ntin` в теле создаётся заявка на
     * редактирование существующей карточки.
     *
     * @param array<string, mixed> $payload oktru, autoPublication, attributes[]; для расширенной
     *                                      карточки — isGov, *ExtendedAttributes, imageFiles, documents
     *
     * @return array<string, mixed> {"id": номер заявки}
     */
    public function submitProductRequest(array $payload): array
    {
        return $this->http->send(NctRequest::post($this->v1('/products/requests'), 'submitProductRequest', self::json($payload)));
    }

    /**
     * POST /products/requests/{ntin}/edit-product — черновик заявки на редактирование опубликованной
     * карточки. Ответ содержит `productRequestId` и тело заявки в формате `PUT …/requests/{id}`:
     * правьте нужные поля и отправляйте обратно; `oktru` может прийти null.
     *
     * @return array<string, mixed>
     */
    public function createEditRequest(string $ntin): array
    {
        return $this->http->send(NctRequest::post($this->v1('/products/requests/' . urlencode($ntin) . '/edit-product'), "createEditRequest({$ntin})"));
    }

    /**
     * PUT /products/requests/{id} — обновить заявку (статусы new, underRevision, underRevisionGz,
     * cancelled). Передаётся полный список атрибутов.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function updateProductRequest(int $requestId, array $payload): array
    {
        return $this->http->send(NctRequest::put($this->v1("/products/requests/{$requestId}"), "updateProductRequest({$requestId})", self::json($payload)));
    }

    /**
     * DELETE /products/requests/{id} — удалить заявку (только статус new).
     *
     * @return array<string, mixed>
     */
    public function deleteProductRequest(int $requestId): array
    {
        return $this->http->send(NctRequest::delete($this->v1("/products/requests/{$requestId}"), "deleteProductRequest({$requestId})"));
    }

    /**
     * PUT /products/requests/{id}/moderation — отправить на модерацию (new, underRevision,
     * underRevisionGz). Перед этим портал проверяет ФЛК: фото front/back и ≥5 характеристик для
     * расширенной карточки, БИН и PDF при `is_domestic`, формат XTIN.
     *
     * @return array<string, mixed>
     */
    public function submitToModeration(int $requestId): array
    {
        return $this->http->send(NctRequest::put($this->v1("/products/requests/{$requestId}/moderation"), "submitProductRequestToModeration({$requestId})", '{}'));
    }

    /**
     * PUT /products/requests/{id}/cancel — отозвать заявку с модерации (onModeration → cancelled).
     *
     * @return array<string, mixed>
     */
    public function cancelRequest(int $requestId): array
    {
        return $this->http->send(NctRequest::put($this->v1("/products/requests/{$requestId}/cancel"), "cancelRequest({$requestId})"));
    }

    /**
     * PUT /products/requests/{id}/publish — опубликовать карточку (только readyToPublish).
     *
     * @return array<string, mixed>
     */
    public function publishRequest(int $requestId): array
    {
        return $this->http->send(NctRequest::put($this->v1("/products/requests/{$requestId}/publish"), "publishRequest({$requestId})"));
    }

    /**
     * GET /products/requests/{id}/status — статус базовой части, `ntinCode` и `productUrl` при
     * completed, замечания модератора (`comment`, `revisionDetails`), `nextAction`, `gzStatus`.
     *
     * @return array<string, mixed>
     */
    public function fetchRequestStatus(int $requestId): array
    {
        return $this->http->send(NctRequest::get($this->v1("/products/requests/{$requestId}/status"), "getRequestStatus({$requestId})"));
    }

    /**
     * GET /products/requests/{id}/details — иерархия категории, статус, все атрибуты со значениями.
     *
     * @param float|null $timeoutOverride короткий таймаут для синхронной проверки из интерфейса
     * @param int|null   $maxAttempts     1 — без повтора после 429
     *
     * @return array<string, mixed>
     */
    public function fetchRequestDetails(int $requestId, ?float $timeoutOverride = null, ?int $maxAttempts = null): array
    {
        return $this->http->send(
            NctRequest::get($this->v1("/products/requests/{$requestId}/details"), "fetchProductRequestDetails({$requestId})")
                ->with(timeoutOverride: $timeoutOverride, maxAttempts: $maxAttempts),
        );
    }

    /**
     * GET /products/requests?page=&size=[&status=] — заявки заявителя постранично
     * (`content`, `totalElements`, `totalPages`).
     *
     * @return array<string, mixed>
     */
    public function fetchProductRequests(int $page = 1, int $size = 10, RequestStatus|string|null $status = null): array
    {
        $query = ['page' => $page, 'size' => $size];
        if ($status !== null) {
            $query['status'] = $status instanceof RequestStatus ? $status->value : $status;
        }

        return $this->http->send(NctRequest::get($this->v1('/products/requests?' . http_build_query($query)), "fetchProductRequests(page={$page}, size={$size})"));
    }

    // --- Дубли ----------------------------------------------------------------------------------

    /**
     * GET /products/requests/{id}/duplicates — потенциальные дубли заявки на доработке по причине
     * DUPLICATE_REVIEW: `orderNumber`, `name`, `brand`, `gtin`, `ntin`, `similarity`.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchDuplicates(int $requestId): array
    {
        $items = $this->http->send(NctRequest::get($this->v1("/products/requests/{$requestId}/duplicates"), "fetchDuplicates({$requestId})"));

        return array_values(array_filter($items, 'is_array'));
    }

    /**
     * POST /products/requests/{id}/duplicate-decision — продолжить создание новой карточки либо
     * выбрать существующую (тогда обязателен её NTIN).
     *
     * @return array<string, mixed>
     */
    public function decideDuplicates(int $requestId, DuplicateDecision $decision, ?string $ntin = null): array
    {
        if ($decision === DuplicateDecision::UseExisting && ($ntin === null || $ntin === '')) {
            throw new \InvalidArgumentException('USE_EXISTING requires the NTIN of the chosen duplicate');
        }

        $body = ['decision' => $decision->value];
        if ($ntin !== null) {
            $body['ntin'] = $ntin;
        }

        return $this->http->send(NctRequest::post($this->v1("/products/requests/{$requestId}/duplicate-decision"), "decideDuplicates({$requestId})", self::json($body)));
    }

    // --- Файлы заявки -----------------------------------------------------------------------------

    /**
     * POST /storage/{requestId}/upload?imageType= — фотография товара (до 5 МБ; статусы new,
     * underRevision, cancelled). Ответ — `UploadedFileDto`: `filename` затем передаётся в `imageFiles`.
     *
     * @param resource|string $file     поток или путь к файлу
     * @param string|null     $fileName имя файла для портала; по умолчанию — имя из пути
     *
     * @return array<string, mixed>
     */
    public function uploadPhoto(int $requestId, ImageType $imageType, mixed $file, ?string $fileName = null): array
    {
        return $this->http->send(
            NctRequest::post($this->v1("/storage/{$requestId}/upload?imageType={$imageType->value}"), "uploadPhoto({$requestId}, {$imageType->value})")
                ->with(multipart: [self::filePart($file, $fileName)]),
        );
    }

    /**
     * POST /storage/{requestId}/upload/document — сопроводительный PDF (до 5 МБ).
     *
     * @param resource|string $file поток или путь к файлу
     *
     * @return array<string, mixed>
     */
    public function uploadDocument(int $requestId, mixed $file, ?string $fileName = null): array
    {
        return $this->http->send(
            NctRequest::post($this->v1("/storage/{$requestId}/upload/document"), "uploadDocument({$requestId})")
                ->with(multipart: [self::filePart($file, $fileName)]),
        );
    }

    /**
     * DELETE /storage/{requestId}/{fileName} — убрать файл из заявки.
     *
     * @return array<string, mixed>
     */
    public function deleteFile(int $requestId, string $fileName): array
    {
        return $this->http->send(NctRequest::delete($this->v1("/storage/{$requestId}/" . rawurlencode($fileName)), "deleteFile({$requestId}, {$fileName})"));
    }

    // --- Карточки товаров -------------------------------------------------------------------

    /**
     * GET /v1/products/{tin} — одна карточка по NTIN (13 цифр) или GTIN (13–14 цифр).
     *
     * @return array<string, mixed>
     */
    public function fetchProductByGtin(string $gtin, ?float $timeoutOverride = null, ?int $maxAttempts = null): array
    {
        return $this->http->send(
            NctRequest::get($this->v1('/products/' . urlencode($gtin)), "fetchProductByGtin({$gtin})")
                ->with(timeoutOverride: $timeoutOverride, maxAttempts: $maxAttempts),
        );
    }

    /**
     * GET /v2/products/{tin} — все карточки с этим кодом: GTIN не уникален и может принадлежать
     * нескольким товарам, по NTIN карточка всегда одна.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchProductsByTin(string $tin, ?float $timeoutOverride = null, ?int $maxAttempts = null): array
    {
        $result = $this->http->send(
            NctRequest::get($this->v2('/products/' . urlencode($tin)), "fetchProductsByTin({$tin})")
                ->with(timeoutOverride: $timeoutOverride, maxAttempts: $maxAttempts),
        );

        // Спецификация описывает объект карточки, портал отдаёт список — принимаем оба варианта.
        return isset($result['id']) ? [$result] : array_values(array_filter($result, 'is_array'));
    }

    // --- Атрибуты и справочники -----------------------------------------------------------------

    /**
     * GET /products/requests/attributes — базовые атрибуты (код, тип данных, обязательность,
     * ограничения, справочник).
     *
     * @return array<array-key, mixed> ответ портала как есть (список элементов)
     */
    public function fetchAttributes(): array
    {
        return $this->http->send(NctRequest::get($this->v1('/products/requests/attributes'), 'fetchPortalAttributes'));
    }

    /**
     * GET /products/requests/categories/{oktru}/gov-attributes — характеристики расширенной
     * карточки для категории: `mainAttributes` (единый набор ЦЭФ → `mainExtendedAttributes`) и
     * `additionalAttributes` (категорийные → `additionalExtendedAttributes`).
     *
     * @return array<string, mixed>
     */
    public function fetchGovAttributes(string $oktru): array
    {
        return $this->http->send(NctRequest::get($this->v1('/products/requests/categories/' . urlencode($oktru) . '/gov-attributes'), "fetchGovAttributes({$oktru})"));
    }

    /**
     * GET /dictionaries — все доступные справочники.
     *
     * @return array<array-key, mixed> ответ портала как есть (список справочников)
     */
    public function fetchDictionaries(): array
    {
        return $this->http->send(NctRequest::get($this->v1('/dictionaries'), 'fetchDictionaries'));
    }

    /**
     * GET /dictionaries/{code}/roots — корневые элементы (COUNTRY, MEASURE_UNIT, TNVED, OKTRU …).
     *
     * @return array<array-key, mixed> ответ портала как есть (список элементов)
     */
    public function fetchDictionaryRoots(string $dictionaryCode): array
    {
        return $this->http->send(NctRequest::get($this->v1('/dictionaries/' . urlencode($dictionaryCode) . '/roots'), "fetchDictionaryRoots({$dictionaryCode})"));
    }

    /**
     * GET /dictionaries/{code}/children/{parentId}.
     *
     * @return array<array-key, mixed> ответ портала как есть (список элементов)
     */
    public function fetchDictionaryChildren(string $dictionaryCode, int $parentId): array
    {
        return $this->http->send(NctRequest::get(
            $this->v1('/dictionaries/' . urlencode($dictionaryCode) . "/children/{$parentId}"),
            "fetchDictionaryChildren({$dictionaryCode}, {$parentId})",
        ));
    }

    /**
     * GET /dictionaries/{code}/items?page=&size= — элементы постранично (страницы с 1, размер
     * 1–100), для древовидных и плоских справочников: `content`, `totalElements`, `totalPages`.
     *
     * @return array<string, mixed>
     */
    public function fetchDictionaryItems(string $dictionaryCode, int $page = 1, int $size = 100): array
    {
        if ($page < 1 || $size < 1 || $size > 100) {
            throw new \InvalidArgumentException('page must be >= 1 and size within 1..100');
        }

        return $this->http->send(NctRequest::get(
            $this->v1('/dictionaries/' . urlencode($dictionaryCode) . "/items?page={$page}&size={$size}"),
            "fetchDictionaryItems({$dictionaryCode}, page={$page}, size={$size})",
        ));
    }

    private function v1(string $path): string
    {
        return rtrim($this->host, '/') . self::API_V1 . $path;
    }

    private function v2(string $path): string
    {
        return rtrim($this->host, '/') . self::API_V2 . $path;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function json(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param resource|string $file
     *
     * @return array<string, mixed>
     */
    private static function filePart(mixed $file, ?string $fileName): array
    {
        if (\is_string($file)) {
            $stream = @fopen($file, 'rb');
            if ($stream === false) {
                throw new \InvalidArgumentException("Cannot open file for upload: {$file}");
            }
            $fileName ??= basename($file);
            $file = $stream;
        } elseif (!\is_resource($file)) {
            throw new \InvalidArgumentException('File must be a path or a stream resource');
        }

        $part = ['name' => 'file', 'contents' => $file];
        if ($fileName !== null) {
            $part['filename'] = $fileName;
        }

        return $part;
    }
}
