<?php

declare(strict_types=1);

namespace Iperson1337\NctClientBundle\Exception;

/**
 * Сбой на уровне HTTP, не являющийся ответом портала по существу: 5xx, обрыв соединения,
 * сетевая ошибка Guzzle. Повтор запроса имеет смысл.
 */
class NctTransportException extends \RuntimeException implements NctExceptionInterface
{
}
