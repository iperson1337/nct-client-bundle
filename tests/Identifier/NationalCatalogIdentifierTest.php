<?php

declare(strict_types=1);

namespace Iperson1337\NctClientBundle\Tests\Identifier;

use Iperson1337\NctClientBundle\Identifier\NationalCatalogIdentifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Формат идентификаторов НКТ: NTIN — 13 цифр с префиксом 02, XTIN — 13 цифр с префиксом 004;
 * ведущие нули значимы, значения — строки.
 *
 */
#[CoversClass(NationalCatalogIdentifier::class)]
final class NationalCatalogIdentifierTest extends TestCase
{
    private NationalCatalogIdentifier $identifier;

    protected function setUp(): void
    {
        $this->identifier = new NationalCatalogIdentifier();
    }

    public function testNormalizeTrimsAndTurnsBlankIntoNull(): void
    {
        self::assertSame('0200212305195', $this->identifier->normalize(' 0200212305195 '));
        self::assertNull($this->identifier->normalize('   '));
        self::assertNull($this->identifier->normalize(null));
    }

    public function testNtinIsThirteenDigitsWithPrefix02(): void
    {
        self::assertTrue($this->identifier->isValidNtin('0200212305195'));
        self::assertTrue($this->identifier->isValidNtin(' 0200212305195 '));
        self::assertFalse($this->identifier->isValidNtin('0040212305191'), 'XTIN — не NTIN');
        self::assertFalse($this->identifier->isValidNtin('020021230519'), 'двенадцать цифр');
        self::assertFalse($this->identifier->isValidNtin('02002123051950'), 'четырнадцать цифр');
        self::assertFalse($this->identifier->isValidNtin('02O0212305195'), 'буква вместо нуля');
        self::assertFalse($this->identifier->isValidNtin(null));
    }

    public function testXtinIsThirteenDigitsWithPrefix004(): void
    {
        self::assertTrue($this->identifier->isValidXtin('0040212305191'));
        self::assertFalse($this->identifier->isValidXtin('0200212305195'), 'NTIN — не XTIN');
        self::assertFalse($this->identifier->isValidXtin('004021230519'));
        self::assertFalse($this->identifier->isValidXtin(''));
    }
}
