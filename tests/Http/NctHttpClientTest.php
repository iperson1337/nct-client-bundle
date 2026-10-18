<?php

declare(strict_types=1);

namespace Iperson1337\NctClientBundle\Tests\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Iperson1337\NctClientBundle\Http\NctHttpClient;
use Iperson1337\NctClientBundle\Http\NctRequest;
use Iperson1337\NctClientBundle\Http\NctTarget;
use Iperson1337\NctClientBundle\RateLimit\RateLimiterInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\AbstractLogger;

/**
 * Транспорт клиента НКТ (все запросы к nationalcatalog.kz и nct.gov.kz): лимитер по цели,
 * повторы на 429 / 422 TOO_MANY_REQUESTS, 404 «not found» как пустой ответ, тело ответа в тексте
 * ошибки (с потолком), таймауты и прочий транспорт, разбор тела ответа, опции Guzzle.
 *
 * Эталон: результат (массив или исключение), опции каждой попытки, след лимитеров и логов в
 * __snapshots__/…json. Перезаписать эталон осознанно: UPDATE_SNAPSHOTS=1.
 *
 */
#[CoversClass(NctHttpClient::class)]
final class NctHttpClientTest extends TestCase
{
    private const string SNAPSHOT = __DIR__ . '/__snapshots__/NctHttpClientTest.json';

    /** @var list<string> */
    private array $trace = [];

    /**
     * 'responses' — очередь ответов MockHandler; 'args' — именованные параметры NctRequest::with().
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function requestScenarios(): iterable
    {
        $json = static fn (int $status, mixed $data): array => ['status' => $status, 'body' => json_encode($data, JSON_UNESCAPED_UNICODE)];

        yield 'GET 200 json' => [['responses' => [$json(200, ['id' => 5, 'name' => 'Молоко'])]]];
        yield 'POST 200 json' => [['method' => 'POST', 'body' => '{"a":1}', 'responses' => [$json(200, ['id' => 7])]]];
        yield 'PUT 200 empty body' => [['method' => 'PUT', 'body' => '{}', 'responses' => [['status' => 200, 'body' => '']]]];
        yield '200 whitespace body' => [['responses' => [['status' => 200, 'body' => "  \n "]]]];
        yield '200 null json' => [['responses' => [['status' => 200, 'body' => 'null']]]];
        yield '200 scalar json' => [['responses' => [['status' => 200, 'body' => '[]']]]];
        yield '200 invalid json' => [['responses' => [['status' => 200, 'body' => '{oops']]]];
        yield 'unsupported method' => [['method' => 'PATCH', 'responses' => []]];
        yield '429 then 200' => [['responses' => [$json(429, ['message' => 'Too many']), $json(200, ['ok' => true])]]];
        yield '429 no message then 200' => [['responses' => [['status' => 429, 'body' => ''], $json(200, ['ok' => true])]]];
        yield '429 until exhausted' => [['responses' => array_fill(0, 4, $json(429, ['message' => 'Too many']))]];
        yield '422 too many requests then 200' => [['responses' => [$json(422, ['code' => 'TOO_MANY_REQUESTS', 'message' => 'slow down']), $json(200, ['ok' => 1])]]];
        yield '422 other code' => [['responses' => [$json(422, ['code' => 'VALIDATION', 'message' => 'bad brand'])]]];
        yield '429 max attempts 1' => [['args' => ['maxAttempts' => 1], 'responses' => [$json(429, [])]]];
        yield 'max attempts 0' => [['args' => ['maxAttempts' => 0], 'responses' => []]];
        yield '404 product not found, as empty' => [['args' => ['notFoundAsEmpty' => true], 'responses' => [$json(404, ['Message' => 'product (tin=123) not found'])]]];
        yield '404 product not found lowercase key, as empty' => [['args' => ['notFoundAsEmpty' => true], 'responses' => [$json(404, ['message' => 'Product NOT FOUND'])]]];
        yield '404 product not found, not as empty' => [['responses' => [$json(404, ['Message' => 'product (tin=123) not found'])]]];
        yield '404 endpoint not found, as empty' => [['args' => ['notFoundAsEmpty' => true], 'responses' => [$json(404, ['Message' => 'endpoint not found'])]]];
        yield '404 empty body, as empty' => [['args' => ['notFoundAsEmpty' => true], 'responses' => [['status' => 404, 'body' => '']]]];
        yield '404 non-json, as empty' => [['args' => ['notFoundAsEmpty' => true], 'responses' => [['status' => 404, 'body' => 'product not found']]]];
        yield '400 long body capped' => [['responses' => [['status' => 400, 'body' => '  ' . str_repeat('ошибка; ', 300) . '  ']]]];
        yield '400 empty body' => [['responses' => [['status' => 400, 'body' => '']]]];
        yield '500 server error' => [['responses' => [['status' => 500, 'body' => 'boom']]]];
        yield 'connect exception' => [['responses' => [['connect' => 'Connection refused']]]];
        yield 'request timeout errno' => [['responses' => [['requestErrno' => CURLE_OPERATION_TIMEDOUT]]]];
        yield 'request other errno' => [['responses' => [['requestErrno' => 6]]]];
        yield 'gov host 429 then 200' => [['args' => ['target' => NctTarget::Gov, 'headers' => ['Accept' => 'application/json']], 'responses' => [$json(429, []), $json(200, ['ok' => 1])]]];
        yield 'gov host default headers' => [['args' => ['target' => NctTarget::Gov], 'responses' => [$json(200, [['gtin' => '1']])]]];
        yield 'gov host timeout override' => [['args' => ['target' => NctTarget::Gov, 'timeoutOverride' => 2.5, 'maxAttempts' => 2], 'responses' => [$json(200, [['gtin' => '1']])]]];
        yield 'custom headers, timeout override' => [['args' => ['headers' => ['X-Test' => '1'], 'timeoutOverride' => 1.0], 'responses' => [$json(200, ['a' => 'b'])]]];
    }

    /**
     * @param array<string, mixed> $case
     *
     */
    #[DataProvider('requestScenarios')]
    public function testSend(array $case): void
    {
        $this->assertSnapshot($this->runRequest($case));
    }

    /**
     * @param array<string, mixed> $actual
     */
    private function assertSnapshot(array $actual): void
    {
        $name = $this->name() . ' | ' . $this->dataName();
        $snapshots = is_file(self::SNAPSHOT) ? json_decode((string) file_get_contents(self::SNAPSHOT), true) : [];
        if (getenv('UPDATE_SNAPSHOTS') === '1') {
            $snapshots[$name] = $actual;
            ksort($snapshots);
            if (!is_dir(\dirname(self::SNAPSHOT))) {
                mkdir(\dirname(self::SNAPSHOT), 0777, true);
            }
            file_put_contents(self::SNAPSHOT, json_encode($snapshots, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            self::markTestSkipped('snapshot updated');
        }

        self::assertArrayHasKey($name, $snapshots, 'Нет эталона — запустить с UPDATE_SNAPSHOTS=1');
        self::assertSame($snapshots[$name], $actual);
    }

    /**
     * @param array<string, mixed> $case
     *
     * @return array<string, mixed>
     */
    private function runRequest(array $case): array
    {
        $this->trace = [];

        $queue = [];
        foreach ($case['responses'] as $response) {
            $queue[] = match (true) {
                isset($response['connect']) => static fn (RequestInterface $request) => new ConnectException($response['connect'], $request),
                isset($response['requestErrno']) => static fn (RequestInterface $request) => new RequestException('cURL error ' . $response['requestErrno'], $request, null, null, ['errno' => $response['requestErrno']]),
                default => new Response($response['status'], [], $response['body']),
            };
        }
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(function (callable $handler) {
            return function (RequestInterface $request, array $options) use ($handler) {
                $this->trace[] = sprintf(
                    'http %s %s timeout=%s headers=%s body=%s',
                    $request->getMethod(),
                    (string) $request->getUri(),
                    var_export($options['timeout'] ?? null, true),
                    json_encode($request->getHeaders()),
                    var_export((string) $request->getBody(), true),
                );

                return $handler($request, $options);
            };
        });

        $client = $this->client(new Client(['handler' => $stack]));
        $request = (new NctRequest($case['method'] ?? 'GET', 'https://nationalcatalog.kz/gwp/portal/api/v1/test', 'testOperation', $case['body'] ?? null))
            ->with(...($case['args'] ?? []));

        try {
            $result = $client->send($request);
        } catch (\Throwable $e) {
            self::assertNotInstanceOf(\PHPUnit\Framework\Exception::class, $e, $e->getMessage());
            $result = ['throws' => $e::class, 'message' => $e->getMessage(), 'previous' => $e->getPrevious() ? $e->getPrevious()::class : null];
        }

        return ['result' => $result, 'trace' => $this->trace];
    }

    private function client(Client $http): NctHttpClient
    {
        $trace = &$this->trace;
        $logger = new class($trace) extends AbstractLogger {
            /** @param list<string> $trace */
            public function __construct(private array &$trace)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->trace[] = sprintf('log.%s %s %s', $level, $message, json_encode($context, JSON_UNESCAPED_UNICODE));
            }
        };

        return new NctHttpClient(
            $http,
            $logger,
            $this->rateLimiter('portal'),
            $this->rateLimiter('gov'),
            retryCount: 3,
            portalRetryAfterSeconds: 0,
            govRetryAfterSeconds: 0,
            portalHeaders: ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-API-KEY' => 'test-key'],
        );
    }

    private function rateLimiter(string $name): RateLimiterInterface
    {
        $trace = &$this->trace;

        return new class($name, $trace) implements RateLimiterInterface {
            /** @param list<string> $trace */
            public function __construct(private readonly string $name, private array &$trace)
            {
            }

            public function waitIfNeeded(): void
            {
                $this->trace[] = 'rateLimiter.' . $this->name;
            }
        };
    }
}
