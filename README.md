# NCT Client Bundle

**English** · [Русский](README.ru.md) · [Қазақша](README.kk.md)

[![CI](https://github.com/iperson1337/nct-client-bundle/actions/workflows/ci.yml/badge.svg)](https://github.com/iperson1337/nct-client-bundle/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/iperson1337/nct-client-bundle.svg)](https://packagist.org/packages/iperson1337/nct-client-bundle)
[![PHP Version](https://img.shields.io/packagist/php-v/iperson1337/nct-client-bundle.svg)](https://packagist.org/packages/iperson1337/nct-client-bundle)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Symfony client for the **National Catalog of Goods of Kazakhstan (НКТ)**: the
[nationalcatalog.kz](https://nationalcatalog.kz) portal API (product requests, attributes,
dictionaries) and the public [nct.gov.kz](https://nct.gov.kz) lookups. Ships with retries,
Redis-backed rate limiting, typed exceptions and response parsing.

The bundle knows the portal protocol and nothing about your domain model: responses come back as
decoded arrays, and what to do with them is up to the application.

## Features

- **Portal API** (`nationalcatalog.kz`, `X-API-KEY`): create a product request, send it to
  moderation, update, publish, read status and details, list requests, fetch attribute
  definitions and dictionaries, look a product up by GTIN.
- **Public API** (`nct.gov.kz`, no auth): search cards by GTIN, pick the active card, fetch a card
  by id (includes the ОКТРУ category).
- **One façade** — `NctClient::fetchProductByGtin()` picks the configured lookup source.
- **Rate limiting** — a proactive sliding-window limiter per host (Redis, Lua, shared between
  processes) plus reactive retries after `429` / `422 TOO_MANY_REQUESTS`.
- **Per-call overrides** — short timeout and single attempt for synchronous UI checks.
- **Typed exceptions** — all extend `\RuntimeException` and implement `NctExceptionInterface`.
- **Helpers** — request details parser, NTIN/XTIN format validation.

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

### Product request lifecycle

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

The portal rejects an `NTIN` attribute in a *create* request
(`400 VALIDATION_ERROR: The "NTIN" field must not be filled when creating a new product`).

### Request status and details

```php
use Iperson1337\NctClientBundle\Request\RequestDetailsParser;

$status  = $portal->fetchRequestStatus($requestId);   // ['code' => 'onModeration', 'value' => '…']
$details = $portal->fetchRequestDetails($requestId, timeoutOverride: 3.0, maxAttempts: 1);

$parser  = new RequestDetailsParser();
$code    = $parser->extractStatusCode($details);               // 'completed', 'rejected', …
$ntin    = $parser->extractAttributeValueByCode($details, 'ntin');
$xtin    = $parser->extractAttributeValueByCode($details, 'xtin');
$comment = $parser->extractModeratorComment($details);        // null — absent; '' — present but empty

$page = $portal->fetchProductRequests(page: 1, size: 50);
```

Status codes returned by the portal: `new`, `onModeration`, `accepted`, `underRevision`,
`readyToPublish`, `completed`, `rejected`.

### Attribute definitions and dictionaries

```php
$attributes = $portal->fetchAttributes();                  // code, dataType, isRequired, dictionaryCode, …
$roots      = $portal->fetchDictionaryRoots('MEASURE_UNIT'); // COUNTRY, MEASURE_UNIT, TNVED, …
$children   = $portal->fetchDictionaryChildren('MEASURE_UNIT', (int) $roots[0]['id']);
```

### nct.gov.kz: search and card

```php
$gov = $this->nct->gov();

$cards = $gov->searchByGtin($gtin);     // every card with this GTIN, including deactivated duplicates; [] if none
$card  = $gov->findActiveCard($gtin);   // first card with ntin_isdeactivated === false; null if all are deactivated
$ntin  = $gov->lookupNtin($gtin);       // ['gtin' => …, 'ntin_code' => string|null]; throws if there are no cards
$full  = $gov->fetchProduct((int) $card['id']);   // GET /api/core/products/{id}; category.full_code holds ОКТРУ
```

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
`Accept` and `X-API-KEY`; nct.gov.kz requests carry `Accept` only — the API key never leaves for
the public host.

## Endpoints

| Method | HTTP |
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

## Testing

```bash
composer install
vendor/bin/phpunit
```

`NctHttpClientTest` is a snapshot test of the transport (31 scenarios: retries, `422`, every kind
of `404`, timeouts, error bodies, headers per host); the baseline lives in `tests/Http/__snapshots__/`.
To re-record it deliberately: `UPDATE_SNAPSHOTS=1 vendor/bin/phpunit`.

`NctClient`, `NctPortalClient` and `NctGovClient` are not `final`, so they can be stubbed in the
consumer's own tests.

## Scope

The bundle implements the portal protocol. It deliberately does **not** store requests or their
statuses, decide when an identifier may be written to a product, build request payloads from a
domain model, or normalise attribute and dictionary rows into the consumer's own reference data.
Those decisions belong to the application.

## License

MIT — see [LICENSE](LICENSE).
