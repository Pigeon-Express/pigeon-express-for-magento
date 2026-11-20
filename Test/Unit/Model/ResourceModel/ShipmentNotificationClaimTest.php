<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Model\ResourceModel\Db\Context;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Model\ResourceModel\Shipment as ShipmentResource;
use PigeonExpress\Shipping\Model\Shipment;

/**
 * The customer is emailed a redirect's new number once: the claim is one
 * conditional UPDATE, and only the caller that changed the row sends.
 */
class ShipmentNotificationClaimTest extends TestCase
{
    public function testOnlyTheCallerThatSetTheMarkerHasTheClaim(): void
    {
        $calls = [];
        $resource = $this->resource([1, 0], $calls);

        $this->assertTrue($resource->claimCustomerNotification(42, '2026-11-20 10:00:00'));
        $this->assertFalse($resource->claimCustomerNotification(42), 'already claimed');

        $this->assertSame('pigeonexpress_shipment', $calls[0][0]);
        $this->assertSame(['customer_notified_at' => '2026-11-20 10:00:00'], $calls[0][1]);
        $this->assertSame(['entity_id = ?' => 42, 'customer_notified_at IS NULL'], $calls[0][2]);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $calls[1][1]['customer_notified_at']);
    }

    public function testNoRowNoClaim(): void
    {
        $calls = [];
        $resource = $this->resource([], $calls);

        $this->assertFalse($resource->claimCustomerNotification(0));
        $this->assertSame([], $calls);
    }

    public function testAClaimCanBeGivenBack(): void
    {
        $calls = [];
        $resource = $this->resource([1], $calls);

        $resource->releaseCustomerNotification(42);

        $this->assertSame(['customer_notified_at' => null], $calls[0][1]);
        $this->assertSame(['entity_id = ?' => 42], $calls[0][2]);
    }

    public function testTheModelReadsTheNewColumns(): void
    {
        $row = $this->createPartialMock(Shipment::class, []);
        $this->assertNull($row->getRedirectedFrom());
        $this->assertNull($row->getCustomerNotifiedAt());

        $row->setRedirectedFrom(' 459073686609 ')->setCustomerNotifiedAt('2026-11-20 10:00:00');
        $this->assertSame('459073686609', $row->getRedirectedFrom());
        $this->assertSame('2026-11-20 10:00:00', $row->getCustomerNotifiedAt());
        $this->assertSame('redirected', Shipment::STATUS_REDIRECTED);
    }

    /**
     * @param int[] $affected What each UPDATE reports
     * @param array<int, array{0: string, 1: array, 2: array}> $calls
     */
    private function resource(array $affected, array &$calls): ShipmentResource
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('update')->willReturnCallback(
            function (string $table, array $bind, $where) use (&$affected, &$calls): int {
                $calls[] = [$table, $bind, $where];

                return (int) array_shift($affected);
            }
        );

        $resources = $this->createMock(ResourceConnection::class);
        $resources->method('getConnection')->willReturn($connection);
        $resources->method('getTableName')->willReturnArgument(0);

        $context = $this->createMock(Context::class);
        $context->method('getResources')->willReturn($resources);

        return new ShipmentResource($context);
    }
}
