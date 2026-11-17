<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Config\Source;

use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Model\Config\Source\OrderOnDelivery;
use PigeonExpress\Shipping\Model\Config\Source\OrderOnReturn;
use PigeonExpress\Shipping\Model\Magento\Settings;
use PigeonExpress\Shipping\Model\Status\StatusConfig;

/**
 * The merchant picks what happens to the order, never a status code.
 */
class OrderActionSourcesTest extends TestCase
{
    public function testDeliveryOffersLeaveOrComplete(): void
    {
        $this->assertSame(
            ['none' => "Don't change the order", 'complete' => 'Complete the order'],
            $this->options((new OrderOnDelivery())->toOptionArray())
        );
    }

    public function testReturnOffersLeaveHoldOrCancel(): void
    {
        $this->assertSame(
            [
                'none' => "Don't change the order",
                'hold' => 'Put the order on hold',
                'cancel' => 'Cancel the order',
            ],
            $this->options((new OrderOnReturn())->toOptionArray())
        );
    }

    /**
     * @dataProvider savedValues
     */
    public function testUnknownOrMissingValuesReadAsDontChange(
        ?string $delivery,
        ?string $return,
        string $expectedDelivery,
        string $expectedReturn,
        bool $complete
    ): void {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnMap([
            [StatusConfig::XML_ORDER_ON_DELIVERY, 'store', 1, $delivery],
            [StatusConfig::XML_ORDER_ON_RETURN, 'store', 1, $return],
        ]);
        $config = new StatusConfig($scope);

        $this->assertSame($expectedDelivery, $config->getOrderOnDelivery(1));
        $this->assertSame($expectedReturn, $config->getOrderOnReturn(1));
        $this->assertSame($complete, (new Settings($scope))->isCompleteOnDelivery(1));
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string, 2: string, 3: string, 4: bool}>
     */
    public static function savedValues(): array
    {
        return [
            'never saved' => [null, null, 'none', 'none', false],
            'chosen' => ['complete', 'cancel', 'complete', 'cancel', true],
            'hold' => ['none', 'hold', 'none', 'hold', false],
            // An old status code, or an action of the other setting.
            'unknown' => ['delivered', 'complete', 'none', 'none', false],
        ];
    }

    /**
     * @param array<int, array{value: string, label: mixed}> $options
     * @return array<string, string>
     */
    private function options(array $options): array
    {
        $out = [];
        foreach ($options as $option) {
            $out[$option['value']] = (string) $option['label'];
        }

        return $out;
    }
}
