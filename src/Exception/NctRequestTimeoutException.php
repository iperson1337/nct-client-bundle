<?php

declare(strict_types=1);

namespace Iperson1337\NctClientBundle\Exception;

/**
 * Таймаут соединения или ответа. Отдельный тип, потому что вызывающий код реагирует иначе:
 * живая проверка в клике интерфейса показывает «НКТ не отвечает», а не «ошибка запроса».
 */
final class NctRequestTimeoutException extends \RuntimeException implements NctExceptionInterface
{
}
