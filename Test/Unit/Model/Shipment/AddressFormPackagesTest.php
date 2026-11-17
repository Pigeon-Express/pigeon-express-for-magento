<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Shipment;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\Api\AddressResolver;
use PigeonExpress\Shipping\Model\Rate\LocationToApiIdResolver;
use PigeonExpress\Shipping\Model\Shipment\AddressForm;

/**
 * The package rows, the per-shipment services and the who-pays suggestion, as
 * the order panel posts them and the sender reads them back.
 *
 * The keys and their types are a contract with the payload side, so they are
 * asserted exactly.
 */
class AddressFormPackagesTest extends TestCase
{
    /** Getters the settings side adds; mocked whether or not they exist yet. */
    private const NEW_GETTERS = ['getFragileMode', 'getReturnReceiptMode', 'getReviewMode', 'isDeclaredValueAlways'];

    /** @var ConfigInterface&MockObject */
    private $config;

    /** @var AddressForm */
    private $form;

    protected function setUp(): void
    {
        $resolver = $this->getMockBuilder(AddressResolver::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['matchCityId', 'getCityById', 'matchStreet'])
            ->getMock();
        $locations = $this->getMockBuilder(LocationToApiIdResolver::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolveLocation'])
            ->getMock();

        $existing = array_values(array_filter(self::NEW_GETTERS, static function (string $method): bool {
            return method_exists(ConfigInterface::class, $method);
        }));
        $missing = array_values(array_diff(self::NEW_GETTERS, $existing));

        $builder = $this->getMockBuilder(ConfigInterface::class);
        if ($missing !== []) {
            $builder->addMethods($missing);
        }
        $all = array_map(static function (\ReflectionMethod $m): string {
            return $m->getName();
        }, (new \ReflectionClass(ConfigInterface::class))->getMethods());
        $this->config = $builder->onlyMethods($all)->getMock();

        $this->config->method('getWhoPays')->willReturn(ConfigInterface::WHO_PAYS_RECEIVER);
        $this->config->method('getDefaultLength')->willReturn(30.0);
        $this->config->method('getDefaultWidth')->willReturn(20.0);
        $this->config->method('getDefaultHeight')->willReturn(15.5);

        $this->form = new AddressForm($resolver, $locations, $this->config);
    }

    // -- Package rows ---------------------------------------------------------

    public function testRowsComeBackInTheContractShape(): void
    {
        $this->fragile(AddressForm::FRAGILE_PER_PACKAGE);

        $fields = $this->checked([
            'packages' => [
                ['weight' => '1,25', 'length' => '40.2', 'width' => '30', 'height' => '10',
                    'description' => '  Книги ', 'fragile' => '1'],
                ['weight' => '2', 'length' => '10', 'width' => '10', 'height' => '10', 'description' => ''],
            ],
        ]);

        $this->assertSame([
            ['weight' => 1.25, 'length' => 41, 'width' => 30, 'height' => 10, 'description' => 'Книги', 'fragile' => true],
            ['weight' => 2.0, 'length' => 10, 'width' => 10, 'height' => 10, 'description' => '', 'fragile' => false],
        ], $fields['packages']);
    }

    public function testAnEmptySizeIsTheDefaultBox(): void
    {
        $fields = $this->checked(['packages' => [['weight' => '1', 'length' => '', 'width' => '', 'height' => '']]]);

        $this->assertSame(30, $fields['packages'][0]['length']);
        $this->assertSame(20, $fields['packages'][0]['width']);
        $this->assertSame(16, $fields['packages'][0]['height']);
    }

    public function testAnEmptyRowIsDroppedAndNoRowAtAllIsRefused(): void
    {
        $result = $this->form->validate($this->fields([
            'packages' => [['weight' => '', 'length' => '', 'width' => '', 'height' => '', 'description' => '']],
        ]), 1);

        $this->assertSame([], $result['fields']['packages']);
        $this->assertSame(AddressForm::SEVERITY_ERROR, $result['issues']['packages']['severity']);
    }

    public function testAMissingWeightBlocksAndKeepsWhatWasTyped(): void
    {
        $result = $this->form->validate($this->fields([
            'packages' => [
                ['weight' => '1', 'length' => '10', 'width' => '10', 'height' => '10'],
                ['weight' => 'abc', 'length' => '10', 'width' => '10', 'height' => '10'],
            ],
        ]), 1);

        $this->assertSame(AddressForm::SEVERITY_ERROR, $result['issues']['packages']['severity']);
        $this->assertStringContainsString('2', $result['issues']['packages']['message']);
        $this->assertSame('abc', $result['fields']['packages'][1]['weight']);
    }

    public function testADescriptionIsCutToTheLongestTheCarrierTakes(): void
    {
        $fields = $this->checked(['packages' => [['weight' => '1', 'description' => str_repeat('я', 150)]]]);

        $this->assertSame(AddressForm::PACKAGE_DESCRIPTION_MAX_LENGTH, mb_strlen($fields['packages'][0]['description']));
    }

    public function testAnOldFormatFormBecomesEqualRows(): void
    {
        // As stored by a shipment created before package rows existed.
        $stored = $this->form->fromRequest($this->fields([
            'weight' => '3', 'length' => '60', 'width' => '40', 'height' => '30', 'parcels' => '2',
        ]));
        $fields = $this->checked($stored);

        $this->assertCount(2, $fields['packages']);
        foreach ($fields['packages'] as $row) {
            $this->assertSame(
                ['weight' => 1.5, 'length' => 60, 'width' => 40, 'height' => 30, 'description' => '', 'fragile' => false],
                $row
            );
        }
        // One source of truth: the old totals are emptied.
        foreach (AddressForm::PARCEL_FIELDS as $old) {
            $this->assertSame('', $fields[$old]);
        }
        $this->assertNull($this->form->parcel($fields));
    }

    public function testNoRowsAndNoTotalsLeaveTheParcelsToTheOrder(): void
    {
        $fields = $this->checked([]);

        $this->assertArrayNotHasKey('packages', $fields);
        $this->assertNull($this->form->packages($fields));
    }

    public function testCheckedRowsSurviveBeingStoredAndReadBack(): void
    {
        $this->fragile(AddressForm::FRAGILE_PER_PACKAGE);
        $first = $this->checked([
            'packages' => [['weight' => '1.2', 'length' => '10', 'width' => '20', 'height' => '30', 'fragile' => '1']],
            'declared_value' => '1',
            'declared_value_amount' => '120',
            'review' => 'view',
            'return_receipt' => 'paper',
        ]);

        $again = $this->checked($this->form->fromRequest(json_decode((string) json_encode($first), true)));

        foreach (['packages', 'declared_value', 'declared_value_amount', 'review', 'return_receipt'] as $key) {
            $this->assertSame($first[$key], $again[$key], $key);
        }
    }

    /**
     * @dataProvider fragileModes
     */
    public function testTheFragileMarkFollowsTheSetting(string $mode, string $posted, bool $expected): void
    {
        $this->fragile($mode);

        $fields = $this->checked(['packages' => [['weight' => '1', 'fragile' => $posted]]]);

        $this->assertSame($expected, $fields['packages'][0]['fragile']);
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public function fragileModes(): array
    {
        return [
            'never ignores a tick'      => [AddressForm::FRAGILE_NEVER, '1', false],
            'always marks every row'    => [AddressForm::FRAGILE_ALWAYS, '', true],
            'per package, ticked'       => [AddressForm::FRAGILE_PER_PACKAGE, '1', true],
            'per package, not ticked'   => [AddressForm::FRAGILE_PER_PACKAGE, '', false],
        ];
    }

    // -- Services -------------------------------------------------------------

    public function testServicesNotSentComeFromTheSettings(): void
    {
        $this->config->method('isDeclaredValueAlways')->willReturn(true);
        $this->config->method('getReviewMode')->willReturn('view_test');
        $this->config->method('getReturnReceiptMode')->willReturn('both');

        $fields = $this->checked([]);

        $this->assertTrue($fields['declared_value']);
        $this->assertNull($fields['declared_value_amount']);
        $this->assertSame('view_test', $fields['review']);
        $this->assertSame('both', $fields['return_receipt']);
    }

    public function testWhatTheAdminChoseWinsOverTheSettings(): void
    {
        $this->config->method('isDeclaredValueAlways')->willReturn(true);
        $this->config->method('getReviewMode')->willReturn('view_test');
        $this->config->method('getReturnReceiptMode')->willReturn('both');

        $fields = $this->checked($this->form->fromRequest($this->fields([
            'declared_value' => '0',
            'declared_value_amount' => '50',
            'review' => 'none',
            'return_receipt' => 'electronic',
        ])));

        $this->assertFalse($fields['declared_value']);
        // Unticked, so no amount is insured whatever is left in the box.
        $this->assertNull($fields['declared_value_amount']);
        $this->assertSame('none', $fields['review']);
        $this->assertSame('electronic', $fields['return_receipt']);
    }

    public function testADeclaredAmountIsKeptAsANumber(): void
    {
        $fields = $this->checked($this->form->fromRequest($this->fields([
            'declared_value' => '1',
            'declared_value_amount' => '149,90',
        ])));

        $this->assertTrue($fields['declared_value']);
        $this->assertSame(149.9, $fields['declared_value_amount']);
    }

    public function testADeclaredAmountThatIsNotOneBlocks(): void
    {
        $result = $this->form->validate($this->form->fromRequest($this->fields([
            'declared_value' => '1',
            'declared_value_amount' => '-5',
        ])), 1);

        $this->assertSame(AddressForm::SEVERITY_ERROR, $result['issues']['declared_value_amount']['severity']);
    }

    public function testALockerTakesNoInspectionAndNoReceipt(): void
    {
        $fields = $this->checked($this->form->fromRequest($this->fields([
            'delivery_type' => ConfigInterface::DELIVERY_TYPE_APS,
            'review' => 'view_test',
            'return_receipt' => 'both',
        ])));

        $this->assertSame('none', $fields['review']);
        $this->assertSame('none', $fields['return_receipt']);
    }

    public function testUnknownServiceValuesAreNotTakenFromTheRequest(): void
    {
        $fields = $this->form->fromRequest(['review' => 'everything', 'return_receipt' => ['x']]);

        $this->assertArrayNotHasKey('review', $fields);
        $this->assertArrayNotHasKey('return_receipt', $fields);
    }

    // -- Who pays -------------------------------------------------------------

    /**
     * @dataProvider rules
     */
    public function testTheRuleBehindTheSuggestion(bool $cod, bool $free, bool $fixed, string $rule, string $side): void
    {
        $this->assertSame($rule, $this->form->whoPaysRule($cod, $free, $fixed));
        $this->assertSame($side, $this->form->defaultWhoPays($rule, 1));
    }

    /**
     * @return array<string, array{bool, bool, bool, string, string}>
     */
    public function rules(): array
    {
        return [
            'free shipping beats everything' => [true, true, true, AddressForm::WHO_PAYS_RULE_FREE_SHIPPING, 'sender'],
            'a fixed price beats cod'        => [true, false, true, AddressForm::WHO_PAYS_RULE_FIXED_PRICE, 'sender'],
            'cod follows the setting'        => [true, false, false, AddressForm::WHO_PAYS_RULE_COD, 'receiver'],
            'paid online is the sender'      => [false, false, false, AddressForm::WHO_PAYS_RULE_PREPAID, 'sender'],
        ];
    }

    public function testEachRuleIsNamedInItsReason(): void
    {
        $this->assertStringContainsString(
            'Leave this on Sender',
            $this->form->whoPaysReason(AddressForm::WHO_PAYS_RULE_FREE_SHIPPING, 1)
        );
        $this->assertStringContainsString(
            'fixed delivery price',
            $this->form->whoPaysReason(AddressForm::WHO_PAYS_RULE_FIXED_PRICE, 1)
        );
        $this->assertSame(
            'Default: Receiver (the setting for cash on delivery orders).',
            $this->form->whoPaysReason(AddressForm::WHO_PAYS_RULE_COD, 1)
        );
        $this->assertStringContainsString(
            'not cash on delivery',
            $this->form->whoPaysReason(AddressForm::WHO_PAYS_RULE_PREPAID, 1)
        );
    }

    public function testAFixedPriceSuggestsTheSender(): void
    {
        $this->assertSame('sender', $this->form->whoPays([], 1, false, true));
        $this->assertSame('receiver', $this->form->whoPays([], 1, false, false));
        // The admin's own choice still wins.
        $this->assertSame('receiver', $this->form->whoPays(['who_pays' => 'receiver'], 1, false, true));
    }

    // -------------------------------------------------------------------------

    private function fragile(string $mode): void
    {
        $this->config->method('getFragileMode')->willReturn($mode);
    }

    /**
     * Office delivery to a known office, so only the parts under test can fail.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function fields(array $values = []): array
    {
        return array_merge($this->form->blank(), [
            'delivery_type' => ConfigInterface::DELIVERY_TYPE_OFFICE,
            'contact_name'  => 'Иван Петров',
            'contact_phone' => '0888123456',
        ], $values);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function checked(array $values): array
    {
        return $this->form->validate($this->fields($values), 1)['fields'];
    }
}
