<?php

declare(strict_types=1);

namespace Iperson1337\NctClientBundle\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Iperson1337\NctClientBundle\Exception\NctClientException;
use Iperson1337\NctClientBundle\Exception\NctProductNotFoundException;
use Iperson1337\NctClientBundle\Exception\NctRateLimitExceededException;
use Iperson1337\NctClientBundle\Exception\NctRequestTimeoutException;
use Iperson1337\NctClientBundle\Exception\NctTransportException;
use Iperson1337\NctClientBundle\Gov\NctGovClient;
use Iperson1337\NctClientBundle\GtinLookupSource;
use Iperson1337\NctClientBundle\Http\NctHttpClient;
use Iperson1337\NctClientBundle\NctClient;
use Iperson1337\NctClientBundle\Portal\NctPortalClient;
use Iperson1337\NctClientBundle\RateLimit\NullRateLimiter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Фасад и клиенты хостов поверх транспорта: короткий таймаут и число попыток на вызов, типы
 * ошибок (лимит / таймаут / транспорт / отказ портала), источник карточки по GTIN, nct.gov.kz:
 * 404 «product … not found» как «карточки нет» без ERROR в логе, выбор действующей карточки среди
 * деактивированных, полное тело ответа портала в тексте ошибки с потолком длины.
 *
 */
#[CoversClass(NctClient::class)]
#[CoversClass(NctPortalClient::class)]
#[CoversClass(NctGovClient::class)]
final class NctClientTest extends TestCase
{
    /** @var array<string, mixed> опции Guzzle последнего запроса */
    private array $captured = [];

    /** @var list<string> URL запросов по порядку */
    private array $urls = [];

    /** @var list<string> тела запросов по порядку */
    private array $bodies = [];

    public function testTimeoutOverrideIsPassedThroughToGuzzleOptions(): void
    {
        $client = $this->client([new Response(200, [], json_encode(['status' => ['code' => 'new']]))]);

        $client->portal()->fetchRequestDetails(123, 5.0, 1);

        self::assertSame(5.0, $this->captured['timeout']);
        self::assertSame('https://nationalcatalog.kz/gwp/portal/api/v1/products/requests/123/details', $this->urls[0]);
    }

    public function testNoOverrideDoesNotSetPerCallTimeoutOption(): void
    {
        $client = $this->client([new Response(200, [], json_encode(['status' => ['code' => 'new']]))]);

        $client->portal()->fetchRequestDetails(123);

        self::assertArrayNotHasKey('timeout', $this->captured);
    }

    public function testMaxAttemptsOfOneThrowsRateLimitExceptionImmediatelyOnFirst429(): void
    {
        $client = $this->client([new Response(429, [], json_encode(['message' => 'Too many requests']))]);

        $this->expectException(NctRateLimitExceededException::class);

        $client->portal()->fetchRequestDetails(123, null, 1);
    }

    public function testWithoutMaxAttemptsDefaultRetryCountIsUsed(): void
    {
        // retryCount=3 → 4 попытки; четыре 429 подряд — лимит исчерпан
        $client = $this->client(array_fill(0, 4, new Response(429, [], '{}')));

        $this->expectException(NctRateLimitExceededException::class);

        $client->portal()->fetchRequestDetails(123);
    }

    public function testConnectExceptionIsCategorizedAsRequestTimeout(): void
    {
        $client = $this->client([new ConnectException('Connection timed out', new Request('GET', 'test'))]);

        $this->expectException(NctRequestTimeoutException::class);

        $client->portal()->fetchRequestDetails(123, 5.0, 1);
    }

    public function testServerErrorResponseIsCategorizedAsTransportException(): void
    {
        $client = $this->client([new Response(500, [], 'Internal Server Error')]);

        $this->expectException(NctTransportException::class);

        $client->portal()->fetchRequestDetails(123, 5.0, 1);
    }

    public function testNonRateLimitClientErrorIsClientExceptionWithRuntimeAncestor(): void
    {
        $client = $this->client([new Response(404, [], 'Not Found')]);

        try {
            $client->portal()->fetchRequestDetails(123, 5.0, 1);
            self::fail('Ожидалось исключение на 404 без тела «not found»');
        } catch (NctClientException $e) {
            self::assertInstanceOf(\RuntimeException::class, $e);
            self::assertMatchesRegularExpression('/Ошибка выполнения запроса/', $e->getMessage());
        }
    }

    public function testSuccessfulResponseIsDecodedAndReturned(): void
    {
        $client = $this->client([new Response(200, [], json_encode(['status' => ['code' => 'completed'], 'moderatorComment' => null]))]);

        $result = $client->portal()->fetchRequestDetails(123, 5.0, 1);

        self::assertSame('completed', $result['status']['code']);
    }

    public function testPortalLookupSourceQueriesPortalProductEndpointWithOverrides(): void
    {
        $client = $this->client([new Response(200, [], json_encode(['ntin_code' => '0234567890123']))], GtinLookupSource::Portal);

        $result = $client->fetchProductByGtin('4870123456789', 5.0, 1);

        self::assertSame('0234567890123', $result['ntin_code']);
        self::assertSame(5.0, $this->captured['timeout']);
        self::assertSame('https://nationalcatalog.kz/gwp/portal/api/v1/products/4870123456789', $this->urls[0]);
    }

    public function testPortalLookupMaxAttemptsOfOneThrowsRateLimitExceptionImmediately(): void
    {
        $client = $this->client([new Response(429, [], '{}')], GtinLookupSource::Portal);

        $this->expectException(NctRateLimitExceededException::class);

        $client->fetchProductByGtin('4870123456789', null, 1);
    }

    /**
     * nct.gov.kz на неизвестный GTIN отвечает 404 {"Message":"product (tin=…) not found"}. Это штатное
     * «карточки нет»: исключение несёт фразу "Product with GTIN", по которой ночная сверка и approve
     * уходят в ветку «нет в НКТ», и не пишет ERROR в лог.
     */
    public function testGovNotFoundResponseIsProductNotFoundWithoutErrorLog(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $client = $this->client(
            [new Response(404, ['Content-Type' => 'application/json'], '{"Message":"product (tin=2000003003495) not found"}')],
            logger: $logger,
        );

        try {
            $client->fetchProductByGtin('2000003003495', null, 1);
            self::fail('Ожидалось NctProductNotFoundException');
        } catch (NctProductNotFoundException $e) {
            self::assertInstanceOf(\RuntimeException::class, $e);
            self::assertStringContainsString('Product with GTIN 2000003003495 not found', $e->getMessage());
        }
        self::assertSame('https://nct.gov.kz/api/integration/ofd/search_ofd/?tin=2000003003495', $this->urls[0]);
    }

    /**
     * 404 без JSON «not found» (сменился путь API, HTML балансировщика) — сбой, а не «карточки нет»:
     * иначе ночная сверка молча посчитала бы каталог отсутствующим в НКТ и пошла создавать заявки.
     */
    public function testGovBare404IsClientError(): void
    {
        $client = $this->client([new Response(404, [], '<html>Not Found</html>')]);

        $this->expectException(NctClientException::class);
        $this->expectExceptionMessageMatches('/Ошибка выполнения запроса/');

        $client->fetchProductByGtin('4870123456789', null, 1);
    }

    public function testGov404WithForeignNotFoundMessageIsClientError(): void
    {
        $client = $this->client([new Response(404, ['Content-Type' => 'application/json'], '{"Message":"endpoint not found"}')]);

        $this->expectException(NctClientException::class);

        $client->fetchProductByGtin('4870123456789', null, 1);
    }

    public function testGovLookupReturnsActiveCardNtin(): void
    {
        $client = $this->client([new Response(200, [], json_encode([
            ['gtin' => '4870123456789', 'ntin_code' => '0200000000001', 'ntin_isdeactivated' => true],
            ['gtin' => '4870123456789', 'ntin_code' => '0200000000002', 'ntin_isdeactivated' => false],
        ]))]);

        self::assertSame(['gtin' => '4870123456789', 'ntin_code' => '0200000000002'], $client->fetchProductByGtin('4870123456789', null, 1));
    }

    public function testGovLookupWithOnlyDeactivatedCardsHasNoNtin(): void
    {
        $client = $this->client([new Response(200, [], json_encode([
            ['gtin' => '4870123456789', 'ntin_code' => '0200000000001', 'ntin_isdeactivated' => true],
        ]))]);

        self::assertSame(['gtin' => '4870123456789', 'ntin_code' => null], $client->fetchProductByGtin('4870123456789', null, 1));
    }

    public function testGovFetchProductUsesCoreEndpointAndEmptyOnNotFound(): void
    {
        $client = $this->client([
            new Response(200, [], json_encode(['id' => 77, 'category' => ['full_code' => '10.51.11']])),
            new Response(404, ['Content-Type' => 'application/json'], '{"Message":"product not found"}'),
        ]);

        self::assertSame('10.51.11', $client->gov()->fetchProduct(77)['category']['full_code']);
        self::assertSame([], $client->gov()->fetchProduct(78));
        self::assertSame('https://nct.gov.kz/api/core/products/77', $this->urls[0]);
    }

    /**
     * Причина отказа портала доезжает до вызывающего кода целиком: текст Guzzle-исключения обрезает
     * тело на 120 символах, и в errorMessage заявки оседало «...cannot be chan (truncated...)».
     */
    public function testClientErrorCarriesFullResponseBodyNotTruncatedGuzzleText(): void
    {
        $body = json_encode([
            'code' => 'VALIDATION_ERROR',
            'message' => "Field 'Полное наименование товара (рус)' cannot be changed for an existing product card; "
                . 'use the update request instead of creating a new one.',
        ], JSON_UNESCAPED_UNICODE);
        $client = $this->client([new Response(400, [], $body)]);

        try {
            $client->portal()->updateProductRequest(123, ['gtin' => '4870123456789']);
            self::fail('Ожидалось NctClientException на 400 от портала');
        } catch (NctClientException $e) {
            self::assertStringContainsString('HTTP 400', $e->getMessage());
            self::assertStringContainsString('use the update request instead', $e->getMessage());
            self::assertStringNotContainsString('(truncated...)', $e->getMessage());
        }
    }

    public function testOverlongResponseBodyIsCappedWithExplicitMarker(): void
    {
        $client = $this->client([new Response(422, [], str_repeat('a', 2500))]);

        try {
            $client->portal()->updateProductRequest(123, ['gtin' => '4870123456789']);
            self::fail('Ожидалось NctClientException на 422 от портала');
        } catch (NctClientException $e) {
            self::assertStringContainsString('[обрезано нами]', $e->getMessage());
            self::assertLessThan(2300, mb_strlen($e->getMessage()));
        }
    }

    public function testSubmitSendsJsonBodyToRequestsEndpoint(): void
    {
        $client = $this->client([new Response(200, [], json_encode(['id' => 1]))]);

        self::assertSame(['id' => 1], $client->portal()->submitProductRequest(['oktru' => '10.1', 'attributes' => []]));
        self::assertSame('https://nationalcatalog.kz/gwp/portal/api/v1/products/requests', $this->urls[0]);
        self::assertSame('{"oktru":"10.1","attributes":[]}', $this->bodies[0]);
    }

    /**
     * @param list<Response|\Throwable> $responses
     */
    private function client(array $responses, GtinLookupSource $lookupSource = GtinLookupSource::NctGov, ?LoggerInterface $logger = null): NctClient
    {
        $this->captured = [];
        $this->urls = [];
        $this->bodies = [];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(function (callable $handler) {
            return function ($request, array $options) use ($handler) {
                $this->captured = $options;
                $this->urls[] = (string) $request->getUri();
                $this->bodies[] = (string) $request->getBody();

                return $handler($request, $options);
            };
        });

        $http = new NctHttpClient(
            new Client(['handler' => $stack]),
            $logger ?? new NullLogger(),
            new NullRateLimiter(),
            new NullRateLimiter(),
            retryCount: 3,
            portalRetryAfterSeconds: 0,
            govRetryAfterSeconds: 0,
            portalHeaders: ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-API-KEY' => 'test-key'],
        );

        return new NctClient(
            new NctPortalClient($http, 'https://nationalcatalog.kz'),
            new NctGovClient($http, 'https://nct.gov.kz'),
            $lookupSource->value,
        );
    }
}
