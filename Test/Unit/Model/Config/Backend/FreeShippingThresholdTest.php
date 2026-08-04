<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Config\Backend;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Model\Config\Backend\FreeShippingThreshold;

/**
 * A field hidden by a `depends` rule is still submitted by the admin form, so
 * hiding the threshold is not enough on its own. Without this backend model an
 * old figure would survive in the database and start giving deliveries away the
 * day someone turned the threshold back on.
 */
class FreeShippingThresholdTest extends TestCase
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
    public function testThresholdIsClearedWhenTheToggleIsOff(string $deliveryType): void
    {
        $model = $this->model($deliveryType, '50.00', '0');

        $model->beforeSave();

        $this->assertSame('', $model->getValue());
    }

    /**
     * @dataProvider deliveryTypeProvider
     */
    public function testThresholdIsKeptWhenTheToggleIsOn(string $deliveryType): void
    {
        $model = $this->model($deliveryType, '50.00', '1');

        $model->beforeSave();

        $this->assertSame('50.00', $model->getValue());
    }

    public function deliveryTypeProvider(): array
    {
        return [['address'], ['office'], ['aps']];
    }

    public function testASaveWithoutTheToggleLeavesTheThresholdAlone(): void
    {
        // Saving an unrelated section must not wipe a configured threshold.
        $model = $this->model('address', '50.00', null);

        $model->beforeSave();

        $this->assertSame('50.00', $model->getValue());
    }

    public function testAnEmptyToggleLeavesTheThresholdAlone(): void
    {
        $model = $this->model('address', '50.00', '');

        $model->beforeSave();

        $this->assertSame('50.00', $model->getValue());
    }

    public function testAFieldThatIsNotAThresholdIsUntouched(): void
    {
        $model = $this->rawModel('something_else', '50.00', ['something_free_shipping_enabled' => '0']);

        $model->beforeSave();

        $this->assertSame('50.00', $model->getValue());
    }

    public function testTheFlatRateFieldIsNotMistakenForAThreshold(): void
    {
        // Both backend models key off the field name, so a suffix that almost
        // matches must not make one of them act on the other's field.
        $model = $this->rawModel('address_flat_rate', '9.99', ['address_free_shipping_enabled' => '0']);

        $model->beforeSave();

        $this->assertSame('9.99', $model->getValue());
    }

    private function model(string $deliveryType, string $value, ?string $enabled): FreeShippingThreshold
    {
        $fieldset = $enabled === null ? null : [$deliveryType . '_free_shipping_enabled' => $enabled];

        return $this->rawModel($deliveryType . '_free_shipping_threshold', $value, $fieldset);
    }

    /** @param array<string, string>|null $fieldsetData */
    private function rawModel(string $field, string $value, ?array $fieldsetData): FreeShippingThreshold
    {
        /** @var FreeShippingThreshold $model */
        $model = $this->objectManager->getObject(FreeShippingThreshold::class);
        $model->setData('field', $field);
        $model->setValue($value);

        if ($fieldsetData !== null) {
            $model->setData('fieldset_data', $fieldsetData);
        }

        return $model;
    }
}
