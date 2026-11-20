<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Shipment;

use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\LocationSearchInterface;
use PigeonExpress\Shipping\Logger\Logger;
use PigeonExpress\Shipping\Model\Account\SavedAddresses;
use PigeonExpress\Shipping\Model\Shipment\ReturnDestination;

/**
 * Where an undelivered shipment returns to, as the order shows it and as a
 * change sends it again: from the body the shipment was sent with.
 */
class ReturnDestinationTest extends TestCase
{
    public function testWithNothingSentTheAccountDecides(): void
    {
        $this->assertSame(
            'As set in your Pigeon Express account',
            $this->destination()->describe(['receiver_name' => 'A'], 1)
        );
    }

    public function testAnOfficeIsNamedWithItsAddressAndTown(): void
    {
        $search = $this->createMock(LocationSearchInterface::class);
        $search->expects($this->once())->method('rowsByApiIds')
            ->with(ConfigInterface::DELIVERY_TYPE_OFFICE, [149], 1)
            ->willReturn([['name' => 'Южен', 'address' => 'бул. Македония 2', 'city' => 'Пловдив']]);

        $this->assertSame(
            'Южен - бул. Македония 2 - Пловдив',
            $this->destination([], $search)->describe(['return_office_id' => 149], 1)
        );
    }

    public function testATownTheAddressAlreadyNamesIsNotRepeated(): void
    {
        $search = $this->createMock(LocationSearchInterface::class);
        $search->method('rowsByApiIds')->willReturn([
            ['name' => 'Бушидо', 'address' => 'Пловдив, 4000, кв. Северен, САНКТ ПЕТЕРБУРГ, № 131', 'city' => 'Пловдив'],
        ]);

        $this->assertSame(
            'Бушидо - Пловдив, 4000, кв. Северен, САНКТ ПЕТЕРБУРГ, № 131',
            $this->destination([], $search)->describe(['return_office_id' => 149], 1)
        );
    }

    public function testAnOfficeNoLongerListedReadsByItsNumber(): void
    {
        $this->assertSame('Office 149', $this->destination()->describe(['return_office_id' => '149'], 1));
    }

    public function testASavedAddressReadsAsTheSettingsListIt(): void
    {
        $this->assertSame(
            'Склад (София, ул. Петър Панайотов 18)',
            $this->destination([130 => 'Склад (София, ул. Петър Панайотов 18)'])->describe(['return_address_id' => 130], 1)
        );
    }

    public function testASavedAddressThatLeftTheAccountReadsByItsNumber(): void
    {
        $this->assertSame('Address 131', $this->destination()->describe(['return_address_id' => 131], 1));
    }

    public function testTheOrderShowsOnlyWhereTheShipmentReturnsWhileDocumentsCannotBeChosen(): void
    {
        $lines = $this->destination()->lines(['return_address_id' => 131, 'return_documents_office_id' => 77], 1);

        $this->assertSame(['Return to' => 'Address 131'], $lines);
    }

    public function testTheRecordReadsEveryIdAndIgnoresJunk(): void
    {
        $this->assertSame(
            [
                'return_office_id'            => 149,
                'return_address_id'           => 0,
                'return_documents_office_id'  => 0,
                'return_documents_address_id' => 7,
            ],
            ReturnDestination::recorded([
                'return_office_id'            => '149',
                'return_address_id'           => 'abc',
                'return_documents_office_id'  => -3,
                'return_documents_address_id' => 7,
            ])
        );
    }

    public function testADroppedAddressIsOnlyOneThatWasRecordedAndNotSent(): void
    {
        $this->assertTrue(ReturnDestination::addressDropped(['return_address_id' => 131], ['note' => 'x']));
        $this->assertFalse(ReturnDestination::addressDropped(['return_address_id' => 131], ['return_address_id' => 131]));
        $this->assertFalse(ReturnDestination::addressDropped(['return_office_id' => 149], []), 'an office is never dropped');
        $this->assertFalse(ReturnDestination::addressDropped([], []), 'nothing recorded');
    }

    public function testARecordedDestinationIsSentAgainAsRecorded(): void
    {
        $this->assertSame(
            ['return_address_id' => 130],
            $this->destination([130 => 'Склад'])->fromRecord(['return_address_id' => '130'], [], 1)
        );
        $this->assertSame([], $this->destination()->fromRecord(['return_address_id' => 130], [], 1));
    }

    public function testARecordedAddressIsLeftOutOnlyWhenAListWasReadWithoutIt(): void
    {
        // A list was read, and the address is not on it: it left the account.
        $this->assertSame([], $this->destination([131 => 'Склад'])->fromRecord(['return_address_id' => 130], [], 1));
        // An account with no saved addresses left at all.
        $this->assertSame([], $this->destination([], null, true)->fromRecord(['return_address_id' => 130], [], 1));
    }

    public function testARecordedAddressIsSentAgainWhenTheListCannotBeRead(): void
    {
        // Nothing cached and the addresses call failed: no proof it is gone.
        $destination = $this->destination([], null, false);

        $this->assertSame(['return_address_id' => 130], $destination->fromRecord(['return_address_id' => 130], [], 1));
        $this->assertSame(
            ['return_office_id' => 149, 'return_documents_address_id' => 7],
            $destination->fromRecord(
                ['return_office_id' => 149, 'return_documents_address_id' => 7],
                ['service_return_documents' => true],
                1
            ),
            'the documents address the same way'
        );
    }

    public function testANewShipmentSendsOnlyAnAddressTheAccountLists(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('getReturnAddressId')->willReturn(130);

        $this->assertSame(
            ['return_address_id' => 130],
            $this->destination([130 => 'Склад'], null, true, $config)->fromSettings(1, [])
        );
        $this->assertSame([], $this->destination([], null, false, $config)->fromSettings(1, []), 'not when the list cannot be read');
        $this->assertSame([], $this->destination([131 => 'Друг'], null, true, $config)->fromSettings(1, []));
    }

    /**
     * @param array<int, string> $savedAddresses id => label the account lists
     * @param bool $readable False when the list could not be read at all (it is then empty)
     */
    private function destination(
        array $savedAddresses = [],
        ?LocationSearchInterface $search = null,
        bool $readable = true,
        ?ConfigInterface $config = null
    ): ReturnDestination {
        $addresses = $this->createMock(SavedAddresses::class);
        $addresses->method('find')->willReturnCallback(static function (int $id) use ($savedAddresses): ?array {
            return isset($savedAddresses[$id]) ? ['id' => $id, 'label' => $savedAddresses[$id]] : null;
        });
        $addresses->method('isKnown')->willReturnCallback(static function (int $id) use ($savedAddresses, $readable): ?bool {
            return isset($savedAddresses[$id]) ? true : ($readable ? false : null);
        });

        if ($search === null) {
            $search = $this->createMock(LocationSearchInterface::class);
            $search->method('rowsByApiIds')->willReturn([]);
        }

        return new ReturnDestination(
            $config ?? $this->createMock(ConfigInterface::class),
            $addresses,
            $search,
            $this->createMock(Logger::class)
        );
    }
}
