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
 * The form is the last point at which a bad address can be caught. Past it,
 * Pigeon Express resolves the recipient from the phone number and quietly
 * substitutes an address it already holds, so the parcel goes elsewhere while
 * the label reads correctly.
 *
 * The lookups themselves are covered by the live verification script. What is
 * tested here is what the form does with their answers.
 */
class AddressFormTest extends TestCase
{
    private const SOFIA = 759;

    /** @var AddressResolver&MockObject */
    private $resolver;

    /** @var LocationToApiIdResolver&MockObject */
    private $locations;

    /** @var AddressForm */
    private $form;

    protected function setUp(): void
    {
        // Only the lookups are stubbed. The parsing runs for real, so the form
        // is tested against the same text handling that production uses.
        $this->resolver = $this->getMockBuilder(AddressResolver::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['matchCityId', 'getCityById', 'matchStreet'])
            ->getMock();

        $this->locations = $this->getMockBuilder(LocationToApiIdResolver::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolveLocation'])
            ->getMock();

        $config = $this->createMock(ConfigInterface::class);
        $config->method('getWhoPays')->willReturn(ConfigInterface::WHO_PAYS_RECEIVER);

        $this->form = new AddressForm($this->resolver, $this->locations, $config);
    }

    // -- What stops a shipment ----------------------------------------------

    public function testAnEmptyFormReportsEverythingThatIsMissing(): void
    {
        $issues = $this->form->validate($this->form->blank(), 1)['issues'];

        $this->assertSame(AddressForm::SEVERITY_ERROR, $issues['contact_name']['severity']);
        $this->assertSame(AddressForm::SEVERITY_ERROR, $issues['contact_phone']['severity']);
        $this->assertSame(AddressForm::SEVERITY_ERROR, $issues['city']['severity']);
        $this->assertSame(AddressForm::SEVERITY_ERROR, $issues['street']['severity']);
        $this->assertTrue($this->form->hasErrors($issues));
    }

    public function testAnUnknownCityBlocksTheShipment(): void
    {
        $this->resolver->method('matchCityId')->willReturn(null);

        $issues = $this->form->validate($this->fields(['city' => 'Атлантида']), 1)['issues'];

        $this->assertSame(AddressForm::SEVERITY_ERROR, $issues['city']['severity']);
        $this->assertStringContainsString('Атлантида', $issues['city']['message']);
    }

    /**
     * @dataProvider unusablePhoneProvider
     */
    public function testAPhoneTooShortToDialBlocksTheShipment(string $phone): void
    {
        // Getting this wrong is the worst case there is: the carrier looks the
        // recipient up by phone, so a wrong number can attach the parcel to
        // somebody else's stored address.
        $this->cityResolves();
        $this->streetMatches();

        $issues = $this->form->validate($this->fields(['contact_phone' => $phone]), 1)['issues'];

        $this->assertSame(AddressForm::SEVERITY_ERROR, $issues['contact_phone']['severity']);
    }

    public function unusablePhoneProvider(): array
    {
        return [
            'truncated' => ['87601'],
            'a note'    => ['n/a'],
            'zeroes'    => ['000'],
        ];
    }

    public function testAFullBulgarianNumberIsAccepted(): void
    {
        $this->cityResolves();
        $this->streetMatches();

        $checked = $this->form->validate($this->fields(['contact_phone' => '0899 123 456']), 1);

        $this->assertArrayNotHasKey('contact_phone', $checked['issues']);
        $this->assertSame('+359899123456', $checked['fields']['contact_phone']);
    }

    // -- What only warns -----------------------------------------------------

    public function testAnUnknownStreetWarnsButStillShips(): void
    {
        $this->cityResolves();
        $this->resolver->method('matchStreet')->willReturn(null);

        $checked = $this->form->validate($this->fields(), 1);

        $this->assertSame(AddressForm::SEVERITY_WARNING, $checked['issues']['street']['severity']);
        $this->assertFalse($this->form->hasErrors($checked['issues']));
    }

    public function testTheStreetWarningNamesTheConsequence(): void
    {
        // An admin who is not told what goes wrong has no reason to act on it.
        $this->cityResolves();
        $this->resolver->method('matchStreet')->willReturn(null);

        $message = $this->form->validate($this->fields(), 1)['issues']['street']['message'];

        $this->assertStringContainsString('phone number', $message);
    }

    public function testAMissingStreetNumberWarns(): void
    {
        $this->cityResolves();
        $this->streetMatches();

        $checked = $this->form->validate($this->fields(['street_number' => '']), 1);

        $this->assertSame(AddressForm::SEVERITY_WARNING, $checked['issues']['street_number']['severity']);
        $this->assertFalse($this->form->hasErrors($checked['issues']));
    }

    public function testTwoStreetsOfTheSameNameAskTheAdminToChoose(): void
    {
        // Sofia has a квартал and a булевард both called ВИТОША, in different
        // parts of the city. Picking one silently is a coin toss with a parcel.
        $this->cityResolves();
        $this->streetMatches(true);

        $checked = $this->form->validate($this->fields(), 1);

        $this->assertSame(AddressForm::SEVERITY_WARNING, $checked['issues']['street']['severity']);
        $this->assertStringContainsString('улица ВИТОША', $checked['issues']['street']['message']);
    }

    public function testAnAmbiguousStreetStillShipsWithAnId(): void
    {
        // Refusing would be worse: one of the two is right, and the merchant may
        // well know which.
        $this->cityResolves();
        $this->streetMatches(true);

        $checked = $this->form->validate($this->fields(), 1);

        $this->assertFalse($this->form->hasErrors($checked['issues']));
        $this->assertSame('434618', $checked['fields']['street_id']);
    }

    public function testTheCustomersStreetTypeIsPassedToTheLookup(): void
    {
        // "бул. Витоша" and "кв. Витоша" are different places. The type the
        // customer wrote is the only thing that tells them apart.
        $this->cityResolves();
        $this->resolver->expects($this->once())
            ->method('matchStreet')
            ->with(self::SOFIA, 'Витоша', 1, 'булевард')
            ->willReturn(null);

        $this->form->validate($this->fields(['street' => 'бул. Витоша']), 1);
    }

    public function testAMissingNumberIsNotReportedTwiceWhenTheStreetIsAlreadyWrong(): void
    {
        // Two red messages for one broken street reads as two problems.
        $this->cityResolves();
        $this->resolver->method('matchStreet')->willReturn(null);

        $issues = $this->form->validate($this->fields(['street_number' => '']), 1)['issues'];

        $this->assertArrayNotHasKey('street_number', $issues);
    }

    // -- Resolving -----------------------------------------------------------

    public function testAMatchedStreetIsRewrittenToTheCarriersOwnName(): void
    {
        $this->cityResolves();
        $this->streetMatches();

        $fields = $this->form->validate($this->fields(['street' => 'Витоша']), 1)['fields'];

        $this->assertSame('ВИТОША', $fields['street']);
        $this->assertSame('434618', $fields['street_id']);
    }

    public function testAStreetChosenFromTheListIsNotLookedUpAgain(): void
    {
        $this->cityResolves();
        $this->resolver->expects($this->never())->method('matchStreet');

        $fields = $this->form->validate($this->fields(['street_id' => '434618']), 1)['fields'];

        $this->assertSame('434618', $fields['street_id']);
    }

    public function testACityIdThatNoLongerExistsFallsBackToTheName(): void
    {
        // A location sync can retire a settlement between one order and the next.
        $this->resolver->method('getCityById')->willReturnCallback(
            static function (int $id): ?array {
                return $id === self::SOFIA
                    ? ['id' => self::SOFIA, 'name' => 'София', 'postcode' => '1000', 'label' => 'София (1000)']
                    : null;
            }
        );
        $this->resolver->method('matchCityId')->willReturn(self::SOFIA);
        $this->streetMatches();

        $fields = $this->form->validate($this->fields(['city_id' => '99999999']), 1)['fields'];

        $this->assertSame((string) self::SOFIA, $fields['city_id']);
    }

    public function testATypedStreetKeepsItsNumber(): void
    {
        // An admin given one box for the street will put the number in it.
        $this->cityResolves();
        $this->streetMatches();

        $fields = $this->form->validate($this->fields(['street' => 'Витоша 15', 'street_number' => '']), 1)['fields'];

        $this->assertSame('15', $fields['street_number']);
    }

    public function testAnExistingNumberIsNotOverwritten(): void
    {
        $this->cityResolves();
        $this->streetMatches();

        $fields = $this->form->validate($this->fields(['street' => 'Витоша 15', 'street_number' => '22']), 1)['fields'];

        $this->assertSame('22', $fields['street_number']);
    }

    public function testThePostalCodeIsFilledFromTheCityWhenItIsMissing(): void
    {
        $this->cityResolves();
        $this->streetMatches();

        $fields = $this->form->validate($this->fields(['postal_code' => '']), 1)['fields'];

        $this->assertSame('1000', $fields['postal_code']);
    }

    public function testTheCustomersOwnPostalCodeIsLeftAlone(): void
    {
        // Sofia alone has dozens of codes and the carrier stores one per city, so
        // replacing what the customer gave would lose the more precise value.
        $this->cityResolves();
        $this->streetMatches();

        $fields = $this->form->validate($this->fields(['postal_code' => '1113']), 1)['fields'];

        $this->assertSame('1113', $fields['postal_code']);
    }

    // -- Pickup points -------------------------------------------------------

    public function testOfficeDeliveryChecksOnlyTheContact(): void
    {
        $this->officeResolves();
        $this->resolver->expects($this->never())->method('matchCityId');

        $issues = $this->form->validate($this->fields(['delivery_type' => 'office', 'location_id' => '7']), 1)['issues'];

        $this->assertSame([], $issues);
    }

    public function testOfficeDeliveryDropsTheAddressFields(): void
    {
        $this->officeResolves();
        // The parcel goes to an office, so a street on the payload could only
        // ever contradict it.
        $fields = $this->form->validate($this->fields(['delivery_type' => 'office', 'location_id' => '7']), 1)['fields'];

        $this->assertSame('', $fields['city']);
        $this->assertSame('', $fields['street']);
        $this->assertSame('', $fields['postal_code']);
    }

    public function testAnOfficeHasToBeChosen(): void
    {
        $issues = $this->form->validate($this->fields(['delivery_type' => 'office']), 1)['issues'];

        $this->assertSame(AddressForm::SEVERITY_ERROR, $issues['location']['severity']);
    }

    public function testAnOfficeThatNoLongerExistsIsRefused(): void
    {
        // Offices close and lockers are moved, and the nightly sync retires them.
        // An id that no longer resolves must not reach the API.
        $this->locations->method('resolveLocation')->willReturn(null);

        $checked = $this->form->validate(
            $this->fields(['delivery_type' => 'office', 'location_id' => '7']),
            1
        );

        $this->assertSame(AddressForm::SEVERITY_ERROR, $checked['issues']['location']['severity']);
        $this->assertSame('', $checked['fields']['location_id'], 'a dead id must not be sent');
    }

    public function testThePickupPointIsNamedFromOurOwnListRatherThanTheRequest(): void
    {
        $this->officeResolves();

        $fields = $this->form->validate(
            $this->fields(['delivery_type' => 'office', 'location_id' => '7', 'location' => 'whatever was typed']),
            1
        )['fields'];

        $this->assertSame('Южен, Пловдив', $fields['location']);
    }

    public function testSwitchingBackToAnAddressDropsThePickupPoint(): void
    {
        // Otherwise a stale office id travels alongside a street address, and
        // which one the carrier honours is anyone's guess.
        $this->cityResolves();
        $this->streetMatches();

        $fields = $this->form->validate($this->fields(['location_id' => '7', 'location' => 'Южен']), 1)['fields'];

        $this->assertSame('', $fields['location_id']);
        $this->assertSame('', $fields['location']);
    }

    /**
     * @dataProvider deliveryTypeProvider
     */
    public function testOnlyTheThreeRealDeliveryTypesAreAccepted(string $submitted, string $expected): void
    {
        $this->assertSame($expected, $this->form->deliveryType(['delivery_type' => $submitted]));
    }

    public function deliveryTypeProvider(): array
    {
        return [
            'address'  => ['address', 'address'],
            'office'   => ['office', 'office'],
            'locker'   => ['aps', 'aps'],
            'nonsense' => ['teleport', 'address'],
            'empty'    => ['', 'address'],
        ];
    }

    public function testOfficeDeliveryStillNeedsAPhone(): void
    {
        $issues = $this->form->validate($this->fields(['delivery_type' => 'office', 'contact_phone' => '']), 1)['issues'];

        $this->assertSame(AddressForm::SEVERITY_ERROR, $issues['contact_phone']['severity']);
    }

    // -- What arrives from the browser --------------------------------------

    public function testOnlyKnownFieldsSurviveTheRequest(): void
    {
        $fields = $this->form->fromRequest(['contact_name' => 'Иван', 'delivery_office_id' => '99']);

        $this->assertSame('Иван', $fields['contact_name']);
        $this->assertArrayNotHasKey('delivery_office_id', $fields);
    }

    public function testValuesAreTrimmed(): void
    {
        $this->assertSame('Иван Петров', $this->form->fromRequest(['contact_name' => '  Иван Петров '])['contact_name']);
    }

    /**
     * @dataProvider badIdProvider
     */
    public function testOnlyRealIdsAreKept(string $submitted): void
    {
        // Ids come from our own pickers. Anything else is dropped rather than
        // passed to the API to be rejected there.
        $fields = $this->form->fromRequest(['city_id' => $submitted, 'street_id' => $submitted]);

        $this->assertSame('', $fields['city_id']);
        $this->assertSame('', $fields['street_id']);
    }

    public function badIdProvider(): array
    {
        return [
            'injection attempt' => ['1 OR 1=1'],
            'negative'          => ['-5'],
            'zero'              => ['0'],
            'decimal'           => ['12.5'],
            'text'              => ['София'],
        ];
    }

    public function testAnArrayValueIsIgnoredRatherThanCast(): void
    {
        $this->assertSame('', $this->form->fromRequest(['contact_name' => ['a', 'b']])['contact_name']);
    }

    // -- The outgoing payload -----------------------------------------------

    public function testThePayloadCarriesEveryResolvedField(): void
    {
        $address = $this->form->toApiAddress($this->fields([
            'city_id'         => (string) self::SOFIA,
            'street_id'       => '434618',
            'building_number' => '5',
            'entrance'        => 'А',
            'floor'           => '3',
            'apartment'       => '12',
        ]));

        $this->assertSame(self::SOFIA, $address['city_id']);
        $this->assertSame(434618, $address['street_id']);
        $this->assertSame('Витоша', $address['street_name']);
        $this->assertSame('15', $address['street_number']);
        $this->assertSame('5', $address['building_number']);
        $this->assertSame('А', $address['entrance']);
        $this->assertSame('3', $address['floor']);
        $this->assertSame('12', $address['apartment']);
        $this->assertSame('Иван Петров', $address['contact_name']);
        $this->assertSame('+359899123456', $address['contact_phone']);
    }

    public function testEmptyFieldsAreLeftOutRatherThanSentBlank(): void
    {
        $address = $this->form->toApiAddress($this->fields(['building_number' => '', 'floor' => '']));

        $this->assertArrayNotHasKey('building_number', $address);
        $this->assertArrayNotHasKey('floor', $address);
    }

    public function testUnresolvedIdsAreLeftOut(): void
    {
        $address = $this->form->toApiAddress($this->fields(['city_id' => '', 'street_id' => '']));

        $this->assertArrayNotHasKey('city_id', $address);
        $this->assertArrayNotHasKey('street_id', $address);
    }

    public function testTheWrittenAddressReadsTheWayAPersonWouldWriteIt(): void
    {
        // additional_info is what a courier falls back to reading, so it has to
        // be an address rather than a dump of the form.
        $address = $this->form->toApiAddress($this->fields([
            'building_number' => '5',
            'entrance'        => 'А',
            'floor'           => '3',
            'apartment'       => '12',
        ]));

        $this->assertSame('Витоша 15, бл. 5, вх. А, ет. 3, ап. 12', $address['additional_info']);
    }

    // -- Who the carrier bills -----------------------------------------------

    public function testTheStoreSettingDecidesWhenNothingElseDoes(): void
    {
        // The fixture configures receiver pays.
        $this->assertSame(ConfigInterface::WHO_PAYS_RECEIVER, $this->form->whoPays([]));
    }

    public function testAFreeDeliveryIsBilledToTheMerchant(): void
    {
        // Billing the recipient here would have the courier collect at the door
        // for something the shop already said cost nothing.
        $this->assertSame(ConfigInterface::WHO_PAYS_SENDER, $this->form->whoPays([], null, true));
    }

    public function testAnAdminWhoPicksASideGetsItEvenOnAFreeDelivery(): void
    {
        // The shipment form has a real Who Pays select. A screen saying one thing
        // while the carrier is told another is worse than the charge the override
        // was trying to prevent.
        $this->assertSame(
            ConfigInterface::WHO_PAYS_RECEIVER,
            $this->form->whoPays(['who_pays' => ConfigInterface::WHO_PAYS_RECEIVER], null, true)
        );
    }

    public function testAnUnrecognisedSubmittedValueFallsThroughToTheFreeShippingRule(): void
    {
        $this->assertSame(
            ConfigInterface::WHO_PAYS_SENDER,
            $this->form->whoPays(['who_pays' => 'somebody_else'], null, true)
        );
    }

    // -----------------------------------------------------------------------

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function fields(array $overrides = []): array
    {
        return array_merge($this->form->blank(), [
            'contact_name'  => 'Иван Петров',
            'contact_phone' => '+359899123456',
            'city'          => 'София',
            'postal_code'   => '1000',
            'street'        => 'Витоша',
            'street_number' => '15',
        ], $overrides);
    }

    private function officeResolves(): void
    {
        $this->locations->method('resolveLocation')->willReturn([
            'entity_id' => 7,
            'api_id'    => 149,
            'name'      => 'Южен',
            'address'   => 'бул. Македония 2',
            'city'      => 'Пловдив',
            'postcode'  => '4000',
        ]);
    }

    private function cityResolves(): void
    {
        $this->resolver->method('matchCityId')->willReturn(self::SOFIA);
        $this->resolver->method('getCityById')->willReturn(
            ['id' => self::SOFIA, 'name' => 'София', 'postcode' => '1000', 'label' => 'София (1000)']
        );
    }

    private function streetMatches(bool $ambiguous = false): void
    {
        $this->resolver->method('matchStreet')->willReturn([
            'id'        => 434618,
            'name'      => 'ВИТОША',
            'name_en'   => 'VITOSHA',
            'type'      => 'улица',
            'label'     => 'улица ВИТОША',
            'ambiguous' => $ambiguous,
        ]);
    }
}
