<?php

declare(strict_types=1);

namespace Iperson1337\NctClientBundle\Exception;

/**
 * Карточки с таким GTIN в НКТ нет. Это штатный ответ, а не сбой: вызывающий код уходит в ветку
 * «подать заявку» или «очистить коды».
 *
 * Текст сообщения намеренно стабилен — «Product with GTIN {gtin} not found …»: по нему исторически
 * различают «нет в НКТ» и прочие ошибки, не ломайте его.
 */
final class NctProductNotFoundException extends \RuntimeException implements NctExceptionInterface
{
    public static function forGtin(string $gtin, string $source): self
    {
        return new self(sprintf('Product with GTIN %s not found in NCT (%s)', $gtin, $source));
    }
}
