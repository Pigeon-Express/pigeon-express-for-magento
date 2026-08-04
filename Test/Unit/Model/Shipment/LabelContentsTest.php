<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Shipment;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\Item;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\Config\Source\LabelContents as Mode;
use PigeonExpress\Shipping\Model\Shipment\LabelContents;

/**
 * What the carrier prints on the label. Getting this wrong either announces a
 * customer's purchase to everyone who handles the parcel, or prints a word the
 * merchant never chose on every waybill they send.
 */
class LabelContentsTest extends TestCase
{
    private const STORE_ID = 1;

    /** @var ObjectManager */
    private $objectManager;

    /** @var ConfigInterface&MockObject */
    private $config;

    /** @var LabelContents */
    private $model;

    protected function setUp(): void
    {
        $this->objectManager = new ObjectManager($this);
        $this->config = $this->createMock(ConfigInterface::class);
        $this->model = $this->objectManager->getObject(LabelContents::class, [
            'config' => $this->config,
        ]);
    }

    public function testProductNamesAndQuantitiesAreSentByDefault(): void
    {
        // The module sent these before the setting existed, and an upgrade must
        // not silently change what is printed on a merchant's labels.
        $this->config->method('getLabelContentsMode')->willReturn(Mode::PRODUCT_NAMES);
        $this->config->expects($this->never())->method('getLabelContentsText');

        $items = $this->model->build($this->order([['Push It Messenger Bag 1kg', 5, 45.0]]), self::STORE_ID);

        $this->assertCount(1, $items);
        $this->assertSame('Push It Messenger Bag 1kg', $items[0]['description']);
        $this->assertSame(5, $items[0]['quantity']);
    }

    public function testAFixedDescriptionSendsOneLineWithNoQuantity(): void
    {
        // "1 бр. - Дрехи" reads as though something has been lost, and repeating
        // the same word once per order line is worse. Quantity is optional in the
        // API, so leaving the key out is what gets the count off the label.
        $this->config->method('getLabelContentsMode')->willReturn(Mode::FIXED_TEXT);
        $this->config->method('getLabelContentsText')->willReturn('Дрехи');

        $items = $this->model->build(
            $this->order([['Push It Messenger Bag 1kg', 5, 45.0], ['Chaz Hoodie', 2, 52.0]]),
            self::STORE_ID
        );

        $this->assertCount(1, $items, 'three order lines still make one label line');
        $this->assertSame(['description' => 'Дрехи'], $items[0]);
        $this->assertArrayNotHasKey('quantity', $items[0]);
    }

    public function testNoProductNameSurvivesTheFixedMode(): void
    {
        $this->config->method('getLabelContentsMode')->willReturn(Mode::FIXED_TEXT);
        $this->config->method('getLabelContentsText')->willReturn('Стоки');

        $items = $this->model->build($this->order([['Chaz Kangeroo Hoodie', 1, 52.0]]), self::STORE_ID);

        $this->assertStringNotContainsStringIgnoringCase('Chaz', json_encode($items) ?: '');
    }

    public function testAnEmptyOrderStillSendsSomething(): void
    {
        // The API requires at least one item, and rejects the shipment without.
        $this->config->method('getLabelContentsMode')->willReturn(Mode::PRODUCT_NAMES);
        $this->config->method('getLabelContentsText')->willReturn('Goods');

        $items = $this->model->build($this->order([]), self::STORE_ID);

        $this->assertCount(1, $items);
        $this->assertNotSame('', $items[0]['description']);
    }

    public function testChildItemsAreSkippedSoAConfigurableCountsOnce(): void
    {
        $this->config->method('getLabelContentsMode')->willReturn(Mode::PRODUCT_NAMES);

        $items = $this->model->build(
            $this->order([['Parent Hoodie', 1, 52.0], ['Child Hoodie S', 1, 52.0, 99]]),
            self::STORE_ID
        );

        $this->assertCount(1, $items);
        $this->assertSame('Parent Hoodie', $items[0]['description']);
    }

    public function testALongProductNameIsTrimmedToWhatTheApiAccepts(): void
    {
        // The API caps a description at 200 characters and rejects the whole
        // shipment rather than trimming it.
        $this->config->method('getLabelContentsMode')->willReturn(Mode::PRODUCT_NAMES);

        $items = $this->model->build($this->order([[str_repeat('a', 400), 1, 1.0]]), self::STORE_ID);

        $this->assertLessThanOrEqual(200, mb_strlen($items[0]['description']));
    }

    public function testAnOrderLineWithNoNameStillPrintsSomething(): void
    {
        $this->config->method('getLabelContentsMode')->willReturn(Mode::PRODUCT_NAMES);

        $items = $this->model->build($this->order([['   ', 1, 1.0]]), self::STORE_ID);

        $this->assertNotSame('', trim($items[0]['description']));
    }

    public function testAnUnrecognisedModeFallsBackToProductNames(): void
    {
        // A value left behind by a future version, or hand edited in the database.
        $this->config->method('getLabelContentsMode')->willReturn('something_else');

        $items = $this->model->build($this->order([['Hoodie', 3, 10.0]]), self::STORE_ID);

        $this->assertSame('Hoodie', $items[0]['description']);
        $this->assertSame(3, $items[0]['quantity']);
    }

    /**
     * @param array<int, array{0:string,1:int,2:float,3?:int}> $lines name, qty, price, parentId
     * @return OrderInterface&MockObject
     */
    private function order(array $lines)
    {
        $items = [];
        foreach ($lines as $line) {
            $item = $this->getMockBuilder(Item::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['getParentItemId', 'getQtyOrdered', 'getPrice', 'getName'])
                ->getMock();
            $item->method('getName')->willReturn($line[0]);
            $item->method('getQtyOrdered')->willReturn((float) $line[1]);
            $item->method('getPrice')->willReturn($line[2]);
            $item->method('getParentItemId')->willReturn($line[3] ?? null);
            $items[] = $item;
        }

        $order = $this->createMock(OrderInterface::class);
        $order->method('getItems')->willReturn($items);

        return $order;
    }
}
