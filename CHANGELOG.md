# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to
[Semantic Versioning](https://semver.org/).

## [1.1.0] — 2026-10-19

Full coverage of the official portal specification (`https://nationalcatalog.kz/gwp/docs`) and of the
public nct.gov.kz collection.

### Added

- `NctPortalClient`: `deleteProductRequest()`, `cancelRequest()`, `createEditRequest()` (edit an
  existing card by NTIN), `fetchDuplicates()` / `decideDuplicates()` (DUPLICATE_REVIEW), `uploadPhoto()` /
  `uploadDocument()` / `deleteFile()` (multipart storage endpoints), `fetchProductsByTin()` (`/v2/products/{tin}`,
  every card behind a GTIN), `fetchGovAttributes()` (extended-card attributes per ОКТРУ), `fetchDictionaries()`,
  `fetchDictionaryItems()` (paged); `fetchProductRequests()` accepts a `status` filter.
- `NctGovClient`: `searchByTins()` (up to 100 codes per request), `searchByName()` (paged), `login()`,
  `exportProducts()` and `countChangedProducts()` (JWT-protected timestamp export).
- Value objects for protocol-shaped data: `Attribute\AttributeDefinition` (portal attribute row with
  normalised types), `Dictionary\DictionaryItem`, `Request\ProductRequestPayload` (request body with
  order-insensitive comparison, extended-card parts included).
- Enums: `Request\RequestStatus` (all base statuses, `isFinal()`, `isEditable()`), `Request\ExtendedRequestStatus`
  (`gzStatus` codes), `Portal\ImageType`, `Portal\DuplicateDecision`.
- Transport: `DELETE` method and multipart bodies (`NctRequest::$multipart`, `NctRequest::delete()`).

### Changed

- `NctRequest::post()` body is optional (endpoints without a body).

## [1.0.0] — 2026-10-19

Initial release: portal transport with per-host rate limiting and retries, portal and nct.gov.kz clients,
typed exceptions, request details parser, NTIN/XTIN validation.

[1.1.0]: https://github.com/iperson1337/nct-client-bundle/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/iperson1337/nct-client-bundle/releases/tag/v1.0.0
