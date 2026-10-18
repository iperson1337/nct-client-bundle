<?php

declare(strict_types=1);

namespace Iperson1337\NctClientBundle\Attribute;

/**
 * Описание атрибута заявки из `GET /products/requests/attributes`, приведённое к предсказуемым типам:
 * тип данных только из известного списка, булевы из строк вида «true»/«1»/«yes», положительная
 * длина, пустые строки — null, варианты словаря — массив или null.
 *
 * Что делать с описанием дальше (хранить, строить форму, проверять значения) — дело потребителя.
 */
final readonly class AttributeDefinition
{
    public const array DATA_TYPES = ['string', 'text', 'number', 'boolean', 'dictionary', 'multiDictionary', 'date'];

    public const array ATTRIBUTE_TYPES = ['standard', 'advanced', 'gov', 'compound', 'compoundStandard'];

    public const array REQUIRED_FIELDS = ['code', 'dataType', 'nameKk', 'nameRu'];

    /**
     * @param value-of<self::DATA_TYPES>           $dataType
     * @param value-of<self::ATTRIBUTE_TYPES>|null $attributeType
     * @param non-empty-array<mixed>|null          $dictionaryValues      варианты словаря как отдал портал
     * @param non-empty-array<mixed>|null          $multiDictionaryValues
     */
    public function __construct(
        public string $code,
        public string $dataType,
        public string $nameKk,
        public string $nameRu,
        public ?string $attributeType = null,
        public ?string $descriptionRu = null,
        public ?string $descriptionKk = null,
        public bool $isRequired = false,
        public bool $isUnique = false,
        public ?int $maxLength = null,
        public ?string $minValue = null,
        public ?string $maxValue = null,
        public ?string $dictionaryCode = null,
        public ?string $pattern = null,
        public ?string $value = null,
        public ?array $dictionaryValues = null,
        public ?array $multiDictionaryValues = null,
    ) {
    }

    /**
     * Обязательные поля, которых в строке портала нет или они пустые.
     *
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    public static function missingRequiredFields(array $row): array
    {
        $missing = [];
        foreach (self::REQUIRED_FIELDS as $field) {
            $value = $row[$field] ?? null;
            if ($value === null || $value === '') {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * @param array<string, mixed> $row строка ответа портала
     *
     * @throws \InvalidArgumentException код пуст после обрезки — см. {@see missingRequiredFields()}
     */
    public static function fromPortalRow(array $row): self
    {
        $code = trim((string) ($row['code'] ?? ''));
        if ($code === '') {
            throw new \InvalidArgumentException('Attribute row has an empty code');
        }

        return new self(
            code: $code,
            dataType: self::normalizeDataType($row['dataType'] ?? null),
            nameKk: trim((string) ($row['nameKk'] ?? '')),
            nameRu: trim((string) ($row['nameRu'] ?? '')),
            attributeType: self::normalizeAttributeType($row['attributeType'] ?? null),
            descriptionRu: self::normalizeString($row['descriptionRu'] ?? null),
            descriptionKk: self::normalizeString($row['descriptionKk'] ?? null),
            isRequired: self::normalizeBoolean($row['isRequired'] ?? false),
            isUnique: self::normalizeBoolean($row['isUnique'] ?? false),
            maxLength: self::normalizePositiveInteger($row['maxLength'] ?? null),
            minValue: self::normalizeString($row['minValue'] ?? null),
            maxValue: self::normalizeString($row['maxValue'] ?? null),
            dictionaryCode: self::normalizeString($row['dictionaryCode'] ?? null),
            pattern: self::normalizeString($row['pattern'] ?? null),
            value: self::normalizeString($row['value'] ?? null),
            dictionaryValues: self::normalizeVariants($row['values'] ?? null),
            multiDictionaryValues: self::normalizeVariants($row['multiValues'] ?? null),
        );
    }

    /** Неизвестный тип данных считается строкой. */
    public static function normalizeDataType(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));

        return \in_array($normalized, self::DATA_TYPES, true) ? $normalized : 'string';
    }

    /** Неизвестный тип атрибута — null. */
    public static function normalizeAttributeType(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = strtolower(trim((string) $value));

        return \in_array($normalized, self::ATTRIBUTE_TYPES, true) ? $normalized : null;
    }

    public static function normalizeString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    /** Строки «true», «1», «yes», «on» (без учёта регистра) — истина; остальное по правилам (bool). */
    public static function normalizeBoolean(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        if (\is_string($value)) {
            return \in_array(strtolower(trim($value)), ['true', '1', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    /** Только положительное целое; строка проверяется через FILTER_VALIDATE_INT. */
    public static function normalizePositiveInteger(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (!\is_string($value)) {
            return null;
        }

        $intValue = filter_var(trim($value), FILTER_VALIDATE_INT);

        return $intValue !== false && $intValue > 0 ? $intValue : null;
    }

    /**
     * @return non-empty-array<mixed>|null
     */
    private static function normalizeVariants(mixed $variants): ?array
    {
        return \is_array($variants) && $variants !== [] ? $variants : null;
    }
}
