<?php

declare(strict_types=1);

namespace Iperson1337\NctClientBundle\Exception;

/**
 * Портал ответил 2xx, но тело не разбирается как JSON.
 */
final class NctResponseException extends \RuntimeException implements NctExceptionInterface
{
}
