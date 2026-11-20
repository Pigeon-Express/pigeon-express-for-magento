<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Shipment;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PigeonExpress\DTO\Response\ShipmentDetails;
use PigeonExpress\Shipping\Api\ShipmentClientInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use PigeonExpress\Shipping\Model\Magento\OrderComment;
use PigeonExpress\Shipping\Model\Magento\RedirectNotifier;
use PigeonExpress\Shipping\Model\ResourceModel\Shipment as ShipmentResource;
use PigeonExpress\Shipping\Model\Shipment;
use PigeonExpress\Shipping\Model\Shipment\OrderLock;
use PigeonExpress\Shipping\Model\Shipment\RedirectFollower;
use PigeonExpress\Shipping\Model\ShipmentCanceller;
use PigeonExpress\Shipping\Model\ShipmentFactory;
use PigeonExpress\Shipping\Model\Status\ShipmentDetailsStore;
use PigeonExpress\Shipping\Model\Status\StatusCodes;
use Psr\Log\LoggerInterface;

/**
 * The order follows a redirected shipment to the new one: only once the new
 * shipment can be read, with a new row, the old row kept as history, the
 * Magento track replaced (event), a comment, and the customer emailed once.
 */
class RedirectFollowerTest extends TestCase
{
    /** @var array<string, array<string, mixed>|null> Reference => GET answer, null = unreadable */
    private $answers = [];

    /** @var Shipment */
    private $current;

    /** @var array<int, array<string, mixed>> Rows saved */
    private $saved = [];

    /** @var array<int, array<string, mixed>> Plain updates */
    private $updates = [];

    /** @var array<int, array<string, mixed>> Events */
    private $events = [];

    /** @var RedirectNotifier&MockObject */
    private $notifier;

    /** @var OrderComment&MockObject */
    private $comments;

    /** @var bool */
    private $lockFree = true;

    protected function setUp(): void
    {
        $this->current = $this->row(['id' => 5, 'order_id' => 9, 'reference_number' => 'A1', 'status' => 'shipment_registered',
            'status_code' => 'shipment_redirected', 'payload' => '{"return_office_id":55,"delivery_type":"office"}',
            'form_fields' => '{"delivery_type":"office"}']);
        $this->notifier = $this->createMock(RedirectNotifier::class);
        $this->comments = $this->createMock(OrderComment::class);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function row(array $data = []): Shipment
    {
        $row = $this->getMockBuilder(Shipment::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $row->setData($data);

        return $row;
    }

    private function follower(): RedirectFollower
    {
        $client = $this->createMock(ShipmentClientInterface::class);
        $client->method('get')->willReturnCallback(function (string $reference) {
            $answer = $this->answers[$reference] ?? null;
            if ($answer === null) {
                throw new ApiException(__('Not readable'));
            }

            return ShipmentDetails::fromArray($answer);
        });

        $finder = $this->createMock(ShipmentCanceller::class);
        $finder->method('findShipment')->willReturnCallback(function () {
            return $this->current;
        });

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('update')->willReturnCallback(function (string $table, array $values, array $where): int {
            $this->updates[] = ['values' => $values, 'where' => $where];

            return 1;
        });
        $resource = $this->createMock(ShipmentResource::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getMainTable')->willReturn('pigeonexpress_shipment');
        $resource->method('save')->willReturnCallback(function (Shipment $row) use ($resource) {
            $row->setId(77);
            $this->saved[] = $row->getData();

            return $resource;
        });

        $factory = $this->createMock(ShipmentFactory::class);
        $factory->method('create')->willReturnCallback(function () {
            return $this->row();
        });

        $lock = $this->createMock(OrderLock::class);
        $lock->method('acquire')->willReturnCallback(function (): bool {
            return $this->lockFree;
        });

        $orders = $this->createMock(OrderRepositoryInterface::class);
        $orders->method('get')->willReturn($this->getMockBuilder(Order::class)->disableOriginalConstructor()->onlyMethods([])->getMock());

        $events = $this->createMock(ManagerInterface::class);
        $events->method('dispatch')->willReturnCallback(function (string $name, array $data): void {
            $this->events[] = ['name' => $name] + $data;
        });

        return new RedirectFollower(
            $client,
            $finder,
            $this->createMock(ShipmentDetailsStore::class),
            $resource,
            $factory,
            $lock,
            $orders,
            $events,
            $this->comments,
            $this->notifier,
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testTheOrderMovesToTheReadableNewShipment(): void
    {
        $this->answers = [
            'A1' => ['reference_number' => 'A1', 'related_shipments' => [['reference_number' => 'B2', 'relation' => 'redirected']]],
            'B2' => ['reference_number' => 'B2', 'packages' => [['tracking_number' => 'B2-1']], 'pricing' => ['total' => 6.4],
                'related_shipments' => [['reference_number' => 'A1', 'relation' => 'original']]],
        ];
        $this->notifier->expects(self::once())->method('notify')->willReturn(true);
        $this->comments->expects(self::once())->method('add')->with(self::anything(), self::callback(static function (string $text): bool {
            return strpos($text, 'Pigeon Express shipment A1 was redirected; the new shipment is B2.') === 0;
        }));

        self::assertSame('B2', $this->follower()->follow(9, 'A1'));

        self::assertCount(1, $this->saved);
        self::assertSame('B2', $this->saved[0]['reference_number']);
        self::assertSame('B2-1', $this->saved[0]['tracking_number']);
        self::assertSame('A1', $this->saved[0]['redirected_from']);
        self::assertSame(6.4, $this->saved[0]['delivery_price']);
        // The recorded return destination goes with the order.
        self::assertSame(55, json_decode($this->saved[0]['payload'], true)['return_office_id']);

        self::assertSame(Shipment::STATUS_REDIRECTED, $this->updates[0]['values']['status']);
        self::assertSame(StatusCodes::REDIRECTED, $this->updates[0]['values']['status_code']);
        self::assertSame(['entity_id = ?' => 5], $this->updates[0]['where']);

        self::assertSame(RedirectFollower::EVENT_REDIRECTED, $this->events[0]['name']);
        self::assertSame('A1', $this->events[0]['old_row']['reference_number']);
        self::assertSame('B2', $this->events[0]['shipment_row']['reference_number']);
    }

    public function testAnUnreadableNewShipmentMovesNothing(): void
    {
        $this->answers = [
            'A1' => ['reference_number' => 'A1', 'related_shipments' => [['reference_number' => 'B2', 'relation' => 'redirected']]],
            'B2' => null,
        ];
        $this->notifier->expects(self::never())->method('notify');

        self::assertSame('', $this->follower()->follow(9, 'A1'));
        self::assertSame([], $this->saved);
        self::assertSame([], $this->events);
    }

    public function testAnAnswerForAnotherShipmentIsNotTheTarget(): void
    {
        $this->answers = [
            'A1' => ['reference_number' => 'A1', 'related_shipments' => [['reference_number' => 'B2', 'relation' => 'redirected']]],
            'B2' => ['reference_number' => 'C3'],
        ];

        self::assertSame('', $this->follower()->follow(9, 'A1'));
        self::assertSame([], $this->saved);
    }

    public function testNothingMovesWhenTheOrderHoldsAnotherShipmentOrIsBusy(): void
    {
        $this->answers = [
            'A1' => ['reference_number' => 'A1', 'related_shipments' => [['reference_number' => 'B2', 'relation' => 'redirected']]],
            'B2' => ['reference_number' => 'B2'],
        ];

        // Moved already (a replay, Refresh after the quick change): nothing again.
        self::assertSame('', $this->follower()->follow(9, 'Z9'));

        $this->lockFree = false;
        self::assertSame('', $this->follower()->follow(9, 'A1'));
        self::assertSame([], $this->saved);
    }

    public function testRedirectsOfRedirectsAreFollowedToTheEndWithinTheLimit(): void
    {
        $refs = ['A1', 'B2', 'C3', 'D4', 'E5', 'F6', 'G7', 'H8'];
        foreach ($refs as $i => $ref) {
            $next = $refs[$i + 1] ?? null;
            $this->answers[$ref] = ['reference_number' => $ref, 'related_shipments' => $next !== null
                ? [['reference_number' => $next, 'relation' => 'redirected']] : []];
        }

        $found = $this->follower()->activeRedirect('A1');

        self::assertSame('F6', $found['reference']);
        self::assertSame(['B2', 'C3', 'D4', 'E5', 'F6'], $found['path']);
        self::assertCount(RedirectFollower::MAX_HOPS, $found['path']);

        // A chain leading back to where it started stops before the loop.
        $this->answers['C3']['related_shipments'] = [['reference_number' => 'A1', 'relation' => 'redirected']];
        self::assertSame('C3', $this->follower()->activeRedirect('A1')['reference']);
    }

    public function testTheRedirectLinkIsReadFromEitherShape(): void
    {
        self::assertSame('B2', RedirectFollower::redirectOf(['related_shipments' => [
            ['reference_number' => 'A0', 'relation' => 'original'],
            ['reference_number' => 'B2', 'relation' => 'redirected'],
        ]]));
        self::assertSame('B2', RedirectFollower::redirectOf(['related' => [['reference' => 'B2', 'relation' => 'redirected']]]));
        self::assertSame('', RedirectFollower::redirectOf(['related' => [['reference' => 'B2', 'relation' => 'return_to_sender']]]));
    }
}
