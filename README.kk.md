# NCT Client Bundle

[English](README.md) · [Русский](README.ru.md) · **Қазақша**

[![CI](https://github.com/iperson1337/nct-client-bundle/actions/workflows/ci.yml/badge.svg)](https://github.com/iperson1337/nct-client-bundle/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/iperson1337/nct-client-bundle.svg)](https://packagist.org/packages/iperson1337/nct-client-bundle)
[![PHP Version](https://img.shields.io/packagist/php-v/iperson1337/nct-client-bundle.svg)](https://packagist.org/packages/iperson1337/nct-client-bundle)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**Қазақстанның Ұлттық тауар каталогына (ҰТК / НКТ)** арналған Symfony-клиент:
[nationalcatalog.kz](https://nationalcatalog.kz) порталының толық API-і (тауарға өтінімдер және
олардың өмірлік циклі, файлдар, дубликаттар, карточкалар, атрибуттар, анықтамалықтар) және
[nct.gov.kz](https://nct.gov.kz) жария API-і (іздеу, уақыт бойынша экспорт). Сұрауларды қайталау, Redis
арқылы лимиттер, типтелген қателер, хаттама деректеріне арналған мән-объектілер.

Бандл порталдың хаттамасын біледі, бірақ сіздің деректер моделіңіз туралы ештеңе білмейді: жауаптар
талданған массив (немесе шағын мән-объектілер) түрінде қайтарылады, ал олармен не істеу — қолданбаның шешімі.

Қамту: порталдың ресми спецификациясындағы барлық 23 операция (`https://nationalcatalog.kz/gwp/docs`)
және nct.gov.kz жария коллекциясының барлық 5 сұрауы.

## Мүмкіндіктер

- **Портал API-і** (`nationalcatalog.kz`, `X-API-KEY`): өтінімді жасау, жаңарту, жою, кері қайтару,
  модерацияға жіберу және жариялау; жарияланған карточканы NTIN бойынша өңдеуге өтінім жасау;
  мәртебе бойынша сүзгісі бар өтінімдер тізімі; мәртебе мен мәліметтер; дубликаттар бойынша шешім;
  фотосурет пен PDF жүктеу; NTIN/GTIN бойынша карточкалар (біреу және тізім); негізгі және кеңейтілген
  атрибуттардың сипаттамалары; анықтамалықтар (тізім, түбірлер, ұрпақтар, беттелген элементтер).
- **Жария API** (`nct.gov.kz`): бір код, 100 кодқа дейінгі топ немесе атау бойынша карточкаларды іздеу;
  id бойынша карточка; өзгерген тауарларды уақыт бойынша экспорттау және санау (JWT арқылы).
- **Бір фасад** — `NctClient::fetchProductByGtin()` карточканы бапталған дереккөзден алады.
- **Лимиттер** — әр хостқа алдын ала жылжымалы терезе (Redis, Lua, процестерге ортақ) және
  `429` / `422 TOO_MANY_REQUESTS` кейін қайталау.
- **Шақыру деңгейіндегі баптаулар** — интерфейстегі синхронды тексерулер үшін қысқа таймаут және бір әрекет.
- **Типтелген қателер** — барлығы `\RuntimeException`-ден тарайды және `NctExceptionInterface`-ті іске асырады.
- **Мән-объектілер** — `AttributeDefinition`, `DictionaryItem`, `ProductRequestPayload` (ретке тәуелсіз
  салыстырумен), `RequestStatus` / `ExtendedRequestStatus` / `ImageType` / `DuplicateDecision`
  тізбелері, `RequestDetailsParser`, `NationalCatalogIdentifier`.

## Талаптар

- PHP 8.3+
- Symfony 7.4 (`config`, `dependency-injection`, `http-kernel`, `cache`)
- Guzzle 7
- Қалауыңызша: Redis-лимитер үшін `redis` кеңейтімі немесе `predis/predis`

## Орнату

```bash
composer require iperson1337/nct-client-bundle
```

Symfony Flex болмаса, бандлды қолмен тіркеңіз:

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
    portal:                                   # nationalcatalog.kz, X-API-KEY авторизациясы
        host: 'https://nationalcatalog.kz'
        api_key: '%env(NCT_API_KEY)%'
        timeout: 30                           # жауап таймауты, секунд
        connect_timeout: 5
        retry_count: 3                        # 429 кейінгі қайталаулар, бірінші әрекеттен бөлек
        retry_after_seconds: 30               # 429 кейін қайталау алдындағы кідіріс
        rate_limit:
            requests: 100                     # `window_seconds` ішінде ең көбі `requests` сұрау
            window_seconds: 60
            redis_key: 'nct:rate_limit:requests'
    gov:                                      # nct.gov.kz, жария
        host: 'https://nct.gov.kz'
        retry_after_seconds: 2                # nct.gov.kz шамамен бір секундта қалпына келеді
        rate_limit:
            requests: 2                       # жария хост секундына ~3 сұраулық шоғырды көтереді
            window_seconds: 1
            redis_key: 'nct:gov:rate_limit:requests'
    lookup_source: nctgov                     # GTIN бойынша карточка дереккөзі: nctgov | nationalcatalog
    redis_dsn: '%env(REDIS_URL)%'             # null лимиттерді өшіреді (NullRateLimiter)
```

Барлық кілттер міндетті емес; жоғарыда әдепкі мәндер берілген (`api_key` бос, `redis_dsn` — `null`).

| Кілт | Мағынасы |
|---|---|
| `portal.host`, `portal.api_key` | портал адресі және өтінім берушінің API кілті; өтінімдермен барлық операциялар `lookup_source`-қа қарамастан осында барады |
| `portal.timeout`, `portal.connect_timeout` | екі хостқа да әдепкі Guzzle таймауттары; шақыру деңгейінде `$timeoutOverride` аргументімен өзгертіледі |
| `portal.retry_count` | `429` / `422 TOO_MANY_REQUESTS` кейін неше рет қайталау; шақыруда `$maxAttempts = 1` қайталауды өшіреді |
| `*.retry_after_seconds` | қайталау алдындағы `sleep()`, әр хостқа өзінікі |
| `*.rate_limit` | сұрау алдындағы алдын ала лимит: әр хостқа өз кілті бар Redis-тегі жылжымалы терезе, сондықтан хосттар бір-біріне кедергі келтірмейді |
| `lookup_source` | `NctClient::fetchProductByGtin()` карточканы қайдан алады: `nctgov` — жария іздеу, жауап `{gtin, ntin_code}` түріне келтіріледі; `nationalcatalog` — порталдың `GET /products/{gtin}`, жауап сол қалпында |
| `redis_dsn` | лимитерлер үшін Redis; Redis қолжетімсіз болса, лимит ескертумен өткізіледі, сұрау орындалады |

Тіркелетін сервистер (класс бойынша автовайринг): `NctClient`, `NctPortalClient`, `NctGovClient`,
`NctHttpClient`, `RequestDetailsParser`, `NationalCatalogIdentifier`.

## Пайдалану

### GTIN бойынша карточка

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

            // nct.gov.kz `ntin_code` қайтарады, портал — `ntin`
            return $card['ntin'] ?? $card['ntin_code'] ?? null;
        } catch (NctProductNotFoundException) {
            return null; // бұл GTIN-ге карточка жоқ — қалыпты нәтиже, ақау емес
        }
    }
}
```

Интерфейстегі синхронды тексеру үшін қысқа таймаут және бір әрекет (толық `timeout`-ты күтпеу және
`429`-де ұйықтамау):

```php
$card = $this->nct->fetchProductByGtin($gtin, timeoutOverride: 3.0, maxAttempts: 1);
```

Порталда GTIN бірегей емес — бір GTIN бірнеше карточкаға тиесілі болуы мүмкін. Барлығын көру:

```php
$cards = $this->nct->portal()->fetchProductsByTin('4870001302818');   // GET /v2/products/{tin}
```

### Өтінімнің өмірлік циклі

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

$requestId = (int) $portal->submitProductRequest($payload->toArray())['id'];   // POST …/requests → жоба

$portal->submitToModeration($requestId);                       // PUT …/{id}/moderation (new, underRevision)
$portal->cancelRequest($requestId);                            // PUT …/{id}/cancel     (onModeration → cancelled)
$portal->updateProductRequest($requestId, $payload->toArray()); // PUT …/{id}            (new, underRevision, cancelled)
$portal->publishRequest($requestId);                           // PUT …/{id}/publish    (readyToPublish)
$portal->deleteProductRequest($requestId);                     // DELETE …/{id}         (тек new)
```

Портал *жасау* өтінімінде `NTIN` атрибутын қабылдамайды. **Жарияланған** карточканы өңдеу үшін
`submitProductRequest()` денесінде `ntin` беріңіз немесе карточканың өзінен бастаңыз:

```php
$draft = $portal->createEditRequest('0200212305195');   // POST …/requests/{ntin}/edit-product
$requestId = (int) $draft['productRequestId'];
$body = ProductRequestPayload::fromArray($draft);       // PUT …/requests/{id} сияқты форма
// ескі карточкаларда `oktru` null келуі мүмкін — жібермес бұрын қолданыстағы категория кодын көрсетіңіз
$portal->updateProductRequest($requestId, $body->toArray());
```

Өңдеу өтінімінде `name_ru`, `name_kk`, `ntin` және `tnved` өзгертілмейді; кеңейтілген карточкалар
(КЗТИН-мен) API арқылы өңделмейді.

`ProductRequestPayload` денелерді кілттер мен атрибуттардың ретіне қарамай салыстырады — сақталған
өтінім шынымен өзгерді ме, соны модерацияны қайта қоздыратын `PUT` жібермес бұрын біліңіз:

```php
if (!$payload->matchesStoredJson($request->getRequestBody())) {
    $portal->updateProductRequest($requestId, $payload->toArray());
}
```

### Өтінімнің мәртебесі мен мәліметтері

```php
use Iperson1337\NctClientBundle\Request\RequestDetailsParser;
use Iperson1337\NctClientBundle\Request\RequestStatus;

$status = $portal->fetchRequestStatus($requestId);
// ['code' => 'completed', 'value' => '…', 'ntinCode' => '0200…', 'productUrl' => '…',
//  'comment' => …, 'revisionDetails' => [...], 'nextAction' => [...], 'gzStatus' => [...]]
$code = RequestStatus::tryFrom($status['code']);
if ($code?->isFinal()) { /* completed, rejected немесе existingProductSelected */ }

$details = $portal->fetchRequestDetails($requestId, timeoutOverride: 3.0, maxAttempts: 1);
$parser  = new RequestDetailsParser();
$ntin    = $parser->extractAttributeValueByCode($details, 'ntin');
$xtin    = $parser->extractAttributeValueByCode($details, 'xtin');
$comment = $parser->extractModeratorComment($details);        // null — өріс жоқ; '' — өріс бар, бірақ бос

$page = $portal->fetchProductRequests(page: 1, size: 50, status: RequestStatus::OnModeration);
// ['content' => [...], 'totalElements' => N, 'totalPages' => M]
```

Негізгі өтінім мәртебелері (`RequestStatus`): `new`, `onModeration`, `accepted`, `underRevision`,
`cancelled`, `readyToPublish`, `existingProductSelected`, `completed`, `rejected`. `gzStatus` ішіндегі
кеңейтілген бөлік мәртебелері (`ExtendedRequestStatus`): `acceptedGz`, `underRevisionGz`, `rejectedGz`, `publishingGz`.

### Дубликаттар

Модерация өтінімді `DUPLICATE_REVIEW` себебімен қайтарғанда:

```php
use Iperson1337\NctClientBundle\Portal\DuplicateDecision;

$matches = $portal->fetchDuplicates($requestId);   // orderNumber, name, brand, gtin, ntin, similarity
$portal->decideDuplicates($requestId, DuplicateDecision::UseExisting, $matches[0]['ntin']);
// немесе: $portal->decideDuplicates($requestId, DuplicateDecision::Continue);
```

### Фотосуреттер мен құжаттар

Файлдар алдымен өтінімге жүктеледі (`new`, `underRevision`, `cancelled` мәртебелері; ең көбі 5 МБ),
содан кейін өтінім денесінде `filename` арқылы көрсетіледі:

```php
use Iperson1337\NctClientBundle\Portal\ImageType;

$front = $portal->uploadPhoto($requestId, ImageType::Front, '/path/front.jpg');     // POST …/storage/{id}/upload?imageType=front
$back  = $portal->uploadPhoto($requestId, ImageType::Back, fopen('php://memory', 'r+'), 'back.jpg');
$cert  = $portal->uploadDocument($requestId, '/path/certificate.pdf');              // POST …/storage/{id}/upload/document
$portal->deleteFile($requestId, $cert['filename']);                                 // DELETE …/storage/{id}/{fileName}

$payload = new ProductRequestPayload(
    oktru: '10.51.11',
    autoPublication: false,
    attributes: [/* … соның ішінде ['code' => 'brand', 'value' => '…'] */],
    isGov: true,
    mainExtendedAttributes: [['code' => 'gross_weight', 'value' => '1.05']],
    additionalExtendedAttributes: [/* fetchGovAttributes() нәтижесінен */],
    imageFiles: [
        ['fileName' => $front['filename'], 'imageType' => ImageType::Front->value],
        ['fileName' => $back['filename'], 'imageType' => ImageType::Back->value],
    ],
    documents: [['fileName' => $cert['filename']]],
);
```

Кеңейтілген карточкаға (`isGov: true`) модерация алдында `front` және `back` фотолары, `brand`
атрибуты және кемінде бес қосымша сипаттама қажет.

### Атрибут сипаттамалары мен анықтамалықтар

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

$gov = $portal->fetchGovAttributes('10.51.11');                // ОКТРУ үшін mainAttributes + additionalAttributes

$dictionaries = $portal->fetchDictionaries();                   // GET …/dictionaries
$roots = $portal->fetchDictionaryRoots('MEASURE_UNIT');         // ағаш тәрізді анықтамалықтар
$children = $portal->fetchDictionaryChildren('MEASURE_UNIT', (int) $roots[0]['id']);
$page = $portal->fetchDictionaryItems('TNVED', page: 1, size: 100);   // кез келген анықтамалық, беттеп (size 1..100)

foreach ($roots as $row) {
    if (DictionaryItem::missingRequiredField($row) === null) {
        $item = DictionaryItem::fromRow($row);                 // externalId, code, nameRu, nameKk, parentId, level, hasChild, isDeleted
    }
}
```

### nct.gov.kz: іздеу, карточка, экспорт

```php
$gov = $this->nct->gov();

$cards = $gov->searchByGtin($gtin);                          // осы кодпен барлық карточкалар, өшірілген дубликаттарды қоса; жоқ болса — []
$cards = $gov->searchByTins(['4870235770032', '0200093179779']);   // бір сұрауда 100 NTIN/GTIN-ге дейін
$page  = $gov->searchByName('Молоко', limit: 100, offset: 0);     // ['count', 'next', 'previous', 'last_modified', 'result' => [...]]
$card  = $gov->findActiveCard($gtin);                        // ntin_isdeactivated === false болған біріншісі; бәрі өшірілген болса — null
$ntin  = $gov->lookupNtin($gtin);                            // ['gtin' => …, 'ntin_code' => string|null]; карточка жоқ болса — қате
$full  = $gov->fetchProduct((int) $card['id']);              // GET /api/core/products/{id}; ОКТРУ — category.full_code ішінде
```

Уақыт бойынша экспортқа nct.gov.kz есептік жазбасы қажет (лимит: минутына 120 сұрау):

```php
$auth = $gov->login('username', 'password');                 // POST /api/auth/login/ — JWT бар жауап сол қалпында
$jwt = $auth['access'] ?? $auth['token'];                    // кілт атауы порталдың жауабына байланысты

$count = $gov->countChangedProducts($jwt, from: 1743596160)['count'];
$page = $gov->exportProducts($jwt, from: 1743596160, limit: 1000, offset: 0);
foreach ($page['result'] as $card) {
    // id, name_ru, name_kk, gtin, ntin_code, is_social, modified, status, is_markedeac, measure,
    // product_type (1 тауар, 2 жұмыс, 3 қызмет), ntin_isdeactivated, ntin_deactivationreason, ntin_duplicateofproduct
}
// келесі шақыру: from = алынған ең үлкен `modified`
```

Бірнеше сәтсіз кіру әрекетінен кейін портал есептік жазбаны 10 минутқа бұғаттайды (`403`, `locked_seconds`).

### NTIN / XTIN форматы

```php
use Iperson1337\NctClientBundle\Identifier\NationalCatalogIdentifier;

$id = new NationalCatalogIdentifier();
$id->isValidNtin('0200212305195');  // true — «02» + 11 цифр
$id->isValidXtin('0040212305191');  // true — «004» + 10 цифр
$id->normalize('  0200212305195 '); // '0200212305195'; бос → null
```

Идентификаторлар — жолдар: алдыңғы нөлдер маңызды.

## Қателер

Барлық қателер `\RuntimeException`-ден тарайды және `NctExceptionInterface`-ті іске асырады:
бұрынғы `catch (\RuntimeException)` жұмысын жалғастырады, ал `catch (NctExceptionInterface)` ҰТК-ның
кез келген ақауын ұстайды.

| Қате | Қашан |
|---|---|
| `NctProductNotFoundException` | GTIN бойынша карточка жоқ (nct.gov.kz `404 "product … not found"` немесе бос тізім қайтарды). Хабарлама тұрақты: `Product with GTIN {gtin} not found in NCT (nct.gov.kz)` |
| `NctRateLimitExceededException` | `429` немесе `422 TOO_MANY_REQUESTS`, әрекеттер таусылды (немесе `maxAttempts: 1`) |
| `NctRequestTimeoutException` | қосылу таймауты (`ConnectException`) немесе жауап таймауты (cURL 28) |
| `NctTransportException` | `5xx`, желі ақауы, әрекеттер таусылды |
| `NctClientException` | басқа `4xx`: жарамсыз өтінім, рұқсат жоқ, API жолы өзгерді. Хабарламада — `HTTP {мәртебе} {дене}`: жауап денесі толық (2000 таңбамен шектеліп, қысқартылғаны белгіленеді), өйткені қай атрибуттың қабылданбағанын осы дене түсіндіреді |
| `NctResponseException` | `2xx`, бірақ дене JSON ретінде талданбайды |

`{"Message": "product … not found"}` денесі бар `404` тек осыны жариялаған сұрауларда ғана
«табылмады» деп саналады (`NctRequest::$notFoundAsEmpty`): API жолы өзгергеннен кейінгі `404` тауар
жоқ сияқты көрінбеуі керек.

## Лимиттер және қайталаулар

Әр сұрауды екі қабат қорғайды:

1. **Алдын ала лимит** — сұрау алдында `RateLimiterInterface::waitIfNeeded()`, әр хостқа өз лимитері
   (`NctTarget::Portal` / `NctTarget::Gov`). Redis-тегі іске асыру уақыт белгілерінің сұрыпталған
   жиынын сақтайды; атомдық Lua-скрипт терезедегі сұрауларды санап, терезе толған кезде ең ескісі
   шығатын уақытты қайтарады. Redis ақауында лимит ескертумен өткізіледі — лимитер процесті
   тоқтатпауы керек.
2. **429 кейін қайталау** — хосттың `retry_after_seconds` кідірісімен `retry_count`-қа дейін
   қайталау; шақырудағы `maxAttempts: 1` қайталауды өшіріп, бірден `NctRateLimitExceededException`
   шығарады.

Екі хостқа да бір Guzzle-клиент. Порталға `Content-Type: application/json`, `Accept` және
`X-API-KEY` жіберіледі (multipart жүктеулерде шекараны Guzzle қояды); nct.gov.kz-ке — тек `Accept`,
экспорт үшін қосымша `Authorization: JWT …`: API кілті жария хостқа кетпейді.

## Эндпоинттер

| Әдіс | HTTP |
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

## Тестілеу

```bash
composer install
vendor/bin/phpunit
```

`NctHttpClientTest` — транспорттың snapshot-тесті (31 сценарий: қайталаулар, `422`, `404`-тің барлық
түрі, таймауттар, қате денелері, хост бойынша тақырыптар); эталон `tests/Http/__snapshots__/` ішінде.
Эталонды саналы түрде қайта жазу: `UPDATE_SNAPSHOTS=1 vendor/bin/phpunit`. `NctPortalClientTest` және
`NctGovClientTest` әр операцияның әдісін, жолын, query-ін, денесі мен тақырыптарын Guzzle `MockHandler`
арқылы бекітеді.

`NctClient`, `NctPortalClient` және `NctGovClient` `final` емес — оларды өз қолданбаңыздың тесттерінде
алмастыруға болады.

## Шекаралар

Бандл портал хаттамасын іске асырады. Ол әдейі өтінімдерді және олардың мәртебелерін сақта**майды**,
идентификаторды тауарға қашан жазуға болатынын шеш**пейді**, сіздің деректер моделіңізден өтінім
денесін құра**майды** және атрибуттар мен анықтамалық жолдарын сіздің анықтамалықтарыңызға
келтір**мейді**. Мән-объектілер бұл жолдарға болжамды форма береді; келтірудің өзі қолданбада қалады.

## Лицензия

MIT — [LICENSE](LICENSE) қараңыз.
