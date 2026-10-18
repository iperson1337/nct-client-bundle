<?php

declare(strict_types=1);

namespace Iperson1337\NctClientBundle\Http;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Iperson1337\NctClientBundle\Exception\NctClientException;
use Iperson1337\NctClientBundle\Exception\NctRateLimitExceededException;
use Iperson1337\NctClientBundle\Exception\NctRequestTimeoutException;
use Iperson1337\NctClientBundle\Exception\NctResponseException;
use Iperson1337\NctClientBundle\Exception\NctTransportException;
use Iperson1337\NctClientBundle\RateLimit\RateLimiterInterface;
use Psr\Log\LoggerInterface;

/**
 * Выполнение запросов к НКТ: упреждающий лимит на хост, повтор после 429 / 422 TOO_MANY_REQUESTS
 * с паузой, 404 «… not found» как пустой ответ по запросу, тело ответа портала в тексте ошибки
 * (с потолком длины), разбор JSON. Ошибки — типизированные исключения бандла.
 *
 * Один Guzzle-клиент на оба хоста: таймауты по умолчанию общие, на вызов переопределяются
 * через {@see NctRequest::$timeoutOverride}.
 */
final class NctHttpClient
{
    /** Потолок сохраняемого тела ответа портала — оно оседает в логах и в тексте исключения. */
    public const int ERROR_BODY_MAX_LENGTH = 2000;

    private const array METHODS = ['GET', 'POST', 'PUT', 'DELETE'];

    /**
     * @param array<string, string> $portalHeaders заголовки запросов на портал (Content-Type, Accept, X-API-KEY)
     */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly RateLimiterInterface $portalRateLimiter,
        private readonly RateLimiterInterface $govRateLimiter,
        private readonly int $retryCount,
        private readonly int $portalRetryAfterSeconds,
        private readonly int $govRetryAfterSeconds,
        private readonly array $portalHeaders,
    ) {
    }

    /**
     * @return array<array-key, mixed> разобранный JSON; [] для пустого тела и для «не найдено» при notFoundAsEmpty
     *
     * @throws NctRateLimitExceededException лимит исчерпан и попыток не осталось
     * @throws NctClientException            прочий 4xx
     * @throws NctRequestTimeoutException    таймаут соединения или ответа
     * @throws NctTransportException         5xx, сетевой сбой, исчерпаны попытки
     * @throws NctResponseException          тело 2xx не разбирается как JSON
     */
    public function send(NctRequest $request): array
    {
        $this->rateLimiter($request->target)->waitIfNeeded();

        $retryAfterSeconds = $request->target === NctTarget::Gov ? $this->govRetryAfterSeconds : $this->portalRetryAfterSeconds;
        $maxAttempts = $request->maxAttempts ?? ($this->retryCount + 1);
        $options = $this->guzzleOptions($request);

        for ($attempt = 1; $attempt <= $maxAttempts; ++$attempt) {
            try {
                $response = $this->http->request($this->method($request), $request->url, $options);

                return $this->decode((string) $response->getBody(), $request->operation);
            } catch (ClientException $e) {
                $rateLimit = $this->rateLimitMessage($e);
                if ($rateLimit !== null && $attempt < $maxAttempts) {
                    $this->logger->warning("NCT rate limit exceeded ({$request->operation}), waiting {$retryAfterSeconds} seconds for window reset before retry", [
                        'class' => self::class,
                        'operation' => $request->operation,
                        'attempt' => $attempt,
                        'max_attempts' => $maxAttempts,
                        'target' => $request->target->value,
                        'message' => $rateLimit,
                    ]);

                    sleep($retryAfterSeconds);
                    continue;
                }

                return $this->clientError($e, $request, $rateLimit !== null);
            } catch (GuzzleException $e) {
                throw $this->transportError($e, $request->operation);
            }
        }

        throw new NctTransportException("Не удалось выполнить запрос {$request->operation} после {$maxAttempts} попыток");
    }

    private function rateLimiter(NctTarget $target): RateLimiterInterface
    {
        return $target === NctTarget::Gov ? $this->govRateLimiter : $this->portalRateLimiter;
    }

    private function method(NctRequest $request): string
    {
        if (!\in_array($request->method, self::METHODS, true)) {
            throw new \InvalidArgumentException("Unsupported HTTP method: {$request->method}");
        }

        return $request->method;
    }

    /**
     * @return array<string, mixed>
     */
    private function guzzleOptions(NctRequest $request): array
    {
        $headers = $request->headers ?? $this->defaultHeaders($request->target);
        if ($request->multipart !== null) {
            // Границу multipart выставит Guzzle; заранее заданный Content-Type её бы перебил.
            unset($headers['Content-Type']);
        }
        $options = ['headers' => $headers];

        if ($request->timeoutOverride !== null) {
            $options['timeout'] = $request->timeoutOverride;
        }

        if ($request->multipart !== null) {
            $options['multipart'] = $request->multipart;
        } elseif ($request->body !== null) {
            $options['body'] = $request->body;
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function defaultHeaders(NctTarget $target): array
    {
        return $target === NctTarget::Gov ? ['Accept' => 'application/json'] : $this->portalHeaders;
    }

    /**
     * 429 или 422 с code TOO_MANY_REQUESTS — текст для лога повтора; иначе null.
     */
    private function rateLimitMessage(ClientException $e): ?string
    {
        $statusCode = $e->getResponse()->getStatusCode();
        $responseData = self::decodeErrorBody($e);
        $isRateLimit = $statusCode === 429
            || ($statusCode === 422 && \is_array($responseData) && ($responseData['code'] ?? null) === 'TOO_MANY_REQUESTS');
        if (!$isRateLimit) {
            return null;
        }

        return \is_array($responseData) ? ($responseData['message'] ?? 'Rate limit exceeded') : 'Rate limit exceeded';
    }

    /**
     * Ошибка клиента без права на повтор: лимит исчерпан — NctRateLimitExceededException;
     * 404 «product … not found» при notFoundAsEmpty — пустой ответ; прочее — NctClientException.
     *
     * @return array{}
     */
    private function clientError(ClientException $e, NctRequest $request, bool $isRateLimit): array
    {
        $operation = $request->operation;

        if ($isRateLimit) {
            $this->logger->error("NCT {$operation} rate limit exceeded, no attempts left: " . $e->getMessage(), [
                'class' => self::class,
                'operation' => $operation,
            ]);
            throw new NctRateLimitExceededException("Превышен лимит запросов к NCT порталу ({$operation}): " . $e->getMessage(), 0, $e);
        }

        $statusCode = $e->getResponse()->getStatusCode();
        if ($request->notFoundAsEmpty && $statusCode === 404 && self::isNotFoundResponse(self::decodeErrorBody($e))) {
            return [];
        }

        // В текст идёт тело ответа, а не $e->getMessage(): Guzzle режет тело на 120 символах,
        // и причина отказа портала (какой атрибут отклонён) терялась.
        $responseBody = (string) $e->getResponse()->getBody();
        $detail = $responseBody !== ''
            ? sprintf('HTTP %d %s', $statusCode, self::capResponseBody($responseBody))
            : $e->getMessage();

        $this->logger->error("NCT {$operation} client error: " . $detail, [
            'class' => self::class,
            'operation' => $operation,
            'status_code' => $statusCode,
            'response_body' => self::capResponseBody($responseBody),
        ]);
        throw new NctClientException("Ошибка выполнения запроса {$operation} в NCT портале: " . $detail, 0, $e);
    }

    /**
     * Сбой соединения и таймаут запроса — NctRequestTimeoutException, прочее (в т.ч. 5xx) — NctTransportException.
     */
    private function transportError(GuzzleException $e, string $operation): \RuntimeException
    {
        if ($e instanceof ConnectException) {
            $this->logger->error("NCT {$operation} connect timeout: " . $e->getMessage(), ['class' => self::class, 'operation' => $operation]);

            return new NctRequestTimeoutException("Таймаут соединения с NCT порталом ({$operation}): " . $e->getMessage(), 0, $e);
        }

        if ($e instanceof RequestException && ($e->getHandlerContext()['errno'] ?? null) === CURLE_OPERATION_TIMEDOUT) {
            $this->logger->error("NCT {$operation} request timeout: " . $e->getMessage(), ['class' => self::class, 'operation' => $operation]);

            return new NctRequestTimeoutException("Таймаут запроса к NCT порталу ({$operation}): " . $e->getMessage(), 0, $e);
        }

        $this->logger->error("NCT {$operation} transport error: " . $e->getMessage(), ['class' => self::class, 'operation' => $operation]);

        return new NctTransportException("Ошибка выполнения запроса {$operation} в NCT портале: " . $e->getMessage(), 0, $e);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(string $body, string $operation): array
    {
        if (trim($body) === '') {
            // Пустое тело — штатно для некоторых PUT.
            $this->logger->info("NCT {$operation} returned empty response body", ['class' => self::class, 'operation' => $operation]);

            return [];
        }

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new NctResponseException('NCT response JSON decode error: ' . json_last_error_msg());
        }

        return \is_array($data) ? $data : [];
    }

    private static function decodeErrorBody(ClientException $e): mixed
    {
        $responseBody = (string) $e->getResponse()->getBody();

        return $responseBody !== '' ? json_decode($responseBody, true) : null;
    }

    /**
     * nct.gov.kz: {"Message":"product (tin=…) not found"}. Требуется именно «product … not found»:
     * «endpoint not found» после смены роута не должен сойти за отсутствие товара.
     */
    private static function isNotFoundResponse(mixed $responseData): bool
    {
        if (!\is_array($responseData)) {
            return false;
        }

        $message = $responseData['Message'] ?? $responseData['message'] ?? null;

        return \is_string($message)
            && stripos($message, 'product') !== false
            && stripos($message, 'not found') !== false;
    }

    public static function capResponseBody(string $responseBody): string
    {
        $body = trim($responseBody);
        if (mb_strlen($body) <= self::ERROR_BODY_MAX_LENGTH) {
            return $body;
        }

        return mb_substr($body, 0, self::ERROR_BODY_MAX_LENGTH) . '… [обрезано нами]';
    }
}
