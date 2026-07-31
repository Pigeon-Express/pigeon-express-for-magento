<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Config\Backend;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Model\Config\Backend\FlatRate;
use PigeonExpress\Shipping\Model\Config\Source\PricingMode;

/**
 * A field hidden by a `depends` rule is still submitted by the admin form, so
 * hiding the flat rate is not enough on its own. Without this backend model a
 * stale price would survive in the database and silently reappear the day
 * someone switched the delivery type back to fixed pricing.
 */
class FlatRateTest extends TestCase
{
    /** @var ObjectManager */
    private $objectManager;

    protected function setUp(): void
    {
        $this->objectManager = new ObjectManager($this);
    }

    /**
     * @dataProvider deliveryTypeProvider
     */
    public function testPriceIsClearedWhenTheDeliveryTypeIsPricedByTheApi(string $deliveryType): void
    {
        $model = $this->model($deliveryType, '9.99', PricingMode::DYNAMIC);

        $model->beforeSave();

        $this->assertSame('', $model->getValue());
    }

    /**
     * @dataProvider deliveryTypeProvider
     */
    public function testPriceIsKeptWhenTheDeliveryTypeUsesAFixedRate(string $deliveryType): void
    {
        $model = $this->model($deliveryType, '9.99', PricingMode::FIXED);

        $model->beforeSave();

        $this->assertSame('9.99', $model->getValue());
    }

    public function deliveryTypeProvider(): array
    {
        return [['address'], ['office'], ['aps']];
    }

    public function testASaveWithoutThePricingModeLeavesThePriceAlone(): void
    {
        // Saving an unrelated section must not wipe a configured price.
        $model = $this->model('address', '9.99', null);

        $model->beforeSave();

        $this->assertSame('9.99', $model->getValue());
    }

    public function testAnEmptyPricingModeLeavesThePriceAlone(): void
    {
        $model = $this->model('address', '9.99', '');

        $model->beforeSave();

        $this->assertSame('9.99', $model->getValue());
    }

    public function testAFieldThatIsNotAFlatRateIsUntouched(): void
    {
        $model = $this->model2('something_else', '9.99', ['something_pricing_mode' => PricingMode::DYNAMIC]);

        $model->beforeSave();

        $this->assertSame('9.99', $model->getValue());
    }

    private function model(string $deliveryType, string $value, ?string $mode): FlatRate
    {
        $fieldset = $mode === null ? null : [$deliveryType . '_pricing_mode' => $mode];

        return $this->model2($deliveryType . '_flat_rate', $value, $fieldset);
    }

    /** @param array<string, string>|null $fieldsetData */
    private function model2(string $field, string $value, ?array $fieldsetData): FlatRate
    {
        /** @var FlatRate $model */
        $model = $this->objectManager->getObject(FlatRate::class);
        $model->setData('field', $field);
        $model->setValue($value);

        if ($fieldsetData !== null) {
            $model->setData('fieldset_data', $fieldsetData);
        }

        return $model;
    }
}
