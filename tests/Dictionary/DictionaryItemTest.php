<?php

declare(strict_types=1);

namespace Iperson1337\NctClientBundle\Tests\Dictionary;

use Iperson1337\NctClientBundle\Dictionary\DictionaryItem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DictionaryItem::class)]
final class DictionaryItemTest extends TestCase
{
    public function testReadsPropertiesOrTopLevelFields(): void
    {
        $nested = DictionaryItem::fromRow(['id' => '15', 'parentId' => 3, 'level' => '2', 'hasChild' => 1, 'properties' => ['code' => '166', 'nameRu' => 'кг', 'nameKk' => 'кг']]);
        $flat = DictionaryItem::fromRow(['id' => 16, 'code' => '796', 'nameRu' => 'шт', 'nameKk' => '', 'isDeleted' => true]);

        self::assertSame(15, $nested->externalId);
        self::assertSame('166', $nested->code);
        self::assertSame(3, $nested->parentId);
        self::assertSame(2, $nested->level);
        self::assertTrue($nested->hasChild);
        self::assertFalse($nested->isDeleted);

        self::assertSame('796', $flat->code);
        self::assertSame('шт', $flat->nameRu);
        self::assertNull($flat->nameKk, 'пустая строка — null');
        self::assertNull($flat->parentId);
        self::assertTrue($flat->isDeleted);
    }

    public function testMissingRequiredField(): void
    {
        self::assertSame('id', DictionaryItem::missingRequiredField(['code' => 'x']));
        self::assertSame('code', DictionaryItem::missingRequiredField(['id' => 1, 'properties' => ['code' => '']]));
        self::assertNull(DictionaryItem::missingRequiredField(['id' => 1, 'code' => 'x']));

        $this->expectException(\InvalidArgumentException::class);
        DictionaryItem::fromRow(['id' => 1]);
    }
}
