<?php

declare(strict_types=1);

namespace Iperson1337\NctClientBundle\Tests\Request;

use Iperson1337\NctClientBundle\Request\RequestDetailsParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 */
#[CoversClass(RequestDetailsParser::class)]
final class RequestDetailsParserTest extends TestCase
{
    private RequestDetailsParser $parser;

    protected function setUp(): void
    {
        $this->parser = new RequestDetailsParser();
    }

    public function testExtractStatusCodeReturnsTrimmedCode(): void
    {
        $this->assertSame('completed', $this->parser->extractStatusCode([
            'status' => ['code' => ' completed ', 'value' => 'Завершено'],
        ]));
    }

    public function testExtractStatusCodeReturnsNullWhenStatusMissing(): void
    {
        $this->assertNull($this->parser->extractStatusCode([]));
    }

    public function testExtractStatusCodeReturnsNullWhenStatusNotArray(): void
    {
        $this->assertNull($this->parser->extractStatusCode(['status' => 'completed']));
    }

    public function testExtractStatusCodeReturnsNullWhenCodeEmpty(): void
    {
        $this->assertNull($this->parser->extractStatusCode(['status' => ['code' => '  ']]));
    }

    public function testExtractStatusCodeReturnsNullWhenCodeNotString(): void
    {
        $this->assertNull($this->parser->extractStatusCode(['status' => ['code' => 123]]));
    }

    public function testExtractAttributeValueByCodeFindsMatchingAttribute(): void
    {
        $details = [
            'attributes' => [
                ['code' => 'xtin', 'value' => 'XTIN-1'],
                ['code' => 'ntin', 'value' => '04870123456789'],
            ],
        ];

        $this->assertSame('04870123456789', $this->parser->extractAttributeValueByCode($details, 'ntin'));
    }

    public function testExtractAttributeValueByCodeReturnsNullWhenNotFound(): void
    {
        $details = ['attributes' => [['code' => 'xtin', 'value' => 'XTIN-1']]];

        $this->assertNull($this->parser->extractAttributeValueByCode($details, 'ntin'));
    }

    public function testExtractAttributeValueByCodeReturnsNullWhenAttributesMissing(): void
    {
        $this->assertNull($this->parser->extractAttributeValueByCode([], 'ntin'));
    }

    public function testExtractAttributeValueByCodeReturnsNullWhenValueKeyAbsent(): void
    {
        $details = ['attributes' => [['code' => 'ntin']]];

        $this->assertNull($this->parser->extractAttributeValueByCode($details, 'ntin'));
    }

    public function testExtractAttributeValueByCodeReturnsNullWhenValueEmptyAfterTrim(): void
    {
        $details = ['attributes' => [['code' => 'ntin', 'value' => '   ']]];

        $this->assertNull($this->parser->extractAttributeValueByCode($details, 'ntin'));
    }

    public function testExtractAttributeValueByCodeAcceptsNumericValue(): void
    {
        $details = ['attributes' => [['code' => 'ntin', 'value' => 4870123456789]]];

        $this->assertSame('4870123456789', $this->parser->extractAttributeValueByCode($details, 'ntin'));
    }

    public function testExtractModeratorCommentReturnsTrimmedValue(): void
    {
        $this->assertSame('Неверно указан состав', $this->parser->extractModeratorComment([
            'moderatorComment' => '  Неверно указан состав  ',
        ]));
    }

    public function testExtractModeratorCommentReturnsNullWhenKeyAbsent(): void
    {
        $this->assertNull($this->parser->extractModeratorComment([]));
    }

    public function testExtractModeratorCommentReturnsEmptyStringWhenKeyPresentButNull(): void
    {
        $this->assertSame('', $this->parser->extractModeratorComment(['moderatorComment' => null]));
    }
}
