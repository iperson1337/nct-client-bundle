<?php

declare(strict_types=1);

namespace Iperson1337\NctClientBundle\Dictionary;

/**
 * Элемент словаря портала (`/dictionaries/{code}/roots`, `/children/{parentId}`): идентификатор и
 * код, названия на двух языках, место в дереве. Названия и код портал отдаёт либо в `properties`,
 * либо на верхнем уровне строки — читаются оба варианта.
 */
final readonly class DictionaryItem
{
    public function __construct(
        public int $externalId,
        public string $code,
        public ?string $nameKk = null,
        public ?string $nameRu = null,
        public ?int $parentId = null,
        public ?int $level = null,
        public bool $hasChild = false,
        public bool $isDeleted = false,
    ) {
    }

    /**
     * Обязательное поле, которого в строке нет: `id` или `code`; null — строка полная.
     *
     * @param array<string, mixed> $row
     *
     * @return 'id'|'code'|null
     */
    public static function missingRequiredField(array $row): ?string
    {
        if (!isset($row['id'])) {
            return 'id';
        }

        $code = self::property($row, 'code');
        if ($code === null || $code === '') {
            return 'code';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row строка ответа портала
     *
     * @throws \InvalidArgumentException нет `id` или `code` — см. {@see missingRequiredField()}
     */
    public static function fromRow(array $row): self
    {
        $missing = self::missingRequiredField($row);
        if ($missing !== null) {
            throw new \InvalidArgumentException(sprintf('Dictionary row has no required field "%s"', $missing));
        }

        return new self(
            externalId: (int) $row['id'],
            code: (string) self::property($row, 'code'),
            nameKk: self::optionalString(self::property($row, 'nameKk')),
            nameRu: self::optionalString(self::property($row, 'nameRu')),
            parentId: isset($row['parentId']) ? (int) $row['parentId'] : null,
            level: isset($row['level']) ? (int) $row['level'] : null,
            hasChild: (bool) ($row['hasChild'] ?? false),
            isDeleted: (bool) ($row['isDeleted'] ?? false),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function property(array $row, string $name): mixed
    {
        $properties = $row['properties'] ?? null;
        if (\is_array($properties) && isset($properties[$name])) {
            return $properties[$name];
        }

        return $row[$name] ?? null;
    }

    private static function optionalString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
