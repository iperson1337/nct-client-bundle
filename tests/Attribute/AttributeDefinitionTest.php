<?php

declare(strict_types=1);

namespace Iperson1337\NctClientBundle\Tests\Attribute;

use Iperson1337\NctClientBundle\Attribute\AttributeDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AttributeDefinition::class)]
final class AttributeDefinitionTest extends TestCase
{
    public function testNormalizesScalarsFromPortalRow(): void
    {
        $definition = AttributeDefinition::fromPortalRow([
            'code' => ' gtin ',
            'dataType' => ' Number ',
            'nameKk' => 'Штрихкод',
            'nameRu' => '  Штрихкод ',
            'attributeType' => 'GOV',
            'isRequired' => 'yes',
            'isUnique' => 'нет',
            'maxLength' => ' 14 ',
            'minValue' => '   ',
            'pattern' => '^\d+$',
            'values' => [['code' => 'kg']],
            'multiValues' => [],
        ]);

        self::assertSame('gtin', $definition->code);
        self::assertSame('number', $definition->dataType);
        self::assertSame('Штрихкод', $definition->nameRu);
        self::assertSame('gov', $definition->attributeType);
        self::assertTrue($definition->isRequired);
        self::assertFalse($definition->isUnique);
        self::assertSame(14, $definition->maxLength);
        self::assertNull($definition->minValue);
        self::assertSame('^\d+$', $definition->pattern);
        self::assertSame([['code' => 'kg']], $definition->dictionaryValues);
        self::assertNull($definition->multiDictionaryValues);
    }

    public function testUnknownTypesFallBack(): void
    {
        $definition = AttributeDefinition::fromPortalRow(['code' => 'x', 'dataType' => 'uuid', 'attributeType' => 'weird']);

        self::assertSame('string', $definition->dataType);
        self::assertNull($definition->attributeType);
    }

    public function testMaxLengthMustBePositiveInteger(): void
    {
        foreach ([0, -3, '0', '12.5', 'abc', '', null] as $maxLength) {
            self::assertNull(AttributeDefinition::fromPortalRow(['code' => 'x', 'maxLength' => $maxLength])->maxLength, var_export($maxLength, true));
        }
        self::assertSame(7, AttributeDefinition::fromPortalRow(['code' => 'x', 'maxLength' => 7])->maxLength);
    }

    public function testMissingRequiredFieldsAndEmptyCode(): void
    {
        self::assertSame(['dataType', 'nameKk'], AttributeDefinition::missingRequiredFields(['code' => 'x', 'dataType' => '', 'nameRu' => 'Имя']));
        self::assertSame([], AttributeDefinition::missingRequiredFields(['code' => 'x', 'dataType' => 'string', 'nameKk' => 'a', 'nameRu' => 'b']));

        $this->expectException(\InvalidArgumentException::class);
        AttributeDefinition::fromPortalRow(['code' => '   ']);
    }
}
