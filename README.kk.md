# NCT Client Bundle

[English](README.md) · [Русский](README.ru.md) · **Қазақша**

[![CI](https://github.com/iperson1337/nct-client-bundle/actions/workflows/ci.yml/badge.svg)](https://github.com/iperson1337/nct-client-bundle/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/iperson1337/nct-client-bundle.svg)](https://packagist.org/packages/iperson1337/nct-client-bundle)
[![PHP Version](https://img.shields.io/packagist/php-v/iperson1337/nct-client-bundle.svg)](https://packagist.org/packages/iperson1337/nct-client-bundle)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**Қазақстанның Ұлттық тауар каталогына (ҰТК / НКТ)** арналған Symfony-клиент:
[nationalcatalog.kz](https://nationalcatalog.kz) порталының API-і (тауарға өтінімдер, атрибуттар,
анықтамалықтар) және [nct.gov.kz](https://nct.gov.kz) жария іздеулері. Сұрауларды қайталау, Redis
арқылы лимиттер, типтелген қателер, жауаптарды талдау.

Бандл порталдың хаттамасын біледі, бірақ сіздің деректер моделіңіз туралы ештеңе білмейді: жауаптар
талданған массив түрінде қайтарылады, ал олармен не істеу — қолданбаның шешімі.

## Мүмкіндіктер

- **Портал API-і** (`nationalcatalog.kz`, `X-API-KEY`): тауарға өтінім жасау, модерацияға жіберу,
  өзгерту, жариялау, мәртебесі мен толық мәліметтерін оқу, өтінімдер тізімін, атрибут сипаттамаларын
  және анықтамалықтарды алу, GTIN бойынша карточканы табу.
- **Жария API** (`nct.gov.kz`, авторизациясыз): GTIN бойынша карточкаларды іздеу, қолданыстағы
  карточканы таңдау, id бойынша карточка (ОКТРУ категориясымен).
- **Бір фасад** — `NctClient::fetchProductByGtin()` карточканы бапталған дереккөзден алады.
- **Лимиттер** — әр хостқа алдын ала жылжымалы терезе (Redis, Lua, процестерге ортақ) және
  `429` / `422 TOO_MANY_REQUESTS` кейін қайталау.
- **Шақыру деңгейіндегі баптаулар** — интерфейстегі синхронды тексерулер үшін қысқа таймаут және бір
  әрекет.
- **Типтелген қателер** — барлығы `\RuntimeException`-ден тарайды және `NctExceptionInterface`-ті
  іске асырады.
- **Көмекшілер** — өтінім мәліметтерінің парсері, NTIN/XTIN форматын тексеру.

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

### Өтінімнің өмірлік циклі

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

Портал *жасау* өтінімінде `NTIN` атрибутын қабылдамайды
(`400 VALIDATION_ERROR: The "NTIN" field must not be filled when creating a new product`).

### Өтінімнің мәртебесі мен мәліметтері

```php
use Iperson1337\NctClientBundle\Request\RequestDetailsParser;

$status  = $portal->fetchRequestStatus($requestId);   // ['code' => 'onModeration', 'value' => '…']
$details = $portal->fetchRequestDetails($requestId, timeoutOverride: 3.0, maxAttempts: 1);

$parser  = new RequestDetailsParser();
$code    = $parser->extractStatusCode($details);               // 'completed', 'rejected', …
$ntin    = $parser->extractAttributeValueByCode($details, 'ntin');
$xtin    = $parser->extractAttributeValueByCode($details, 'xtin');
$comment = $parser->extractModeratorComment($details);        // null — өріс жоқ; '' — өріс бар, бірақ бос

$page = $portal->fetchProductRequests(page: 1, size: 50);
```

Портал қайтаратын мәртебе кодтары: `new`, `onModeration`, `accepted`, `underRevision`,
`readyToPublish`, `completed`, `rejected`.

### Атрибут сипаттамалары мен анықтамалықтар

```php
$attributes = $portal->fetchAttributes();                  // code, dataType, isRequired, dictionaryCode, …
$roots      = $portal->fetchDictionaryRoots('MEASURE_UNIT'); // COUNTRY, MEASURE_UNIT, TNVED, …
$children   = $portal->fetchDictionaryChildren('MEASURE_UNIT', (int) $roots[0]['id']);
```

### nct.gov.kz: іздеу және карточка

```php
$gov = $this->nct->gov();

$cards = $gov->searchByGtin($gtin);     // осы GTIN-мен барлық карточкалар, өшірілген дубликаттарды қоса; жоқ болса — []
$card  = $gov->findActiveCard($gtin);   // ntin_isdeactivated === false болған біріншісі; бәрі өшірілген болса — null
$ntin  = $gov->lookupNtin($gtin);       // ['gtin' => …, 'ntin_code' => string|null]; карточка жоқ болса — қате
$full  = $gov->fetchProduct((int) $card['id']);   // GET /api/core/products/{id}; ОКТРУ — category.full_code ішінде
```

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
`X-API-KEY` жіберіледі; nct.gov.kz-ке — тек `Accept`: API кілті жария хостқа кетпейді.

## Эндпоинттер

| Әдіс | HTTP |
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

## Тестілеу

```bash
composer install
vendor/bin/phpunit
```

`NctHttpClientTest` — транспорттың snapshot-тесті (31 сценарий: қайталаулар, `422`, `404`-тің барлық
түрі, таймауттар, қате денелері, хост бойынша тақырыптар); эталон `tests/Http/__snapshots__/` ішінде.
Эталонды саналы түрде қайта жазу: `UPDATE_SNAPSHOTS=1 vendor/bin/phpunit`.

`NctClient`, `NctPortalClient` және `NctGovClient` `final` емес — оларды өз қолданбаңыздың тесттерінде
алмастыруға болады.

## Шекаралар

Бандл портал хаттамасын іске асырады. Ол әдейі өтінімдерді және олардың мәртебелерін сақта**майды**,
идентификаторды тауарға қашан жазуға болатынын шеш**пейді**, сіздің деректер моделіңізден өтінім
денесін құра**майды** және атрибуттар мен анықтамалық жолдарын сіздің анықтамалықтарыңызға
келтір**мейді**. Бұл — қолданбаның шешімдері.

## Лицензия

MIT — [LICENSE](LICENSE) қараңыз.
