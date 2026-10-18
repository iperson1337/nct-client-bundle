# NCT Client Bundle

**English** · [Русский](README.ru.md) · [Қазақша](README.kk.md)

[![CI](https://github.com/iperson1337/nct-client-bundle/actions/workflows/ci.yml/badge.svg)](https://github.com/iperson1337/nct-client-bundle/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/iperson1337/nct-client-bundle.svg)](https://packagist.org/packages/iperson1337/nct-client-bundle)
[![PHP Version](https://img.shields.io/packagist/php-v/iperson1337/nct-client-bundle.svg)](https://packagist.org/packages/iperson1337/nct-client-bundle)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Symfony client for the **National Catalog of Goods of Kazakhstan (НКТ)**: the complete
[nationalcatalog.kz](https://nationalcatalog.kz) portal API (product requests and their lifecycle,
files, duplicates, cards, attributes, dictionaries) and the public [nct.gov.kz](https://nct.gov.kz)
API (search, timestamp export). Ships with retries, Redis-backed rate limiting, typed exceptions and
value objects for the protocol's data.

The bundle knows the portal protocol and nothing about your domain model: responses come back as
decoded arrays (or small value objects), and what to do with them is up to the application.

Coverage: all 23 operations of the official portal specification (`https://nationalcatalog.kz/gwp/docs`)
and all 5 requests of the public nct.gov.kz collection.

## Features

- **Portal API** (`nationalcatalog.kz`, `X-API-KEY`): create, update, delete, cancel, moderate and
  publish product requests; create an edit request for a published card by NTIN; list requests with a
  status filter; read status and details; resolve duplicates; upload photos and PDF documents; look
  cards up by NTIN/GTIN (single and list); base and extended-card attribute definitions; dictionaries
  (list, roots, children, paged items).
- **Public API** (`nct.gov.kz`): search cards by one code, by a batch of up to 100 codes, or by name;
  fetch a card by id; JWT-protected export of changed products by timestamp with a count.
- **One façade** — `NctClient::fetchProductByGtin()` picks the configured lookup source.
- **Rate limiting** — a proactive sliding-window limiter per host (Redis, Lua, shared between
  processes) plus reactive retries after `429` / `422 TOO_MANY_REQUESTS`.
- **Per-call overrides** — short timeout and single attempt for synchronous UI checks.
- **Typed exceptions** — all extend `\RuntimeException` and implement `NctExceptionInterface`.
- **Value objects** — `AttributeDefinition`, `DictionaryItem`, `ProductRequestPayload` (with
  order-insensitive comparison), `RequestStatus` / `ExtendedRequestStatus` / `ImageType` /
  `DuplicateDecision` enums, `RequestDetailsParser`, `NationalCatalogIdentifier`.

## Requirements

- PHP 8.3+
- Symfony 7.4 (`config`, `dependency-injection`, `http-kernel`, `cache`)
- Guzzle 7
- Optional: the `redis` extension or `predis/predis` for the Redis rate limiter

## Installation

```bash
composer require iperson1337/nct-client-bundle
```

Without Symfony Flex, register the bundle:

```php
// config/bundles.php
return [
    // ...
    Iperson1337\NctClientBundle\NctClientBundle::class => ['all' => true],
];
```

## Configuration

`config/packages/iperson1337_nct_client.yaml`:

```yaml
iperson1337_nct_client:
    portal:                                   # nationalcatalog.kz, X-API-KEY auth
        host: 'https://nationalcatalog.kz'
        api_key: '%env(NCT_API_KEY)%'
        timeout: 30                           # response timeout, seconds
        connect_timeout: 5
        retry_count: 3                        # retries after 429, on top of the first attempt
        retry_after_seconds: 30               # pause before a retry after 429
        rate_limit:
            requests: 100                     # at most `requests` calls per `window_seconds`
            window_seconds: 60
            redis_key: 'nct:rate_limit:requests'
    gov:                                      # nct.gov.kz, public
        host: 'https://nct.gov.kz'
        retry_after_seconds: 2                # nct.gov.kz recovers within about a second
        rate_limit:
            requests: 2                       # the public host tolerates bursts of ~3 req/s
            window_seconds: 1
            redis_key: 'nct:gov:rate_limit:requests'
    lookup_source: nctgov                     # product-by-GTIN source: nctgov | nationalcatalog
    redis_dsn: '%env(REDIS_URL)%'             # null disables rate limiting (NullRateLimiter)
```

All keys are optional; the values above are the defaults (except `api_key`, which is empty, and
`redis_dsn`, which is `null`).

| Key | Meaning |
|---|---|
| `portal.host`, `portal.api_key` | portal address and the applicant's API key; every request operation goes here regardless of `lookup_source` |
| `portal.timeout`, `portal.connect_timeout` | default Guzzle timeouts for both hosts; overridable per call with `$timeoutOverride` |
| `portal.retry_count` | how many times to retry after `429` / `422 TOO_MANY_REQUESTS`; `$maxAttempts = 1` on a call disables retries |
| `*.retry_after_seconds` | `sleep()` before a retry, per host |
| `*.rate_limit` | proactive limit checked before each request: a Redis sliding window with its own key per host, so the hosts never throttle each other |
| `lookup_source` | where `NctClient::fetchProductByGtin()` looks: `nctgov` — public search, normalised to `{gtin, ntin_code}`; `nationalcatalog` — `GET /products/{gtin}` on the portal, raw response |
| `redis_dsn` | Redis for the limiters; when Redis is unavailable the limit is skipped with a warning and the request proceeds |

Registered services (autowire by class): `NctClient`, `NctPortalClient`, `NctGovClient`,
`NctHttpClient`, `RequestDetailsParser`, `NationalCatalogIdentifier`.

## Usage

### Product by GTIN

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

            // nct.gov.kz returns `ntin_code`; the portal returns `ntin`
            return $card['ntin'] ?? $card['ntin_code'] ?? null;
        } catch (NctProductNotFoundException) {
            return null; // no card for this GTIN — a regular outcome, not a failure
        }
    }
}
```

A short timeout and a single attempt for a synchronous check in a UI action (don't wait the full
`timeout` and don't sleep on `429`):

```php
$card = $this->nct->fetchProductByGtin($gtin, timeoutOverride: 3.0, maxAttempts: 1);
```

GTIN is not unique on the portal — a GTIN may belong to several cards. To see all of them:

```php
$cards = $this->nct->portal()->fetchProductsByTin('4870001302818');   // GET /v2/products/{tin}
```

### Product request lifecycle

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

$requestId = (int) $portal->submitProductRequest($payload->toArray())['id'];   // POST …/requests → draft

$portal->submitToModeration($requestId);                       // PUT …/{id}/moderation (new, underRevision)
$portal->cancelRequest($requestId);                            // PUT …/{id}/cancel     (onModeration → cancelled)
$portal->updateProductRequest($requestId, $payload->toArray()); // PUT …/{id}            (new, underRevision, cancelled)
$portal->publishRequest($requestId);                           // PUT …/{id}/publish    (readyToPublish)
$portal->deleteProductRequest($requestId);                     // DELETE …/{id}         (new only)
```

The portal rejects an `NTIN` attribute in a *create* request. To edit a **published** card either
pass `ntin` in the payload of `submitProductRequest()` or start from the card itself:

```php
$draft = $portal->createEditRequest('0200212305195');   // POST …/requests/{ntin}/edit-product
$requestId = (int) $draft['productRequestId'];
$body = ProductRequestPayload::fromArray($draft);       // same shape as PUT …/requests/{id}
// `oktru` may come back null for old cards — set a current category code before sending.
$portal->updateProductRequest($requestId, $body->toArray());
```

`name_ru`, `name_kk`, `ntin` and `tnved` cannot be changed on an edit request; extended cards (with a
KZTIN) are not editable through the API.

`ProductRequestPayload` compares bodies regardless of key and attribute order — use it to decide
whether a stored request actually changed before sending a `PUT` that would re-trigger moderation:

```php
if (!$payload->matchesStoredJson($request->getRequestBody())) {
    $portal->updateProductRequest($requestId, $payload->toArray());
}
```

### Request status and details

```php
use Iperson1337\NctClientBundle\Request\RequestDetailsParser;
use Iperson1337\NctClientBundle\Request\RequestStatus;

$status = $portal->fetchRequestStatus($requestId);
// ['code' => 'completed', 'value' => '…', 'ntinCode' => '0200…', 'productUrl' => '…',
//  'comment' => …, 'revisionDetails' => [...], 'nextAction' => [...], 'gzStatus' => [...]]
$code = RequestStatus::tryFrom($status['code']);
if ($code?->isFinal()) { /* completed, rejected or existingProductSelected */ }

$details = $portal->fetchRequestDetails($requestId, timeoutOverride: 3.0, maxAttempts: 1);
$parser  = new RequestDetailsParser();
$ntin    = $parser->extractAttributeValueByCode($details, 'ntin');
$xtin    = $parser->extractAttributeValueByCode($details, 'xtin');
$comment = $parser->extractModeratorComment($details);        // null — absent; '' — present but empty

$page = $portal->fetchProductRequests(page: 1, size: 50, status: RequestStatus::OnModeration);
// ['content' => [...], 'totalElements' => N, 'totalPages' => M]
```

Base statuses (`RequestStatus`): `new`, `onModeration`, `accepted`, `underRevision`, `cancelled`,
`readyToPublish`, `existingProductSelected`, `completed`, `rejected`. Extended-card statuses in
`gzStatus` (`ExtendedRequestStatus`): `acceptedGz`, `underRevisionGz`, `rejectedGz`, `publishingGz`.

### Duplicates

When moderation sends a request back for `DUPLICATE_REVIEW`:

```php
use Iperson1337\NctClientBundle\Portal\DuplicateDecision;

$matches = $portal->fetchDuplicates($requestId);   // orderNumber, name, brand, gtin, ntin, similarity
$portal->decideDuplicates($requestId, DuplicateDecision::UseExisting, $matches[0]['ntin']);
// or: $portal->decideDuplicates($requestId, DuplicateDecision::Continue);
```

### Photos and documents

Files are uploaded to the request first (statuses `new`, `underRevision`, `cancelled`; 5 MB max),
then referenced by `filename` in the payload:

```php
use Iperson1337\NctClientBundle\Portal\ImageType;

$front = $portal->uploadPhoto($requestId, ImageType::Front, '/path/front.jpg');     // POST …/storage/{id}/upload?imageType=front
$back  = $portal->uploadPhoto($requestId, ImageType::Back, fopen('php://memory', 'r+'), 'back.jpg');
$cert  = $portal->uploadDocument($requestId, '/path/certificate.pdf');              // POST …/storage/{id}/upload/document
$portal->deleteFile($requestId, $cert['filename']);                                 // DELETE …/storage/{id}/{fileName}

$payload = new ProductRequestPayload(
    oktru: '10.51.11',
    autoPublication: false,
    attributes: [/* … incl. ['code' => 'brand', 'value' => '…'] */],
    isGov: true,
    mainExtendedAttributes: [['code' => 'gross_weight', 'value' => '1.05']],
    additionalExtendedAttributes: [/* from fetchGovAttributes() */],
    imageFiles: [
        ['fileName' => $front['filename'], 'imageType' => ImageType::Front->value],
        ['fileName' => $back['filename'], 'imageType' => ImageType::Back->value],
    ],
    documents: [['fileName' => $cert['filename']]],
);
```

An extended card (`isGov: true`) needs `front` and `back` photos, the `brand` attribute and at least
five additional characteristics before it can go to moderation.

### Attribute definitions and dictionaries

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

$gov = $portal->fetchGovAttributes('10.51.11');                // mainAttributes + additionalAttributes per ОКТРУ

$dictionaries = $portal->fetchDictionaries();                   // GET …/dictionaries
$roots = $portal->fetchDictionaryRoots('MEASURE_UNIT');         // tree dictionaries
$children = $portal->fetchDictionaryChildren('MEASURE_UNIT', (int) $roots[0]['id']);
$page = $portal->fetchDictionaryItems('TNVED', page: 1, size: 100);   // any dictionary, paged (size 1..100)

foreach ($roots as $row) {
    if (DictionaryItem::missingRequiredField($row) === null) {
        $item = DictionaryItem::fromRow($row);                 // externalId, code, nameRu, nameKk, parentId, level, hasChild, isDeleted
    }
}
```

### nct.gov.kz: search, card, export

```php
$gov = $this->nct->gov();

$cards = $gov->searchByGtin($gtin);                          // every card with this code, incl. deactivated duplicates; [] if none
$cards = $gov->searchByTins(['4870235770032', '0200093179779']);   // up to 100 NTIN/GTIN per request
$page  = $gov->searchByName('Молоко', limit: 100, offset: 0);     // ['count', 'next', 'previous', 'last_modified', 'result' => [...]]
$card  = $gov->findActiveCard($gtin);                        // first card with ntin_isdeactivated === false; null if all are deactivated
$ntin  = $gov->lookupNtin($gtin);                            // ['gtin' => …, 'ntin_code' => string|null]; throws if there are no cards
$full  = $gov->fetchProduct((int) $card['id']);              // GET /api/core/products/{id}; category.full_code holds ОКТРУ
```

Timestamp export needs an nct.gov.kz account (limit: 120 requests per minute):

```php
$auth = $gov->login('username', 'password');                 // POST /api/auth/login/ — raw response with the JWT
$jwt = $auth['access'] ?? $auth['token'];                    // key name depends on the portal's response

$count = $gov->countChangedProducts($jwt, from: 1743596160)['count'];
$page = $gov->exportProducts($jwt, from: 1743596160, limit: 1000, offset: 0);
foreach ($page['result'] as $card) {
    // id, name_ru, name_kk, gtin, ntin_code, is_social, modified, status, is_markedeac, measure,
    // product_type (1 goods, 2 work, 3 service), ntin_isdeactivated, ntin_deactivationreason, ntin_duplicateofproduct
}
// next call: from = the largest `modified` received
```

After several failed logins the portal locks the account for 10 minutes (`403`, `locked_seconds`).

### NTIN / XTIN format

```php
use Iperson1337\NctClientBundle\Identifier\NationalCatalogIdentifier;

$id = new NationalCatalogIdentifier();
$id->isValidNtin('0200212305195');  // true — "02" + 11 digits
$id->isValidXtin('0040212305191');  // true — "004" + 10 digits
$id->normalize('  0200212305195 '); // '0200212305195'; blank → null
```

Identifiers are strings: leading zeros matter.

## Exceptions

Every exception extends `\RuntimeException` and implements `NctExceptionInterface`, so an existing
`catch (\RuntimeException)` keeps working and `catch (NctExceptionInterface)` catches any NCT failure.

| Exception | When |
|---|---|
| `NctProductNotFoundException` | no card for the GTIN (nct.gov.kz answered `404 "product … not found"` or an empty list). The message is stable: `Product with GTIN {gtin} not found in NCT (nct.gov.kz)` |
| `NctRateLimitExceededException` | `429` or `422 TOO_MANY_REQUESTS` and no attempts left (or `maxAttempts: 1`) |
| `NctRequestTimeoutException` | connection timeout (`ConnectException`) or response timeout (cURL 28) |
| `NctTransportException` | `5xx`, network failure, attempts exhausted |
| `NctClientException` | any other `4xx`: invalid request, forbidden, changed API path. The message carries `HTTP {status} {body}` — the full body (capped at 2000 characters, marked when cut), because the body is what explains which attribute was rejected |
| `NctResponseException` | `2xx` whose body is not valid JSON |

A `404` with a `{"Message": "product … not found"}` body counts as "not found" only for requests
that opted in (`NctRequest::$notFoundAsEmpty`): a `404` caused by a changed API path must not look
like a missing product.

## Rate limiting and retries

Two layers protect every request:

1. **Proactive limit** — `RateLimiterInterface::waitIfNeeded()` before the request, one limiter per
   host (`NctTarget::Portal` / `NctTarget::Gov`). The Redis implementation keeps a sorted set of
   timestamps; an atomic Lua script counts requests in the window and, when full, returns the time
   until the oldest one leaves it. If Redis fails, the limit is skipped with a warning — a limiter
   must never stop the host process.
2. **Retry after 429** — up to `retry_count` retries with the host's `retry_after_seconds` pause;
   `maxAttempts: 1` on a call turns retries off and surfaces `NctRateLimitExceededException` at once.

One Guzzle client serves both hosts. Portal requests carry `Content-Type: application/json`,
`Accept` and `X-API-KEY` (multipart uploads let Guzzle set the boundary); nct.gov.kz requests carry
`Accept` only, plus `Authorization: JWT …` for the export — the API key never leaves for the public host.

## Endpoints

| Method | HTTP |
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

## Testing

```bash
composer install
vendor/bin/phpunit
```

`NctHttpClientTest` is a snapshot test of the transport (31 scenarios: retries, `422`, every kind
of `404`, timeouts, error bodies, headers per host); the baseline lives in `tests/Http/__snapshots__/`.
To re-record it deliberately: `UPDATE_SNAPSHOTS=1 vendor/bin/phpunit`. `NctPortalClientTest` and
`NctGovClientTest` pin method, path, query, body and headers of every operation against a Guzzle
`MockHandler`.

`NctClient`, `NctPortalClient` and `NctGovClient` are not `final`, so they can be stubbed in the
consumer's own tests.

## Scope

The bundle implements the portal protocol. It deliberately does **not** store requests or their
statuses, decide when an identifier may be written to a product, build request payloads from a
domain model, or map attribute and dictionary rows into the consumer's own reference data. The
value objects give those rows a predictable shape; the mapping stays in the application.

## License

MIT — see [LICENSE](LICENSE).
