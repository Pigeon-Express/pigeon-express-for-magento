<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Api;

use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Model\Api\AddressResolver;

/**
 * Pigeon Express identifies a recipient by phone number. An address block it
 * cannot resolve gets quietly replaced with whatever address it already holds
 * for that number, so the parcel goes somewhere else while the label looks
 * correct. Every field that can be proven has to be sent.
 *
 * These cover the pure parsing. The parts that need a city lookup or the API are
 * exercised by the live verification script instead.
 */
class AddressResolverTest extends TestCase
{
    /**
     * @dataProvider componentProvider
     * @param array<string, string> $expected
     */
    public function testAddressComponentsAreExtracted(string $address, array $expected): void
    {
        $this->assertSame($expected, $this->extractComponents($address));
    }

    public function componentProvider(): array
    {
        return [
            'bulgarian abbreviations' => [
                'ул. Витоша 15, бл. 5, вх. А, ет. 3, ап. 12',
                ['building_number' => '5', 'entrance' => 'А', 'floor' => '3', 'apartment' => '12'],
            ],
            'full bulgarian words' => [
                'бул. България 100, блок 22, вход Б, етаж 7, апартамент 45',
                ['building_number' => '22', 'entrance' => 'Б', 'floor' => '7', 'apartment' => '45'],
            ],
            'latin abbreviations' => [
                'ul. Vitosha 15, bl. 5, vh. A, et. 3, ap. 12',
                ['building_number' => '5', 'entrance' => 'A', 'floor' => '3', 'apartment' => '12'],
            ],
            'english words' => [
                'Vitosha 15, block 9, entrance C, floor 2, apartment 8',
                ['building_number' => '9', 'entrance' => 'C', 'floor' => '2', 'apartment' => '8'],
            ],
            'no separators' => [
                'ул. Витоша 15 бл.5 вх.А ет.3 ап.12',
                ['building_number' => '5', 'entrance' => 'А', 'floor' => '3', 'apartment' => '12'],
            ],
            'building with a letter' => [
                'ж.к. Люлин, бл. 12А, ет. 4',
                ['building_number' => '12А', 'floor' => '4'],
            ],
            'partial, only what is present' => [
                'ул. Раковски 42, ет. 2',
                ['floor' => '2'],
            ],
            'plain street, nothing to extract' => [
                'ул. Раковски 42',
                [],
            ],
            'empty' => ['', []],
        ];
    }

    /**
     * @dataProvider streetLineProvider
     */
    public function testTheStreetIsSeparatedFromTheBuildingDetails(string $line, string $expected): void
    {
        // Customers put the whole address on one line. Split naively, the street
        // name comes out as "Витоша 15, бл. 5, вх. А, ет. 3, ап." and the house
        // number as the flat number, so nothing matches and the carrier falls
        // back to whatever address it holds for the phone.
        $method = (new \ReflectionClass(AddressResolver::class))->getMethod('cutAtAddressComponents');
        $method->setAccessible(true);

        $this->assertSame($expected, $method->invoke($this->resolver(), $line));
    }

    public function streetLineProvider(): array
    {
        return [
            'everything on one line' => ['ул. Витоша 15, бл. 5, вх. А, ет. 3, ап. 12', 'ул. Витоша 15'],
            'no separator'           => ['ул. Витоша 15 бл.5 вх.А', 'ул. Витоша 15'],
            'floor only'             => ['ул. Раковски 42, ет. 2', 'ул. Раковски 42'],
            'block first'            => ['ж.к. Люлин, бл. 12А, ет. 4', 'ж.к. Люлин'],
            'nothing to cut'         => ['ул. Раковски 42', 'ул. Раковски 42'],
            'latin'                  => ['ul. Vitosha 15, bl. 5, et. 3', 'ul. Vitosha 15'],
            'a street called Street' => ['Main Street 4', 'Main Street 4'],
            'empty'                  => ['', ''],
        ];
    }

    /**
     * @dataProvider additionalInfoProvider
     */
    public function testAdditionalInfoAlwaysMeetsTheApiMinimum(string $whole, string $street, string $expected): void
    {
        // The API rejects additional_info shorter than 3 characters whenever
        // street_id is absent, which is exactly when the address is weakest.
        $this->assertSame($expected, $this->buildAdditionalInfo($whole, $street));
    }

    public function additionalInfoProvider(): array
    {
        return [
            'full address wins'      => ['ул. Витоша 15, ет. 3', 'Витоша', 'ул. Витоша 15, ет. 3'],
            'falls back to street'   => ['', 'Витоша', 'Витоша'],
            'too short falls back'   => ['ab', 'Витоша', 'Витоша'],
            'nothing usable'         => ['', '', 'Address'],
            'both too short'         => ['a', 'b', 'Address'],
        ];
    }

    public function testAdditionalInfoIsCappedForTheApi(): void
    {
        $long = str_repeat('щ', 400);

        $this->assertSame(255, mb_strlen($this->buildAdditionalInfo($long, '')));
    }

    /**
     * @dataProvider streetProvider
     */
    public function testStreetNameAndNumberAreSeparated(string $input, string $name, string $number): void
    {
        $method = (new \ReflectionClass(AddressResolver::class))->getMethod('extractStreetNameAndNumber');
        $method->setAccessible(true);

        $this->assertSame([$name, $number], $method->invoke($this->resolver(), $input));
    }

    public function streetProvider(): array
    {
        return [
            'number with hash'   => ['ул. Витоша № 15', 'ул. Витоша', '15'],
            'number after comma' => ['ул. Витоша, 15', 'ул. Витоша', '15'],
            'number after space' => ['ул. Витоша 15', 'ул. Витоша', '15'],
            'number with letter' => ['ул. Витоша 15А', 'ул. Витоша', '15А'],
            'no number at all'   => ['ул. Витоша', 'ул. Витоша', ''],
        ];
    }

    // -----------------------------------------------------------------------

    private function resolver(): AddressResolver
    {
        return (new \ReflectionClass(AddressResolver::class))->newInstanceWithoutConstructor();
    }

    /** @return array<string, string> */
    private function extractComponents(string $address): array
    {
        $method = (new \ReflectionClass(AddressResolver::class))->getMethod('extractAddressComponents');
        $method->setAccessible(true);

        return $method->invoke($this->resolver(), $address);
    }

    private function buildAdditionalInfo(string $whole, string $street): string
    {
        $method = (new \ReflectionClass(AddressResolver::class))->getMethod('buildAdditionalInfo');
        $method->setAccessible(true);

        return $method->invoke($this->resolver(), $whole, $street);
    }

    /**
     * @dataProvider prefixProvider
     */
    public function testStreetTypePrefixIsStrippedBeforeLookup(string $input, string $expected): void
    {
        // The carrier holds "ВИТОША" with the type in a separate field. Searching
        // for "ул. Витоша" finds nothing, and the address then degrades to a
        // name-only lookup that the carrier resolves from the phone number
        // instead, delivering to whatever address it already had.
        $method = (new \ReflectionClass(AddressResolver::class))->getMethod('stripStreetTypePrefix');
        $method->setAccessible(true);

        $this->assertSame($expected, $method->invoke($this->resolver(), $input));
    }

    public function prefixProvider(): array
    {
        return [
            'ulitsa abbreviated'   => ['ул. Витоша', 'Витоша'],
            'ulitsa spelled out'   => ['улица Раковски', 'Раковски'],
            'boulevard'            => ['бул. България', 'България'],
            'square'               => ['пл. Славейков', 'Славейков'],
            'housing complex'      => ['ж.к. Люлин', 'Люлин'],
            'latin abbreviation'   => ['ul. Vitosha', 'Vitosha'],
            'no prefix at all'     => ['Vitosha', 'Vitosha'],
            'trailing type is safe'=> ['Main Street', 'Main Street'],
            'name that is a type'  => ['Булевард', 'Булевард'],
        ];
    }
}
