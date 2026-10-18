<?php

declare(strict_types=1);

namespace Iperson1337\NctClientBundle\Request;

/**
 * Тело заявки на создание или изменение товара (`POST`/`PUT …/products/requests`): ОКТРУ, флаг
 * автопубликации, атрибуты `{code, value}`; для заявки на редактирование — `ntin` карточки; для
 * расширенной карточки — `isGov`, три группы расширенных характеристик, фотографии и документы.
 *
 * Сравнение тел не зависит от порядка ключей и атрибутов: так решают, изменилась ли заявка с момента
 * последней отправки, и не слать лишний PUT, который снова отправит её на модерацию.
 *
 * @phpstan-type Attribute array{code: string, value?: mixed, nameRu?: string, nameKk?: string, attributes?: list<mixed>, isGov?: bool, is_new?: bool}
 */
final readonly class ProductRequestPayload
{
    private const array ATTRIBUTE_LISTS = ['attributes', 'mainExtendedAttributes', 'additionalExtendedAttributes', 'customExtendedAttributes'];

    /**
     * @param list<Attribute>                              $attributes
     * @param string|null                                  $ntin                         NTIN карточки — заявка на её редактирование
     * @param list<Attribute>                              $mainExtendedAttributes       единый набор ЦЭФ (расширенная карточка)
     * @param list<Attribute>                              $additionalExtendedAttributes категорийные характеристики (`gov-attributes`)
     * @param list<Attribute>                              $customExtendedAttributes     характеристики, заведённые вручную, без кода
     * @param list<array{fileName: string, imageType: string}> $imageFiles               загруженные фото (`filename` из storage)
     * @param list<array{fileName: string}>                $documents                    загруженные PDF
     */
    public function __construct(
        public ?string $oktru,
        public bool $autoPublication,
        public array $attributes = [],
        public ?string $ntin = null,
        public ?bool $isGov = null,
        public array $mainExtendedAttributes = [],
        public array $additionalExtendedAttributes = [],
        public array $customExtendedAttributes = [],
        public array $imageFiles = [],
        public array $documents = [],
    ) {
    }

    /**
     * @param array<string, mixed> $payload массив в форме {@see toArray()}
     */
    public static function fromArray(array $payload): self
    {
        $oktru = $payload['oktru'] ?? null;
        $ntin = $payload['ntin'] ?? null;
        $isGov = $payload['isGov'] ?? null;

        return new self(
            oktru: \is_string($oktru) && $oktru !== '' ? $oktru : null,
            autoPublication: (bool) ($payload['autoPublication'] ?? false),
            attributes: self::attributeList($payload['attributes'] ?? null),
            ntin: \is_string($ntin) && $ntin !== '' ? $ntin : null,
            isGov: \is_bool($isGov) ? $isGov : null,
            mainExtendedAttributes: self::attributeList($payload['mainExtendedAttributes'] ?? null),
            additionalExtendedAttributes: self::attributeList($payload['additionalExtendedAttributes'] ?? null),
            customExtendedAttributes: self::attributeList($payload['customExtendedAttributes'] ?? null),
            imageFiles: self::rowList($payload['imageFiles'] ?? null),
            documents: self::rowList($payload['documents'] ?? null),
        );
    }

    /**
     * Разобранное сохранённое тело; null — пусто или не JSON-объект.
     */
    public static function fromJson(?string $json): ?self
    {
        $json = trim((string) $json);
        if ($json === '') {
            return null;
        }

        $decoded = json_decode($json, true);

        return \is_array($decoded) ? self::fromArray($decoded) : null;
    }

    /**
     * Форма, которую принимает портал: необязательные части попадают в массив, только когда заданы.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [];
        if ($this->oktru !== null) {
            $payload['oktru'] = $this->oktru;
        }
        if ($this->ntin !== null) {
            $payload['ntin'] = $this->ntin;
        }
        $payload['autoPublication'] = $this->autoPublication;
        if ($this->isGov !== null) {
            $payload['isGov'] = $this->isGov;
        }
        foreach (self::ATTRIBUTE_LISTS as $list) {
            if ($this->$list !== []) {
                $payload[$list] = $this->$list;
            }
        }
        if ($this->imageFiles !== []) {
            $payload['imageFiles'] = $this->imageFiles;
        }
        if ($this->documents !== []) {
            $payload['documents'] = $this->documents;
        }

        return $payload;
    }

    public function toJson(): string
    {
        return (string) json_encode($this->toArray(), JSON_UNESCAPED_UNICODE);
    }

    /** Одинаковы ли тела с точностью до порядка ключей и атрибутов. */
    public function equals(self $other): bool
    {
        return self::normalize($this->toArray()) === self::normalize($other->toArray());
    }

    /**
     * Совпадает ли с ранее сохранённым телом; пустое или битое сохранённое тело не совпадает ни с чем.
     */
    public function matchesStoredJson(?string $storedJson): bool
    {
        $stored = self::fromJson($storedJson);

        return $stored !== null && $this->equals($stored);
    }

    /**
     * Каноническая строка для сравнения: списки атрибутов по коду, ключи рекурсивно по алфавиту.
     *
     * @param array<string, mixed> $payload
     */
    public static function normalize(array $payload): string
    {
        foreach (self::ATTRIBUTE_LISTS as $list) {
            if (isset($payload[$list]) && \is_array($payload[$list])) {
                $attributes = $payload[$list];
                usort($attributes, static function (mixed $a, mixed $b): int {
                    $codeA = \is_array($a) ? (string) ($a['code'] ?? '') : '';
                    $codeB = \is_array($b) ? (string) ($b['code'] ?? '') : '';

                    return strcmp($codeA, $codeB);
                });
                $payload[$list] = $attributes;
            }
        }
        self::ksortRecursive($payload);

        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return list<Attribute>
     */
    private static function attributeList(mixed $items): array
    {
        $attributes = [];
        foreach (\is_array($items) ? $items : [] as $attribute) {
            if (\is_array($attribute) && \array_key_exists('code', $attribute)) {
                $attributes[] = ['code' => (string) $attribute['code']] + $attribute;
            }
        }

        return $attributes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rowList(mixed $items): array
    {
        return array_values(array_filter(\is_array($items) ? $items : [], 'is_array'));
    }

    /**
     * @param array<array-key, mixed> $array
     */
    private static function ksortRecursive(array &$array): void
    {
        foreach ($array as &$value) {
            if (\is_array($value)) {
                self::ksortRecursive($value);
            }
        }
        unset($value);
        ksort($array);
    }
}
