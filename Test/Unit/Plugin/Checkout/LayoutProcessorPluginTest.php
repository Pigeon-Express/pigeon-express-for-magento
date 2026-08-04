<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Plugin\Checkout;

use Magento\Checkout\Block\Checkout\LayoutProcessor;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\Config\Source\CheckoutLogo;
use PigeonExpress\Shipping\Plugin\Checkout\LayoutProcessorPlugin;

class LayoutProcessorPluginTest extends TestCase
{
    /** @var ConfigInterface&MockObject */
    private $config;

    /** @var LayoutProcessorPlugin */
    private $plugin;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->config->method('getCheckoutLogo')->willReturn('');

        $this->plugin = new LayoutProcessorPlugin(
            $this->config,
            $this->createMock(AssetRepository::class),
            $this->createMock(CheckoutLogo::class)
        );
    }

    /**
     * The address fieldset as Magento hands it over, cut down to what matters.
     *
     * @return array<string, mixed>
     */
    private function layout(): array
    {
        return ['components' => ['checkout' => ['children' => ['steps' => ['children' => [
            'shipping-step' => ['children' => ['shippingAddress' => ['children' => [
                'shipping-address-fieldset' => ['children' => [
                    'firstname'  => ['visible' => true],
                    'lastname'   => ['visible' => true],
                    'company'    => ['visible' => true],
                    'telephone'  => ['visible' => true],
                    'city'       => ['visible' => true],
                    'postcode'   => ['visible' => true],
                    'region_id'  => ['visible' => true],
                    'region'     => ['visible' => false],
                    'country_id' => ['visible' => true],
                    'street'     => ['children' => [['visible' => true], ['visible' => true]]],
                ]],
            ]]]],
        ]]]]]];
    }

    /**
     * @return array<string, mixed>
     */
    private function process(): array
    {
        $out = $this->plugin->afterProcess(
            $this->createMock(LayoutProcessor::class),
            $this->layout()
        );

        return $out['components']['checkout']['children']['steps']['children']['shipping-step']
            ['children']['shippingAddress']['children']['shipping-address-fieldset']['children'];
    }

    /**
     * Hidden in the layout rather than by script, so they are never drawn: doing
     * it afterwards showed the fields and then took them away again.
     */
    public function testTakesTheReplacedFieldsOutOfTheFormWhenTheOverrideIsOn(): void
    {
        $this->config->method('isOverrideAddressFields')->willReturn(true);

        $fields = $this->process();

        foreach (['city', 'postcode', 'region_id', 'country_id'] as $name) {
            $this->assertFalse($fields[$name]['visible'], $name . ' should not be drawn');
        }

        foreach ($fields['street']['children'] as $i => $line) {
            $this->assertFalse($line['visible'], 'street line ' . $i . ' should not be drawn');
        }
    }

    /**
     * Nothing here collects these, and the telephone least of all: it is how
     * Pigeon Express identifies a recipient.
     */
    public function testLeavesTheFieldsNothingHereReplaces(): void
    {
        $this->config->method('isOverrideAddressFields')->willReturn(true);

        $fields = $this->process();

        foreach (['firstname', 'lastname', 'company', 'telephone'] as $name) {
            $this->assertTrue($fields[$name]['visible'], $name . ' should still be asked for');
        }
    }

    /**
     * Magento's own region component chooses between the dropdown and the free
     * text box from the country. Touching it either way fights that.
     */
    public function testLeavesTheFreeTextRegionAlone(): void
    {
        $this->config->method('isOverrideAddressFields')->willReturn(true);

        $this->assertFalse($this->process()['region']['visible']);
    }

    public function testChangesNothingWhenTheOverrideIsOff(): void
    {
        $this->config->method('isOverrideAddressFields')->willReturn(false);

        $fields = $this->process();

        foreach (['city', 'postcode', 'region_id', 'country_id'] as $name) {
            $this->assertTrue($fields[$name]['visible'], $name . ' should be left alone');
        }

        foreach ($fields['street']['children'] as $line) {
            $this->assertTrue($line['visible']);
        }
    }

    public function testStillRegistersTheCarriersOwnComponents(): void
    {
        $this->config->method('isOverrideAddressFields')->willReturn(false);

        $out = $this->plugin->afterProcess($this->createMock(LayoutProcessor::class), $this->layout());
        $children = $out['components']['checkout']['children']['steps']['children']['shipping-step']
            ['children']['shippingAddress']['children'];

        foreach (
            [
                'pigeonexpress-address-visibility',
                'pigeonexpress-location',
                'pigeonexpress-address',
                'pigeonexpress-instructions',
            ] as $name
        ) {
            $this->assertArrayHasKey($name, $children);
        }
    }

    public function testTheMethodRowIsInstalledEvenWithNoLogoConfigured(): void
    {
        // The row carries the word shown in place of a zero price as well as the
        // logo, so tying it to a configured logo would have left stores without
        // one showing 0.00 for a delivery they are giving away.
        $this->config->method('isOverrideAddressFields')->willReturn(false);

        $config = $this->shippingAddressConfig();

        $this->assertSame(
            'PigeonExpress_Shipping/shipping-address/shipping-method-item',
            $config['shippingMethodItemTemplate'] ?? null
        );
    }

    public function testTheFreeLabelIsResolvedServerSide(): void
    {
        // Passed as a finished string rather than translated in the browser, so
        // it does not depend on js-translation.json having been rebuilt.
        $this->config->method('isOverrideAddressFields')->willReturn(false);

        $config = $this->shippingAddressConfig();

        $this->assertArrayHasKey('pigeonexpressFreeLabel', $config);
        $this->assertNotSame('', $config['pigeonexpressFreeLabel']);
    }

    public function testNoLogoKeysAreSetWhenNoLogoIsConfigured(): void
    {
        $this->config->method('isOverrideAddressFields')->willReturn(false);

        $config = $this->shippingAddressConfig();

        $this->assertArrayNotHasKey('pigeonexpressLogoUrl', $config);
        $this->assertArrayNotHasKey('pigeonexpressLogoDark', $config);
    }

    /**
     * @return array<string, mixed>
     */
    private function shippingAddressConfig(): array
    {
        $out = $this->plugin->afterProcess($this->createMock(LayoutProcessor::class), $this->layout());

        return $out['components']['checkout']['children']['steps']['children']['shipping-step']
            ['children']['shippingAddress']['config'] ?? [];
    }
}
