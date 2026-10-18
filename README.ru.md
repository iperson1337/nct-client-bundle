# NCT Client Bundle

[English](README.md) · **Русский** · [Қазақша](README.kk.md)

[![CI](https://github.com/iperson1337/nct-client-bundle/actions/workflows/ci.yml/badge.svg)](https://github.com/iperson1337/nct-client-bundle/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/iperson1337/nct-client-bundle.svg)](https://packagist.org/packages/iperson1337/nct-client-bundle)
[![PHP Version](https://img.shields.io/packagist/php-v/iperson1337/nct-client-bundle.svg)](https://packagist.org/packages/iperson1337/nct-client-bundle)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Symfony-клиент **Национального каталога товаров Казахстана (НКТ)**: полное API портала
[nationalcatalog.kz](https://nationalcatalog.kz) (заявки на товары и их жизненный цикл, файлы, дубли,
карточки, атрибуты, справочники) и публичное API [nct.gov.kz](https://nct.gov.kz) (поиск, выгрузка
по времени). Повторы запросов, лимиты через Redis, типизированные исключения, объекты-значения для
данных протокола.

Бандл знает протокол портала и ничего не знает о вашей модели данных: ответы возвращаются
разобранными массивами (или небольшими объектами-значениями), а что с ними делать — решает приложение.

Покрытие: все 23 операции официальной спецификации портала (`https://nationalcatalog.kz/gwp/docs`)
и все 5 запросов публичной коллекции nct.gov.kz.

## Возможности

- **API портала** (`nationalcatalog.kz`, `X-API-KEY`): создать, обновить, удалить, отозвать,
  отправить на модерацию и опубликовать заявку; создать заявку на редактирование опубликованной
  карточки по NTIN; список заявок с фильтром по статусу; статус и детали; решение по дублям; загрузка
  фотографий и PDF; карточки по NTIN/GTIN (одна и список); описания базовых и расширенных атрибутов;
  справочники (список, корни, потомки, постраничные элементы).
- **Публичное API** (`nct.gov.kz`): поиск карточек по одному коду, по пачке до 100 кодов и по названию;
  карточка по id; выгрузка изменённых товаров по времени с подсчётом (по JWT).
- **Один фасад** — `NctClient::fetchProductByGtin()` берёт карточку из настроенного источника.
- **Лимиты** — упреждающее скользящее окно на каждый хост (Redis, Lua, общее для процессов) плюс
  повторы после `429` / `422 TOO_MANY_REQUESTS`.
- **Настройки на вызов** — короткий таймаут и одна попытка для синхронных проверок в интерфейсе.
- **Типизированные исключения** — все наследуют `\RuntimeException` и реализуют `NctExceptionInterface`.
- **Объекты-значения** — `AttributeDefinition`, `DictionaryItem`, `ProductRequestPayload` (со сравнением
  без учёта порядка), перечисления `RequestStatus` / `ExtendedRequestStatus` / `ImageType` /
  `DuplicateDecision`, `RequestDetailsParser`, `NationalCatalogIdentifier`.

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

GTIN на портале не уникален — один GTIN может принадлежать нескольким карточкам. Увидеть все:

```php
$cards = $this->nct->portal()->fetchProductsByTin('4870001302818');   // GET /v2/products/{tin}
```

### Жизненный цикл заявки

```php
use Iperson1337\NctClientBundle\Request\ProductRequestPayload;

$portal = $this->nct->portal();

$payload = new ProductRequestPayload(
    oktru: '10.51.11',
    autoPublication: false,
    attributes: [
        ['code' => 'gtin', 'value' => '4870001302818'],
        ['code' => 'name_ru', 'value' => 'Молоко 2,5% 1 л'],
    ],
);

$requestId = (int) $portal->submitProductRequest($payload->toArray())['id'];   // POST …/requests → черновик

$portal->submitToModeration($requestId);                       // PUT …/{id}/moderation (new, underRevision)
$portal->cancelRequest($requestId);                            // PUT …/{id}/cancel     (onModeration → cancelled)
$portal->updateProductRequest($requestId, $payload->toArray()); // PUT …/{id}            (new, underRevision, cancelled)
$portal->publishRequest($requestId);                           // PUT …/{id}/publish    (readyToPublish)
$portal->deleteProductRequest($requestId);                     // DELETE …/{id}         (только new)
```

Портал не принимает атрибут `NTIN` в заявке на *создание*. Чтобы отредактировать **опубликованную**
карточку, передайте `ntin` в теле `submitProductRequest()` или начните с самой карточки:

```php
$draft = $portal->createEditRequest('0200212305195');   // POST …/requests/{ntin}/edit-product
$requestId = (int) $draft['productRequestId'];
$body = ProductRequestPayload::fromArray($draft);       // та же форма, что у PUT …/requests/{id}
// у старых карточек `oktru` может прийти null — укажите актуальный код категории перед отправкой
$portal->updateProductRequest($requestId, $body->toArray());
```

В заявке на редактирование нельзя менять `name_ru`, `name_kk`, `ntin` и `tnved`; расширенные карточки
(с КЗТИН) через API не редактируются.

`ProductRequestPayload` сравнивает тела без учёта порядка ключей и атрибутов — так можно понять,
изменилась ли сохранённая заявка, прежде чем слать `PUT`, который снова отправит её на модерацию:

```php
if (!$payload->matchesStoredJson($request->getRequestBody())) {
    $portal->updateProductRequest($requestId, $payload->toArray());
}
```

### Статус и детали заявки

```php
use Iperson1337\NctClientBundle\Request\RequestDetailsParser;
use Iperson1337\NctClientBundle\Request\RequestStatus;

$status = $portal->fetchRequestStatus($requestId);
// ['code' => 'completed', 'value' => '…', 'ntinCode' => '0200…', 'productUrl' => '…',
//  'comment' => …, 'revisionDetails' => [...], 'nextAction' => [...], 'gzStatus' => [...]]
$code = RequestStatus::tryFrom($status['code']);
if ($code?->isFinal()) { /* completed, rejected или existingProductSelected */ }

$details = $portal->fetchRequestDetails($requestId, timeoutOverride: 3.0, maxAttempts: 1);
$parser  = new RequestDetailsParser();
$ntin    = $parser->extractAttributeValueByCode($details, 'ntin');
$xtin    = $parser->extractAttributeValueByCode($details, 'xtin');
$comment = $parser->extractModeratorComment($details);        // null — поля нет; '' — поле есть, но пустое

$page = $portal->fetchProductRequests(page: 1, size: 50, status: RequestStatus::OnModeration);
// ['content' => [...], 'totalElements' => N, 'totalPages' => M]
```

Статусы базовой заявки (`RequestStatus`): `new`, `onModeration`, `accepted`, `underRevision`,
`cancelled`, `readyToPublish`, `existingProductSelected`, `completed`, `rejected`. Статусы расширенной
части в `gzStatus` (`ExtendedRequestStatus`): `acceptedGz`, `underRevisionGz`, `rejectedGz`, `publishingGz`.

### Дубли

Когда модерация вернула заявку с причиной `DUPLICATE_REVIEW`:

```php
use Iperson1337\NctClientBundle\Portal\DuplicateDecision;

$matches = $portal->fetchDuplicates($requestId);   // orderNumber, name, brand, gtin, ntin, similarity
$portal->decideDuplicates($requestId, DuplicateDecision::UseExisting, $matches[0]['ntin']);
// или: $portal->decideDuplicates($requestId, DuplicateDecision::Continue);
```

### Фотографии и документы

Файлы сначала загружаются в заявку (статусы `new`, `underRevision`, `cancelled`; до 5 МБ), затем
указываются по `filename` в теле заявки:

```php
use Iperson1337\NctClientBundle\Portal\ImageType;

$front = $portal->uploadPhoto($requestId, ImageType::Front, '/path/front.jpg');     // POST …/storage/{id}/upload?imageType=front
$back  = $portal->uploadPhoto($requestId, ImageType::Back, fopen('php://memory', 'r+'), 'back.jpg');
$cert  = $portal->uploadDocument($requestId, '/path/certificate.pdf');              // POST …/storage/{id}/upload/document
$portal->deleteFile($requestId, $cert['filename']);                                 // DELETE …/storage/{id}/{fileName}

$payload = new ProductRequestPayload(
    oktru: '10.51.11',
    autoPublication: false,
    attributes: [/* … в т.ч. ['code' => 'brand', 'value' => '…'] */],
    isGov: true,
    mainExtendedAttributes: [['code' => 'gross_weight', 'value' => '1.05']],
    additionalExtendedAttributes: [/* из fetchGovAttributes() */],
    imageFiles: [
        ['fileName' => $front['filename'], 'imageType' => ImageType::Front->value],
        ['fileName' => $back['filename'], 'imageType' => ImageType::Back->value],
    ],
    documents: [['fileName' => $cert['filename']]],
);
```

Расширенной карточке (`isGov: true`) перед модерацией нужны фото `front` и `back`, атрибут `brand`
и не меньше пяти дополнительных характеристик.

### Описания атрибутов и справочники

```php
use Iperson1337\NctClientBundle\Attribute\AttributeDefinition;
use Iperson1337\NctClientBundle\Dictionary\DictionaryItem;

foreach ($portal->fetchAttributes() as $row) {                 // GET …/requests/attributes
    if (AttributeDefinition::missingRequiredFields($row) !== []) {
        continue;
    }
    $definition = AttributeDefinition::fromPortalRow($row);
    // $definition->code, ->dataType ('string'|'text'|'number'|'boolean'|'dictionary'|'multiDictionary'|'date'),
    // ->isRequired, ->maxLength, ->pattern, ->dictionaryCode, ->dictionaryValues …
}

$gov = $portal->fetchGovAttributes('10.51.11');                // mainAttributes + additionalAttributes для ОКТРУ

$dictionaries = $portal->fetchDictionaries();                   // GET …/dictionaries
$roots = $portal->fetchDictionaryRoots('MEASURE_UNIT');         // древовидные справочники
$children = $portal->fetchDictionaryChildren('MEASURE_UNIT', (int) $roots[0]['id']);
$page = $portal->fetchDictionaryItems('TNVED', page: 1, size: 100);   // любой справочник, постранично (size 1..100)

foreach ($roots as $row) {
    if (DictionaryItem::missingRequiredField($row) === null) {
        $item = DictionaryItem::fromRow($row);                 // externalId, code, nameRu, nameKk, parentId, level, hasChild, isDeleted
    }
}
```

### nct.gov.kz: поиск, карточка, выгрузка

```php
$gov = $this->nct->gov();

$cards = $gov->searchByGtin($gtin);                          // все карточки с кодом, включая деактивированные дубликаты; [] — нет
$cards = $gov->searchByTins(['4870235770032', '0200093179779']);   // до 100 NTIN/GTIN за запрос
$page  = $gov->searchByName('Молоко', limit: 100, offset: 0);     // ['count', 'next', 'previous', 'last_modified', 'result' => [...]]
$card  = $gov->findActiveCard($gtin);                        // первая с ntin_isdeactivated === false; null — все деактивированы
$ntin  = $gov->lookupNtin($gtin);                            // ['gtin' => …, 'ntin_code' => string|null]; исключение, если карточек нет
$full  = $gov->fetchProduct((int) $card['id']);              // GET /api/core/products/{id}; ОКТРУ — в category.full_code
```

Для выгрузки по времени нужна учётная запись nct.gov.kz (лимит: 120 запросов в минуту):

```php
$auth = $gov->login('username', 'password');                 // POST /api/auth/login/ — ответ как есть, с JWT
$jwt = $auth['access'] ?? $auth['token'];                    // имя ключа зависит от ответа портала

$count = $gov->countChangedProducts($jwt, from: 1743596160)['count'];
$page = $gov->exportProducts($jwt, from: 1743596160, limit: 1000, offset: 0);
foreach ($page['result'] as $card) {
    // id, name_ru, name_kk, gtin, ntin_code, is_social, modified, status, is_markedeac, measure,
    // product_type (1 товар, 2 работа, 3 услуга), ntin_isdeactivated, ntin_deactivationreason, ntin_duplicateofproduct
}
// следующий вызов: from = наибольший полученный `modified`
```

После нескольких неверных попыток входа портал блокирует учётную запись на 10 минут (`403`, `locked_seconds`).

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
`X-API-KEY` (для multipart-загрузок границу выставляет Guzzle); на nct.gov.kz — только `Accept`, для
выгрузки ещё `Authorization: JWT …`: ключ API на публичный хост не попадает.

## Эндпоинты

| Метод | HTTP |
|---|---|
| `NctPortalClient::submitProductRequest()` | `POST /gwp/portal/api/v1/products/requests` |
| `NctPortalClient::createEditRequest()` | `POST /gwp/portal/api/v1/products/requests/{ntin}/edit-product` |
| `NctPortalClient::updateProductRequest()` | `PUT /gwp/portal/api/v1/products/requests/{id}` |
| `NctPortalClient::deleteProductRequest()` | `DELETE /gwp/portal/api/v1/products/requests/{id}` |
| `NctPortalClient::submitToModeration()` | `PUT /gwp/portal/api/v1/products/requests/{id}/moderation` |
| `NctPortalClient::cancelRequest()` | `PUT /gwp/portal/api/v1/products/requests/{id}/cancel` |
| `NctPortalClient::publishRequest()` | `PUT /gwp/portal/api/v1/products/requests/{id}/publish` |
| `NctPortalClient::fetchRequestStatus()` | `GET /gwp/portal/api/v1/products/requests/{id}/status` |
| `NctPortalClient::fetchRequestDetails()` | `GET /gwp/portal/api/v1/products/requests/{id}/details` |
| `NctPortalClient::fetchProductRequests()` | `GET /gwp/portal/api/v1/products/requests?page=&size=[&status=]` |
| `NctPortalClient::fetchDuplicates()` | `GET /gwp/portal/api/v1/products/requests/{id}/duplicates` |
| `NctPortalClient::decideDuplicates()` | `POST /gwp/portal/api/v1/products/requests/{id}/duplicate-decision` |
| `NctPortalClient::uploadPhoto()` | `POST /gwp/portal/api/v1/storage/{requestId}/upload?imageType=` |
| `NctPortalClient::uploadDocument()` | `POST /gwp/portal/api/v1/storage/{requestId}/upload/document` |
| `NctPortalClient::deleteFile()` | `DELETE /gwp/portal/api/v1/storage/{requestId}/{fileName}` |
| `NctPortalClient::fetchProductByGtin()` | `GET /gwp/portal/api/v1/products/{tin}` |
| `NctPortalClient::fetchProductsByTin()` | `GET /gwp/portal/api/v2/products/{tin}` |
| `NctPortalClient::fetchAttributes()` | `GET /gwp/portal/api/v1/products/requests/attributes` |
| `NctPortalClient::fetchGovAttributes()` | `GET /gwp/portal/api/v1/products/requests/categories/{oktru}/gov-attributes` |
| `NctPortalClient::fetchDictionaries()` | `GET /gwp/portal/api/v1/dictionaries` |
| `NctPortalClient::fetchDictionaryRoots()` | `GET /gwp/portal/api/v1/dictionaries/{code}/roots` |
| `NctPortalClient::fetchDictionaryChildren()` | `GET /gwp/portal/api/v1/dictionaries/{code}/children/{parentId}` |
| `NctPortalClient::fetchDictionaryItems()` | `GET /gwp/portal/api/v1/dictionaries/{code}/items?page=&size=` |
| `NctGovClient::searchByGtin()`, `searchByTins()` | `GET /api/integration/ofd/search_ofd/?tin={codes}` |
| `NctGovClient::searchByName()` | `GET /api/integration/ofd/search_ofd/?q={name}&limit=&offset=` |
| `NctGovClient::fetchProduct()` | `GET /api/core/products/{id}` |
| `NctGovClient::login()` | `POST /api/auth/login/` |
| `NctGovClient::exportProducts()` | `GET /api/integration/ofd/ofd_product/?from=&limit=&offset=` (JWT) |
| `NctGovClient::countChangedProducts()` | `GET /api/integration/ofd/ofd_product_count/?from=` (JWT) |

## Тестирование

```bash
composer install
vendor/bin/phpunit
```

`NctHttpClientTest` — snapshot-тест транспорта (31 сценарий: повторы, `422`, все виды `404`,
таймауты, тела ошибок, заголовки по хостам); эталон — в `tests/Http/__snapshots__/`. Перезаписать
осознанно: `UPDATE_SNAPSHOTS=1 vendor/bin/phpunit`. `NctPortalClientTest` и `NctGovClientTest`
закрепляют метод, путь, query, тело и заголовки каждой операции на `MockHandler` Guzzle.

`NctClient`, `NctPortalClient` и `NctGovClient` не `final` — их можно подменять заглушками в тестах
вашего приложения.

## Границы

Бандл реализует протокол портала. Он намеренно **не** хранит заявки и их статусы, не решает, когда
идентификатор можно записать в товар, не собирает тело заявки из вашей модели данных и не отображает
строки атрибутов и словарей в ваши справочники. Объекты-значения придают этим строкам предсказуемую
форму; само отображение остаётся в приложении.

## Лицензия

MIT — см. [LICENSE](LICENSE).
