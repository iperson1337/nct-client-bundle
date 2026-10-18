<?php

declare(strict_types=1);

namespace Iperson1337\NctClientBundle\Identifier;

/**
 * Идентификаторы Национального каталога товаров: NTIN — 13 цифр с префиксом 02, XTIN — 13 цифр с
 * префиксом 004 (временный код до завершения модерации). Значения — строки: ведущие нули значимы.
 */
final class NationalCatalogIdentifier
{
    public const string NTIN_PATTERN = '/^02\d{11}$/';

    public const string XTIN_PATTERN = '/^004\d{10}$/';

    /** Обрезанное значение; пустое после обрезки — null. */
    public function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    public function isValidNtin(?string $value): bool
    {
        $normalized = $this->normalize($value);

        return $normalized !== null && preg_match(self::NTIN_PATTERN, $normalized) === 1;
    }

    public function isValidXtin(?string $value): bool
    {
        $normalized = $this->normalize($value);

        return $normalized !== null && preg_match(self::XTIN_PATTERN, $normalized) === 1;
    }
}
