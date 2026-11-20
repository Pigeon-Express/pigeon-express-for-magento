<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Config\Source;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Block\Adminhtml\System\Config\ReturnDocumentsField;
use PigeonExpress\Shipping\Model\Account\SavedAddresses;
use PigeonExpress\Shipping\Model\Config\Source\ReturnDocumentsTo;
use PigeonExpress\Shipping\Model\Config\Source\ReturnTo;

/**
 * A saved address as a return destination is offered only to an account
 * that has saved addresses, or where it is already the stored choice.
 */
class ReturnToTest extends TestCase
{
    public function testWithoutSavedAddressesTheChoiceIsAccountOrOffice(): void
    {
        $this->assertSame(
            ['profile', 'office'],
            $this->values(new ReturnTo($this->addresses(false), $this->scope([]), $this->request()))
        );
    }

    public function testAnAccountWithSavedAddressesIsOfferedOne(): void
    {
        $this->assertSame(
            ['profile', 'office', 'address'],
            $this->values(new ReturnTo($this->addresses(true), $this->scope([]), $this->request()))
        );
    }

    public function testAStoredAddressChoiceStaysShownAfterTheAddressesLeft(): void
    {
        $scope = $this->scope(['default' => ['carriers/pigeonexpress/return_to' => 'address']]);

        $this->assertSame(
            ['profile', 'office', 'address'],
            $this->values(new ReturnTo($this->addresses(false), $scope, $this->request()))
        );
    }

    public function testTheScopeBeingEditedIsAskedForItsChoice(): void
    {
        $scope = $this->scope(['website:2' => ['carriers/pigeonexpress/return_to' => 'address']]);
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnMap([['store', null, null], ['website', null, '2']]);

        $this->assertContains('address', $this->values(new ReturnTo($this->addresses(false), $scope, $request)));
        $this->assertNotContains(
            'address',
            $this->values(new ReturnTo($this->addresses(false), $scope, $this->request())),
            'not the default scope'
        );
    }

    public function testReturnDocumentsKeepTheirOwnChoice(): void
    {
        $scope = $this->scope(['default' => [
            'carriers/pigeonexpress/return_to' => 'office',
            'carriers/pigeonexpress/return_documents_to' => 'address',
        ]]);

        $this->assertContains(
            'address',
            $this->values(new ReturnDocumentsTo($this->addresses(false), $scope, $this->request()))
        );
        $this->assertNotContains(
            'address',
            $this->values(new ReturnTo($this->addresses(false), $scope, $this->request()))
        );
    }

    public function testTheReturnDocumentsFieldsFollowTheSwitch(): void
    {
        $this->assertSame(ConfigInterface::RETURN_DOCUMENTS_CHOICE, ReturnDocumentsField::isShown());
    }

    /**
     * The default scope being edited: no store or website in the request.
     */
    private function request(): RequestInterface
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturn(null);

        return $request;
    }

    private function addresses(bool $any): SavedAddresses
    {
        $addresses = $this->createMock(SavedAddresses::class);
        $addresses->method('hasAny')->willReturn($any);

        return $addresses;
    }

    /**
     * @param array<string, array<string, string>> $values "default" or "website:ID" / "store:ID" => path => value
     */
    private function scope(array $values): ScopeConfigInterface
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(
            static function (string $path, string $type = 'default', $code = null) use ($values) {
                $key = $type === 'default' ? 'default' : $type . ':' . $code;

                return $values[$key][$path] ?? null;
            }
        );

        return $scope;
    }

    /**
     * @return string[]
     */
    private function values(ReturnTo $source): array
    {
        return array_column($source->toOptionArray(), 'value');
    }
}
