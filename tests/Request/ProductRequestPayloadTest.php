<?php

declare(strict_types=1);

namespace Iperson1337\NctClientBundle\Tests\Request;

use Iperson1337\NctClientBundle\Request\ExtendedRequestStatus;
use Iperson1337\NctClientBundle\Request\ProductRequestPayload;
use Iperson1337\NctClientBundle\Request\RequestStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Сравнение с уже отправленным телом заявки решает, уйдёт ли PUT в портал: ложное «изменилось»
 * шлёт лишний PUT и повторную модерацию, ложное «то же» теряет правку.
 */
#[CoversClass(ProductRequestPayload::class)]
#[CoversClass(RequestStatus::class)]
#[CoversClass(ExtendedRequestStatus::class)]
final class ProductRequestPayloadTest extends TestCase
{
    public function testSamePayloadWithDifferentKeyAndAttributeOrderMatches(): void
    {
        $stored = json_encode([
            'oktru' => '10.1',
            'attributes' => [['value' => 'Молоко', 'code' => 'name_ru'], ['code' => 'gtin', 'value' => '4870000000017']],
            'autoPublication' => false,
        ], JSON_UNESCAPED_UNICODE);

        $current = ProductRequestPayload::fromArray([
            'autoPublication' => false,
            'attributes' => [['code' => 'gtin', 'value' => '4870000000017'], ['code' => 'name_ru', 'value' => 'Молоко']],
            'oktru' => '10.1',
        ]);

        self::assertTrue($current->matchesStoredJson($stored));
    }

    public function testChangedAttributeValueDoesNotMatch(): void
    {
        $current = ProductRequestPayload::fromArray(['attributes' => [['code' => 'name_ru', 'value' => 'Кефир']]]);

        self::assertFalse($current->matchesStoredJson(json_encode(['attributes' => [['code' => 'name_ru', 'value' => 'Молоко']]], JSON_UNESCAPED_UNICODE)));
    }

    public function testEmptyOrBrokenStoredBodyNeverMatches(): void
    {
        $payload = new ProductRequestPayload('10.1', false);

        self::assertFalse($payload->matchesStoredJson(null));
        self::assertFalse($payload->matchesStoredJson('  '));
        self::assertFalse($payload->matchesStoredJson('{not json'));
        self::assertFalse($payload->matchesStoredJson('"string"'));
    }

    public function testToArrayOmitsUnsetParts(): void
    {
        self::assertSame(['autoPublication' => true], (new ProductRequestPayload(null, true))->toArray());

        $extended = new ProductRequestPayload(
            '10.51.11',
            false,
            [['code' => 'name_ru', 'value' => 'Молоко'], ['code' => 'brand', 'value' => 'Эмиль']],
            ntin: '0200212305195',
            isGov: true,
            mainExtendedAttributes: [['code' => 'gross_weight', 'value' => '1']],
            imageFiles: [['fileName' => 'a.jpg', 'imageType' => 'front']],
            documents: [['fileName' => 'cert.pdf']],
        );

        self::assertSame(
            ['oktru', 'ntin', 'autoPublication', 'isGov', 'attributes', 'mainExtendedAttributes', 'imageFiles', 'documents'],
            array_keys($extended->toArray()),
        );
        self::assertTrue(ProductRequestPayload::fromJson($extended->toJson())->equals($extended));
    }

    public function testExtendedAttributeOrderDoesNotMatterEither(): void
    {
        $a = ProductRequestPayload::fromArray(['mainExtendedAttributes' => [['code' => 'b', 'value' => 1], ['code' => 'a', 'value' => 2]]]);
        $b = ProductRequestPayload::fromArray(['mainExtendedAttributes' => [['code' => 'a', 'value' => 2], ['code' => 'b', 'value' => 1]]]);

        self::assertTrue($a->equals($b));
    }

    public function testStatusEnumsKnowTheirCodes(): void
    {
        self::assertTrue(RequestStatus::Completed->isFinal());
        self::assertTrue(RequestStatus::ExistingProductSelected->isFinal());
        self::assertFalse(RequestStatus::Cancelled->isFinal());
        self::assertTrue(RequestStatus::Cancelled->isEditable());
        self::assertFalse(RequestStatus::OnModeration->isEditable());
        self::assertNull(RequestStatus::tryFrom('somethingNew'));
        self::assertSame(ExtendedRequestStatus::Publishing, ExtendedRequestStatus::from('publishingGz'));
    }
}
