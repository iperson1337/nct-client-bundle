# NCT Client Bundle

[English](README.md) · **Русский** · [Қазақша](README.kk.md)

[![CI](https://github.com/iperson1337/nct-client-bundle/actions/workflows/ci.yml/badge.svg)](https://github.com/iperson1337/nct-client-bundle/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/iperson1337/nct-client-bundle.svg)](https://packagist.org/packages/iperson1337/nct-client-bundle)
[![PHP Version](https://img.shields.io/packagist/php-v/iperson1337/nct-client-bundle.svg)](https://packagist.org/packages/iperson1337/nct-client-bundle)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Symfony-клиент **Национального каталога товаров Казахстана (НКТ)**: API портала
[nationalcatalog.kz](https://nationalcatalog.kz) (заявки на товары, атрибуты, словари) и публичные
выборки [nct.gov.kz](https://nct.gov.kz). Повторы запросов, лимиты через Redis, типизированные
исключения, разбор ответов.

Бандл знает протокол портала и ничего не знает о вашей модели данных: ответы возвращаются
разобранными массивами, а что с ними делать — решает приложение.

## Возможности

- **API портала** (`nationalcatalog.kz`, `X-API-KEY`): создать заявку на товар, отправить на
  модерацию, изменить, опубликовать, прочитать статус и детали, получить список заявок, описания
  атрибутов и словари, найти карточку по GTIN.
- **Публичное API** (`nct.gov.kz`, без авторизации): поиск карточек по GTIN, выбор действующей
  карточки, карточка по id (с категорией ОКТРУ).
- **Один фасад** — `NctClient::fetchProductByGtin()` берёт карточку из настроенного источника.
- **Лимиты** — упреждающее скользящее окно на каждый хост (Redis, Lua, общее для процессов) плюс
  повторы после `429` / `422 TOO_MANY_REQUESTS`.
- **Настройки на вызов** — короткий таймаут и одна попытка для синхронных проверок в интерфейсе.
- **Типизированные исключения** — все наследуют `\RuntimeException` и реализуют `NctExceptionInterface`.
- **Помощники** — парсер деталей заявки, проверка формата NTIN/XTIN.

## Требования

- PHP 8.3+
- Symfony 7.4 (`config`, `dependency-injection`, `http-kernel`, `cache`)
- Guzzle 7
- По желанию: расширение `redis` или `predis/predis` для лимитера в Redis

## Установка

```bash
composer require iperson1337/nct-client-bundle
```

Без Symfony Flex зарегистрируйте бандл вручную:

```php
// config/bundles.php
return [
    // ...
    Iperson1337\NctClientBundle\NctClientBundle::class => ['all' => true],
];
```

## Конфигурация

`config/packages/iperson1337_nct_client.yaml`:

```yaml
iperson1337_nct_client:
    portal:                                   # nationalcatalog.kz, авторизация X-API-KEY
        host: 'https://nationalcatalog.kz'
        api_key: '%env(NCT_API_KEY)%'
        timeout: 30                           # таймаут ответа, секунды
        connect_timeout: 5
        retry_count: 3                        # повторов после 429, помимо первой попытки
        retry_after_seconds: 30               # пауза перед повтором после 429
        rate_limit:
            requests: 100                     # не больше `requests` запросов за `window_seconds`
            window_seconds: 60
            redis_key: 'nct:rate_limit:requests'
    gov:                                      # nct.gov.kz, публичный
        host: 'https://nct.gov.kz'
        retry_after_seconds: 2                # nct.gov.kz восстанавливается примерно за секунду
        rate_limit:
            requests: 2                       # публичный хост выдерживает всплеск ~3 запроса/с
            window_seconds: 1
            redis_key: 'nct:gov:rate_limit:requests'
    lookup_source: nctgov                     # источник карточки по GTIN: nctgov | nationalcatalog
    redis_dsn: '%env(REDIS_URL)%'             # null выключает лимиты (NullRateLimiter)
```

Все ключи необязательны; выше — значения по умолчанию (кроме `api_key`, который пуст, и
`redis_dsn`, который равен `null`).

| Ключ | Что значит |
|---|---|
| `portal.host`, `portal.api_key` | адрес портала и ключ API заявителя; все операции с заявками идут сюда независимо от `lookup_source` |
| `portal.timeout`, `portal.connect_timeout` | таймауты Guzzle по умолчанию для обоих хостов; на вызов переопределяются аргументом `$timeoutOverride` |
| `portal.retry_count` | сколько раз повторить после `429` / `422 TOO_MANY_REQUESTS`; `$maxAttempts = 1` на вызове отключает повторы |
| `*.retry_after_seconds` | `sleep()` перед повтором, свой у каждого хоста |
| `*.rate_limit` | упреждающий лимит до запроса: скользящее окно в Redis со своим ключом у каждого хоста, поэтому хосты не мешают друг другу |
| `lookup_source` | откуда `NctClient::fetchProductByGtin()` берёт карточку: `nctgov` — публичный поиск, ответ приводится к `{gtin, ntin_code}`; `nationalcatalog` — `GET /products/{gtin}` портала, ответ как есть |
| `redis_dsn` | Redis для лимитеров; при недоступности Redis лимит пропускается с предупреждением, запрос выполняется |

Регистрируемые сервисы (автовайринг по классу): `NctClient`, `NctPortalClient`, `NctGovClient`,
`NctHttpClient`, `RequestDetailsParser`, `NationalCatalogIdentifier`.

## Использование

### Карточка по GTIN

```php
use Iperson1337\NctClientBundle\Exception\NctProductNotFoundException;
use Iperson1337\NctClientBundle\NctClient;

final class NtinLookup
{
    public function __construct(private readonly NctClient $nct) {}

    public function ntinFor(string $gtin): ?string
    {
        try {
            $card = $this->nct->fetchProductByGtin($gtin);

            // nct.gov.kz отдаёт `ntin_code`, портал — `ntin`
            return $card['ntin'] ?? $card['ntin_code'] ?? null;
        } catch (NctProductNotFoundException) {
            return null; // карточки с таким GTIN нет — штатный исход, не сбой
        }
    }
}
```

Короткий таймаут и одна попытка для синхронной проверки в действии интерфейса (не ждать полный
`timeout` и не спать на `429`):

```php
$card = $this->nct->fetchProductByGtin($gtin, timeoutOverride: 3.0, maxAttempts: 1);
```

### Жизненный цикл заявки

```php
$portal = $this->nct->portal();

$response = $portal->submitProductRequest([
    'oktru' => '10.51.11',
    'autoPublication' => false,
    'attributes' => [
        ['code' => 'gtin', 'value' => '4870001302818'],
        ['code' => 'name_ru', 'value' => 'Молоко 2,5% 1 л'],
    ],
]);
$requestId = (int) $response['id'];

$portal->submitToModeration($requestId);              // PUT …/requests/{id}/moderation
$portal->updateProductRequest($requestId, $payload);  // PUT …/requests/{id}
$portal->publishRequest($requestId);                  // PUT …/requests/{id}/publish
```

Портал не принимает атрибут `NTIN` в заявке на *создание*
(`400 VALIDATION_ERROR: The "NTIN" field must not be filled when creating a new product`).

### Статус и детали заявки

```php
use Iperson1337\NctClientBundle\Request\RequestDetailsParser;

$status  = $portal->fetchRequestStatus($requestId);   // ['code' => 'onModeration', 'value' => '…']
$details = $portal->fetchRequestDetails($requestId, timeoutOverride: 3.0, maxAttempts: 1);

$parser  = new RequestDetailsParser();
$code    = $parser->extractStatusCode($details);               // 'completed', 'rejected', …
$ntin    = $parser->extractAttributeValueByCode($details, 'ntin');
$xtin    = $parser->extractAttributeValueByCode($details, 'xtin');
$comment = $parser->extractModeratorComment($details);        // null — поля нет; '' — поле есть, но пустое

$page = $portal->fetchProductRequests(page: 1, size: 50);
```

Коды статусов портала: `new`, `onModeration`, `accepted`, `underRevision`, `readyToPublish`,
`completed`, `rejected`.

### Описания атрибутов и словари

```php
$attributes = $portal->fetchAttributes();                  // code, dataType, isRequired, dictionaryCode, …
$roots      = $portal->fetchDictionaryRoots('MEASURE_UNIT'); // COUNTRY, MEASURE_UNIT, TNVED, …
$children   = $portal->fetchDictionaryChildren('MEASURE_UNIT', (int) $roots[0]['id']);
```

### nct.gov.kz: поиск и карточка

```php
$gov = $this->nct->gov();

$cards = $gov->searchByGtin($gtin);     // все карточки с этим GTIN, включая деактивированные дубликаты; [] — нет
$card  = $gov->findActiveCard($gtin);   // первая с ntin_isdeactivated === false; null — все деактивированы
$ntin  = $gov->lookupNtin($gtin);       // ['gtin' => …, 'ntin_code' => string|null]; исключение, если карточек нет
$full  = $gov->fetchProduct((int) $card['id']);   // GET /api/core/products/{id}; ОКТРУ — в category.full_code
```

### Формат NTIN / XTIN

```php
use Iperson1337\NctClientBundle\Identifier\NationalCatalogIdentifier;

$id = new NationalCatalogIdentifier();
$id->isValidNtin('0200212305195');  // true — «02» + 11 цифр
$id->isValidXtin('0040212305191');  // true — «004» + 10 цифр
$id->normalize('  0200212305195 '); // '0200212305195'; пустое → null
```

Идентификаторы — строки: ведущие нули значимы.

## Исключения

Все исключения наследуют `\RuntimeException` и реализуют `NctExceptionInterface`: существующий
`catch (\RuntimeException)` продолжает работать, а `catch (NctExceptionInterface)` ловит любой сбой НКТ.

| Исключение | Когда |
|---|---|
| `NctProductNotFoundException` | карточки с GTIN нет (nct.gov.kz ответил `404 "product … not found"` или пустым списком). Сообщение стабильно: `Product with GTIN {gtin} not found in NCT (nct.gov.kz)` |
| `NctRateLimitExceededException` | `429` или `422 TOO_MANY_REQUESTS`, попытки исчерпаны (или `maxAttempts: 1`) |
| `NctRequestTimeoutException` | таймаут соединения (`ConnectException`) или ответа (cURL 28) |
| `NctTransportException` | `5xx`, сетевой сбой, исчерпаны попытки |
| `NctClientException` | прочий `4xx`: невалидная заявка, нет прав, сменился путь API. В сообщении — `HTTP {статус} {тело}`: тело целиком (с потолком 2000 символов и пометкой об обрезке), потому что именно оно объясняет, какой атрибут отклонён |
| `NctResponseException` | `2xx`, но тело не разбирается как JSON |

`404` с телом `{"Message": "product … not found"}` считается «не найдено» только у запросов, которые
это объявили (`NctRequest::$notFoundAsEmpty`): `404` после смены пути API не должен выглядеть как
отсутствие товара.

## Лимиты и повторы

Каждый запрос защищён двумя слоями:

1. **Упреждающий лимит** — `RateLimiterInterface::waitIfNeeded()` до запроса, свой лимитер у каждого
   хоста (`NctTarget::Portal` / `NctTarget::Gov`). Реализация в Redis хранит отсортированное множество
   меток времени; атомарный Lua-скрипт считает запросы в окне и при переполнении отдаёт время до выхода
   самого старого. При сбое Redis лимит пропускается с предупреждением — лимитер не должен останавливать
   процесс.
2. **Повтор после 429** — до `retry_count` повторов с паузой `retry_after_seconds` хоста;
   `maxAttempts: 1` на вызове отключает повторы и сразу поднимает `NctRateLimitExceededException`.

Один Guzzle-клиент на оба хоста. На портал уходят `Content-Type: application/json`, `Accept` и
`X-API-KEY`; на nct.gov.kz — только `Accept`: ключ API на публичный хост не попадает.

## Эндпоинты

| Метод | HTTP |
|---|---|
| `NctPortalClient::submitProductRequest()` | `POST /gwp/portal/api/v1/products/requests` |
| `NctPortalClient::submitToModeration()` | `PUT /gwp/portal/api/v1/products/requests/{id}/moderation` |
| `NctPortalClient::updateProductRequest()` | `PUT /gwp/portal/api/v1/products/requests/{id}` |
| `NctPortalClient::publishRequest()` | `PUT /gwp/portal/api/v1/products/requests/{id}/publish` |
| `NctPortalClient::fetchRequestStatus()` | `GET /gwp/portal/api/v1/products/requests/{id}/status` |
| `NctPortalClient::fetchRequestDetails()` | `GET /gwp/portal/api/v1/products/requests/{id}/details` |
| `NctPortalClient::fetchProductRequests()` | `GET /gwp/portal/api/v1/products/requests?page=&size=` |
| `NctPortalClient::fetchProductByGtin()` | `GET /gwp/portal/api/v1/products/{gtin}` |
| `NctPortalClient::fetchAttributes()` | `GET /gwp/portal/api/v1/products/requests/attributes` |
| `NctPortalClient::fetchDictionaryRoots()` | `GET /gwp/portal/api/v1/dictionaries/{code}/roots` |
| `NctPortalClient::fetchDictionaryChildren()` | `GET /gwp/portal/api/v1/dictionaries/{code}/children/{parentId}` |
| `NctGovClient::searchByGtin()` | `GET /api/integration/ofd/search_ofd/?tin={gtin}` |
| `NctGovClient::fetchProduct()` | `GET /api/core/products/{id}` |

## Тестирование

```bash
composer install
vendor/bin/phpunit
```

`NctHttpClientTest` — snapshot-тест транспорта (31 сценарий: повторы, `422`, все виды `404`,
таймауты, тела ошибок, заголовки по хостам); эталон — в `tests/Http/__snapshots__/`. Перезаписать
осознанно: `UPDATE_SNAPSHOTS=1 vendor/bin/phpunit`.

`NctClient`, `NctPortalClient` и `NctGovClient` не `final` — их можно подменять заглушками в тестах
вашего приложения.

## Границы

Бандл реализует протокол портала. Он намеренно **не** хранит заявки и их статусы, не решает, когда
идентификатор можно записать в товар, не собирает тело заявки из вашей модели данных и не
нормализует строки атрибутов и словарей в ваши справочники. Это решения приложения.

## Лицензия

MIT — см. [LICENSE](LICENSE).
