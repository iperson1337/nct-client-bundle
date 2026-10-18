<?php

declare(strict_types=1);

namespace Iperson1337\NctClientBundle;

use Iperson1337\NctClientBundle\Gov\NctGovClient;
use Iperson1337\NctClientBundle\Portal\NctPortalClient;

/**
 * Фасад над двумя хостами НКТ: портал nationalcatalog.kz ({@see portal()}) и публичный
 * nct.gov.kz ({@see gov()}). Единственная развилка — карточка по GTIN: источник задаётся
 * конфигурацией `lookup_source`.
 *
 * Не final: потребители подменяют клиент заглушкой в своих тестах.
 */
class NctClient
{
    private readonly GtinLookupSource $lookupSource;

    public function __construct(
        private readonly NctPortalClient $portal,
        private readonly NctGovClient $gov,
        string $lookupSource = GtinLookupSource::NctGov->value,
    ) {
        $this->lookupSource = GtinLookupSource::from($lookupSource);
    }

    public function portal(): NctPortalClient
    {
        return $this->portal;
    }

    public function gov(): NctGovClient
    {
        return $this->gov;
    }

    /**
     * Карточка по GTIN из источника `lookup_source`. Для nct.gov.kz ответ приведён к
     * {gtin, ntin_code}; для портала — карточка как есть (NTIN в `ntin` либо `ntin_code`).
     *
     * @return array<string, mixed>
     *
     * @throws Exception\NctProductNotFoundException карточки нет (только nct.gov.kz; портал отвечает своей ошибкой)
     */
    public function fetchProductByGtin(string $gtin, ?float $timeoutOverride = null, ?int $maxAttempts = null): array
    {
        return match ($this->lookupSource) {
            GtinLookupSource::Portal => $this->portal->fetchProductByGtin($gtin, $timeoutOverride, $maxAttempts),
            GtinLookupSource::NctGov => $this->gov->lookupNtin($gtin, $timeoutOverride, $maxAttempts),
        };
    }
}
