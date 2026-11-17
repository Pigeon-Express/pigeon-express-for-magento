<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Phone;

use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Model\Phone\BulgarianPhone;

class BulgarianPhoneTest extends TestCase
{
    /**
     * @dataProvider valid
     */
    public function testAcceptsBulgarianNumbers(string $phone): void
    {
        $this->assertTrue(BulgarianPhone::isValid($phone));
    }

    /**
     * @dataProvider invalid
     */
    public function testRefusesOthers(string $phone): void
    {
        $this->assertFalse(BulgarianPhone::isValid($phone));
    }

    public function testNormalisesToTheCountryCode(): void
    {
        $this->assertSame('359881234567', BulgarianPhone::normalize('0881 234 567'));
        $this->assertSame('359881234567', BulgarianPhone::normalize('00359881234567'));
        $this->assertSame('359881234567', BulgarianPhone::normalize('881234567'));
        $this->assertSame('35929812345', BulgarianPhone::normalize('02 981 2345'));
    }

    /**
     * @return array<string, array{string}>
     */
    public function valid(): array
    {
        return [
            'mobile with code'   => ['+359 88 123 4567'],
            'mobile dashes'      => ['+359-88-123-4567'],
            'mobile 00'          => ['00359881234567'],
            'mobile local'       => ['0881234567'],
            'mobile bare'        => ['881234567'],
            'mobile 98'          => ['0981234567'],
            'landline Sofia'     => ['02 981 2345'],
            'landline Plovdiv'   => ['032 123 456'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public function invalid(): array
    {
        return [
            'empty'          => [''],
            'letters'        => ['n/a'],
            'too short'      => ['08812'],
            'foreign'        => ['+44 20 7946 0958'],
            'bad mobile'     => ['0851234567'],
            'too long'       => ['08812345678'],
        ];
    }
}
