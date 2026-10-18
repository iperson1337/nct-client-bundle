<?php

declare(strict_types=1);

namespace Iperson1337\NctClientBundle\Tests\Gov;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Iperson1337\NctClientBundle\Gov\NctGovClient;
use Iperson1337\NctClientBundle\Http\NctHttpClient;
use Iperson1337\NctClientBundle\RateLimit\NullRateLimiter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;

/**
 * Операции nct.gov.kz из Postman-документации «API для выгрузки товаров из НКТ»: поиск по кодам
 * (до 100 за запрос) и по названию без авторизации, выгрузка и подсчёт изменений по JWT.
 */
#[CoversClass(NctGovClient::class)]
final class NctGovClientTest extends TestCase
{
    /** @var list<RequestInterface> */
    private array $requests = [];

    public function testSearchByTinsSendsCommaSeparatedCodesWithoutApiKey(): void
    {
        $gov = $this->gov([new Response(200, [], '[{"id":1,"gtin":"4870235770032"},{"id":2,"ntin_code":"0200093179779"}]')]);

        self::assertCount(2, $gov->searchByTins([' 4870235770032 ', '0200093179779', '']));

        self::assertSame('GET https://nct.gov.kz/api/integration/ofd/search_ofd/?tin=4870235770032%2C0200093179779', $this->call(0));
        self::assertSame('', $this->requests[0]->getHeaderLine('X-API-KEY'));
        self::assertSame('application/json', $this->requests[0]->getHeaderLine('Accept'));
    }

    public function testSearchByTinsRejectsEmptyAndOversizedBatches(): void
    {
        $gov = $this->gov([]);

        try {
            $gov->searchByTins(array_fill(0, 101, '4870235770032'));
            self::fail('101 код должен быть отвергнут');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\InvalidArgumentException::class);
        $gov->searchByTins(['', '  ']);
    }

    public function testSearchByNameIsPaginated(): void
    {
        $gov = $this->gov([new Response(200, [], '{"count":2,"next":null,"previous":null,"last_modified":null,"result":[{"id":1},{"id":2}]}')]);

        $page = $gov->searchByName('Молоко', 50, 100);

        self::assertSame(2, $page['count']);
        self::assertSame('GET https://nct.gov.kz/api/integration/ofd/search_ofd/?q=%D0%9C%D0%BE%D0%BB%D0%BE%D0%BA%D0%BE&limit=50&offset=100', $this->call(0));
    }

    public function testExportUsesJwtAuthorizationAndTimestampCursor(): void
    {
        $gov = $this->gov([
            new Response(200, [], '{"access":"tok"}'),
            new Response(200, [], '{"count":1,"result":[{"id":1,"modified":1753691677}]}'),
            new Response(200, [], '{"count":1}'),
        ]);

        self::assertSame('tok', $gov->login('user', 'secret')['access']);
        self::assertSame(1753691677, $gov->exportProducts('tok', 1743596160, 500, 1000)['result'][0]['modified']);
        self::assertSame(1, $gov->countChangedProducts('tok', 1743596160)['count']);

        self::assertSame('POST https://nct.gov.kz/api/auth/login/', $this->call(0));
        self::assertSame('{"username":"user","password":"secret"}', (string) $this->requests[0]->getBody());
        self::assertSame('application/json', $this->requests[0]->getHeaderLine('Content-Type'));
        self::assertSame('GET https://nct.gov.kz/api/integration/ofd/ofd_product/?from=1743596160&limit=500&offset=1000', $this->call(1));
        self::assertSame('JWT tok', $this->requests[1]->getHeaderLine('Authorization'));
        self::assertSame('GET https://nct.gov.kz/api/integration/ofd/ofd_product_count/?from=1743596160', $this->call(2));
        self::assertSame('JWT tok', $this->requests[2]->getHeaderLine('Authorization'));
    }

    private function call(int $index): string
    {
        return $this->requests[$index]->getMethod() . ' ' . $this->requests[$index]->getUri();
    }

    /**
     * @param list<Response> $responses
     */
    private function gov(array $responses): NctGovClient
    {
        $this->requests = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(function (callable $handler) {
            return function (RequestInterface $request, array $options) use ($handler) {
                $this->requests[] = $request;

                return $handler($request, $options);
            };
        });

        $http = new NctHttpClient(
            new Client(['handler' => $stack]),
            new NullLogger(),
            new NullRateLimiter(),
            new NullRateLimiter(),
            retryCount: 0,
            portalRetryAfterSeconds: 0,
            govRetryAfterSeconds: 0,
            portalHeaders: ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-API-KEY' => 'test-key'],
        );

        return new NctGovClient($http, 'https://nct.gov.kz');
    }
}
