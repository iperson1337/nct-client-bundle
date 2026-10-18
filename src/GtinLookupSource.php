<?php

declare(strict_types=1);

namespace Iperson1337\NctClientBundle;

/**
 * Откуда брать карточку по GTIN. Заявки и остальные операции всегда идут на портал, выбор касается
 * только {@see NctClient::fetchProductByGtin()}.
 */
enum GtinLookupSource: string
{
    /** nct.gov.kz, без авторизации; ответ приводится к {gtin, ntin_code}. */
    case NctGov = 'nctgov';

    /** nationalcatalog.kz через X-API-KEY; ответ — карточка портала как есть. */
    case Portal = 'nationalcatalog';
}
