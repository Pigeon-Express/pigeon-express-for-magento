<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Api\LocationSearchInterface;
use PigeonExpress\Shipping\Controller\Adminhtml\Order\CitySearch;
use PigeonExpress\Shipping\Controller\Adminhtml\Order\LocationSearch;
use PigeonExpress\Shipping\Controller\Adminhtml\Order\StreetSearch;
use PigeonExpress\Shipping\Model\Api\AddressResolver;

/**
 * The office, locker, city and street lookups serve the shipment form and
 * the change of delivery place in Quick changes: a role with either right
 * may use them, a role with neither may not.
 */
class PlaceLookupAclTest extends TestCase
{
    /**
     * @dataProvider roles
     *
     * @param string[] $granted
     */
    public function testEitherRightOpensTheLookups(array $granted, bool $allowed): void
    {
        foreach ([LocationSearch::class, CitySearch::class, StreetSearch::class] as $class) {
            $this->assertSame($allowed, $this->isAllowed($this->controller($class, $granted)), $class);
        }
    }

    /**
     * @return array<string, array{0: string[], 1: bool}>
     */
    public function roles(): array
    {
        return [
            'sends shipments'      => [['PigeonExpress_Shipping::send_shipment'], true],
            'makes quick changes'  => [['PigeonExpress_Shipping::quick_change'], true],
            'both'                 => [['PigeonExpress_Shipping::send_shipment', 'PigeonExpress_Shipping::quick_change'], true],
            'only reads shipments' => [['PigeonExpress_Shipping::shipment_details'], false],
            'nothing'              => [[], false],
        ];
    }

    /**
     * @param class-string<Action> $class
     * @param string[] $granted
     */
    private function controller(string $class, array $granted): Action
    {
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(static function ($resource) use ($granted): bool {
            return in_array($resource, $granted, true);
        });
        $context = $this->createMock(Context::class);
        $context->method('getAuthorization')->willReturn($authorization);
        $json = $this->createMock(JsonFactory::class);

        switch ($class) {
            case LocationSearch::class:
                return new LocationSearch(
                    $context,
                    $json,
                    $this->createMock(LocationSearchInterface::class),
                    $this->createMock(OrderRepositoryInterface::class)
                );
            case CitySearch::class:
                return new CitySearch($context, $json, $this->createMock(AddressResolver::class));
            default:
                return new StreetSearch(
                    $context,
                    $json,
                    $this->createMock(AddressResolver::class),
                    $this->createMock(OrderRepositoryInterface::class)
                );
        }
    }

    private function isAllowed(Action $controller): bool
    {
        $method = new \ReflectionMethod($controller, '_isAllowed');
        $method->setAccessible(true);

        return (bool) $method->invoke($controller);
    }
}
