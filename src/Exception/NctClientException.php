<?php

declare(strict_types=1);

namespace Iperson1337\NctClientBundle\Exception;

/**
 * Ответ 4xx, который не является лимитом и не «нет такой записи»: невалидная заявка, нет прав,
 * сменился путь API. В сообщении — HTTP-статус и тело ответа портала (с потолком длины):
 * именно тело объясняет, какой атрибут отклонён.
 */
class NctClientException extends \RuntimeException implements NctExceptionInterface
{
}
