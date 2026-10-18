<?php

declare(strict_types=1);

namespace Iperson1337\NctClientBundle\Request;

/**
 * Разбор ответа GET /gwp/portal/api/v1/products/requests/{id}/details: статус, значение атрибута
 * по коду, комментарий модератора. Форма ответа портала — единственное знание этого класса.
 */
final class RequestDetailsParser
{
    /**
     * @param array<string, mixed> $details
     */
    public function extractStatusCode(array $details): ?string
    {
        $status = $details['status'] ?? null;
        if (!\is_array($status)) {
            return null;
        }

        $code = $status['code'] ?? null;
        if (!\is_string($code)) {
            return null;
        }

        $code = trim($code);

        return $code === '' ? null : $code;
    }

    /**
     * Значение атрибута заявки по коду (например `gtin`, `ntin`); пустое — null.
     *
     * @param array<string, mixed> $details
     */
    public function extractAttributeValueByCode(array $details, string $code): ?string
    {
        $attributes = $details['attributes'] ?? null;
        if (!\is_array($attributes)) {
            return null;
        }

        foreach ($attributes as $attribute) {
            if (!\is_array($attribute) || ($attribute['code'] ?? '') !== $code) {
                continue;
            }
            if (!\array_key_exists('value', $attribute)) {
                return null;
            }

            $value = $attribute['value'];
            if (!\is_string($value) && !is_numeric($value)) {
                return null;
            }
            $trimmed = trim((string) $value);

            return $trimmed === '' ? null : $trimmed;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $details
     *
     * @return string|null null — поля нет; пустая строка — поле есть, но пустое
     */
    public function extractModeratorComment(array $details): ?string
    {
        if (!\array_key_exists('moderatorComment', $details)) {
            return null;
        }

        return trim((string) ($details['moderatorComment'] ?? ''));
    }
}
