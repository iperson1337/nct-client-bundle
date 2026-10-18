<?php

declare(strict_types=1);

namespace Iperson1337\NctClientBundle\Http;

/**
 * Куда идёт запрос. У каждого хоста свой лимитер запросов и своя пауза перед повтором после 429:
 * nationalcatalog.kz восстанавливается десятками секунд, nct.gov.kz — за секунду.
 */
enum NctTarget: string
{
    /** nationalcatalog.kz — API портала, авторизация X-API-KEY. */
    case Portal = 'portal';

    /** nct.gov.kz — публичные справочные выборки без авторизации. */
    case Gov = 'gov';
}
