<?php

declare(strict_types=1);

namespace Iperson1337\NctClientBundle\Tests\Portal;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Iperson1337\NctClientBundle\Http\NctHttpClient;
use Iperson1337\NctClientBundle\Portal\DuplicateDecision;
use Iperson1337\NctClientBundle\Portal\ImageType;
use Iperson1337\NctClientBundle\Portal\NctPortalClient;
use Iperson1337\NctClientBundle\RateLimit\NullRateLimiter;
use Iperson1337\NctClientBundle\Request\RequestStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;

/**
 * Каждая операция портала из официальной спецификации: метод, путь, query, тело, заголовки.
 */
#[CoversClass(NctPortalClient::class)]
final class NctPortalClientTest extends TestCase
{
    /** @var list<RequestInterface> */
    private array $requests = [];

    public function testRequestLifecycleEndpoints(): void
    {
        $portal = $this->portal([
            new Response(200, [], '{"id":501}'),
            new Response(200, [], ''),
            new Response(200, [], ''),
            new Response(200, [], ''),
            new Response(200, [], ''),
            new Response(200, [], '{"productRequestId":777,"oktru":null,"attributes":[]}'),
        ]);

        self::assertSame(['id' => 501], $portal->submitProductRequest(['oktru' => '10.1', 'autoPublication' => false, 'attributes' => []]));
        self::assertSame([], $portal->submitToModeration(501));
        self::assertSame([], $portal->cancelRequest(501));
        self::assertSame([], $portal->publishRequest(501));
        self::assertSame([], $portal->deleteProductRequest(501));
        self::assertSame(777, $portal->createEditRequest('0200212305195')['productRequestId']);

        self::assertSame([
            'POST https://nationalcatalog.kz/gwp/portal/api/v1/products/requests',
            'PUT https://nationalcatalog.kz/gwp/portal/api/v1/products/requests/501/moderation',
            'PUT https://nationalcatalog.kz/gwp/portal/api/v1/products/requests/501/cancel',
            'PUT https://nationalcatalog.kz/gwp/portal/api/v1/products/requests/501/publish',
            'DELETE https://nationalcatalog.kz/gwp/portal/api/v1/products/requests/501',
            'POST https://nationalcatalog.kz/gwp/portal/api/v1/products/requests/0200212305195/edit-product',
        ], $this->calls());
        self::assertSame('{"oktru":"10.1","autoPublication":false,"attributes":[]}', (string) $this->requests[0]->getBody());
        self::assertSame('test-key', $this->requests[0]->getHeaderLine('X-API-KEY'));
    }

    public function testListFiltersByStatus(): void
    {
        $portal = $this->portal([new Response(200, [], '{"content":[],"totalElements":0}'), new Response(200, [], '{"content":[]}')]);

        $portal->fetchProductRequests(2, 50, RequestStatus::OnModeration);
        $portal->fetchProductRequests(1, 10);

        self::assertSame([
            'GET https://nationalcatalog.kz/gwp/portal/api/v1/products/requests?page=2&size=50&status=onModeration',
            'GET https://nationalcatalog.kz/gwp/portal/api/v1/products/requests?page=1&size=10',
        ], $this->calls());
    }

    public function testDuplicatesDecisionRequiresNtinForUseExisting(): void
    {
        $portal = $this->portal([
            new Response(200, [], '[{"orderNumber":1,"ntin":"0200000000001","similarity":97.5}]'),
            new Response(200, [], ''),
            new Response(200, [], ''),
        ]);

        self::assertSame(97.5, $portal->fetchDuplicates(501)[0]['similarity']);
        $portal->decideDuplicates(501, DuplicateDecision::UseExisting, '0200000000001');
        $portal->decideDuplicates(501, DuplicateDecision::Continue);

        self::assertSame('{"decision":"USE_EXISTING","ntin":"0200000000001"}', (string) $this->requests[1]->getBody());
        self::assertSame('{"decision":"CONTINUE"}', (string) $this->requests[2]->getBody());

        $this->expectException(\InvalidArgumentException::class);
        $portal->decideDuplicates(501, DuplicateDecision::UseExisting);
    }

    public function testUploadsAreMultipartWithoutJsonContentType(): void
    {
        $portal = $this->portal([
            new Response(200, [], '{"id":9,"filename":"a1b2.jpg","sizeBytes":1024}'),
            new Response(200, [], '{"id":10,"filename":"doc.pdf"}'),
            new Response(200, [], ''),
        ]);
        $photo = tmpfile();
        fwrite($photo, 'jpeg-bytes');
        $pdf = tempnam(sys_get_temp_dir(), 'nct');
        file_put_contents($pdf, '%PDF-1.4');

        self::assertSame('a1b2.jpg', $portal->uploadPhoto(501, ImageType::Front, $photo, 'front.jpg')['filename']);
        self::assertSame('doc.pdf', $portal->uploadDocument(501, $pdf)['filename']);
        $portal->deleteFile(501, 'a1b2.jpg');
        unlink($pdf);

        self::assertSame([
            'POST https://nationalcatalog.kz/gwp/portal/api/v1/storage/501/upload?imageType=front',
            'POST https://nationalcatalog.kz/gwp/portal/api/v1/storage/501/upload/document',
            'DELETE https://nationalcatalog.kz/gwp/portal/api/v1/storage/501/a1b2.jpg',
        ], $this->calls());
        self::assertStringStartsWith('multipart/form-data; boundary=', $this->requests[0]->getHeaderLine('Content-Type'));
        self::assertStringContainsString('filename="front.jpg"', (string) $this->requests[0]->getBody());
        self::assertStringContainsString('jpeg-bytes', (string) $this->requests[0]->getBody());
        self::assertStringContainsString('filename="' . basename($pdf) . '"', (string) $this->requests[1]->getBody());
        self::assertSame('test-key', $this->requests[0]->getHeaderLine('X-API-KEY'));
    }

    public function testCardsAttributesAndDictionaries(): void
    {
        $portal = $this->portal([
            new Response(200, [], '{"id":1,"ntin":"0200212305195"}'),
            new Response(200, [], '[{"id":1,"gtin":"4870001302818"},{"id":2,"gtin":"4870001302818"}]'),
            new Response(200, [], '{"id":3,"ntin":"0200000000003"}'),
            new Response(200, [], '{"mainAttributes":[{"code":"gross_weight"}],"additionalAttributes":[]}'),
            new Response(200, [], '[{"code":"OKTRU"},{"code":"TNVED"}]'),
            new Response(200, [], '{"content":[{"id":5}],"totalElements":1,"totalPages":1}'),
        ]);

        self::assertSame('0200212305195', $portal->fetchProductByGtin('4870001302818')['ntin']);
        self::assertCount(2, $portal->fetchProductsByTin('4870001302818'));
        self::assertSame([['id' => 3, 'ntin' => '0200000000003']], $portal->fetchProductsByTin('0200000000003'), 'одиночный объект оборачивается в список');
        self::assertSame('gross_weight', $portal->fetchGovAttributes('10.51.11')['mainAttributes'][0]['code']);
        self::assertSame('TNVED', $portal->fetchDictionaries()[1]['code']);
        self::assertSame(1, $portal->fetchDictionaryItems('TNVED', 1, 50)['totalElements']);

        self::assertSame([
            'GET https://nationalcatalog.kz/gwp/portal/api/v1/products/4870001302818',
            'GET https://nationalcatalog.kz/gwp/portal/api/v2/products/4870001302818',
            'GET https://nationalcatalog.kz/gwp/portal/api/v2/products/0200000000003',
            'GET https://nationalcatalog.kz/gwp/portal/api/v1/products/requests/categories/10.51.11/gov-attributes',
            'GET https://nationalcatalog.kz/gwp/portal/api/v1/dictionaries',
            'GET https://nationalcatalog.kz/gwp/portal/api/v1/dictionaries/TNVED/items?page=1&size=50',
        ], $this->calls());
    }

    public function testDictionaryItemsPageBounds(): void
    {
        $portal = $this->portal([]);

        $this->expectException(\InvalidArgumentException::class);
        $portal->fetchDictionaryItems('TNVED', 1, 101);
    }

    /**
     * @return list<string>
     */
    private function calls(): array
    {
        return array_map(static fn (RequestInterface $r): string => $r->getMethod() . ' ' . $r->getUri(), $this->requests);
    }

    /**
     * @param list<Response> $responses
     */
    private function portal(array $responses): NctPortalClient
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

        return new NctPortalClient($http, 'https://nationalcatalog.kz');
    }
}
