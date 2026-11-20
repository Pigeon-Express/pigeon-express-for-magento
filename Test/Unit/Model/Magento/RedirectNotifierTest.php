<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Magento;

use Magento\Framework\App\Area;
use Magento\Framework\Phrase;
use Magento\Framework\Phrase\Renderer\Placeholder;
use Magento\Framework\Phrase\RendererInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderCommentSender;
use Magento\Sales\Model\Order\Email\Sender\OrderCommentSenderFactory;
use Magento\Store\Model\App\Emulation;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Model\Magento\OrderComment;
use PigeonExpress\Shipping\Model\Magento\RedirectNoticeIdentity;
use PigeonExpress\Shipping\Model\Magento\RedirectNotifier;
use PigeonExpress\Shipping\Model\Magento\TrackingUrl;
use PigeonExpress\Shipping\Model\ResourceModel\Shipment as ShipmentResource;
use PigeonExpress\Shipping\Model\Shipment;
use Psr\Log\LoggerInterface;

/**
 * The customer hears of the new number once: whoever claims the row sends,
 * nobody else; a failed send gives the claim back and leaves no visible comment.
 * What the customer reads is worded in the order store's language, whoever
 * (an admin, the cron) caused it.
 */
class RedirectNotifierTest extends TestCase
{
    /** @var ShipmentResource&MockObject */
    private $resource;

    /** @var OrderComment&MockObject */
    private $comments;

    /** @var OrderCommentSender&MockObject */
    private $sender;

    /** @var array<string, mixed>|null */
    private $factoryArgs;

    /** @var Emulation&MockObject */
    private $emulation;

    /** @var RendererInterface|null */
    private $rendererBefore;

    /** @var bool Whether the store is being emulated right now */
    private $emulating = false;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ShipmentResource::class);
        $this->comments = $this->createMock(OrderComment::class);
        $this->sender = $this->createMock(OrderCommentSender::class);
        $this->rendererBefore = Phrase::getRenderer();
        // The acting admin's (or the cron's) language.
        Phrase::setRenderer($this->renderer('[admin] '));

        // Like Magento's: the store's language for as long as it lasts.
        $this->emulation = $this->createMock(Emulation::class);
        $this->emulation->method('startEnvironmentEmulation')->willReturnCallback(function () {
            $this->emulating = true;
            Phrase::setRenderer($this->renderer('[store] '));
        });
        $this->emulation->method('stopEnvironmentEmulation')->willReturnCallback(function () {
            if ($this->emulating) {
                $this->emulating = false;
                Phrase::setRenderer($this->renderer('[admin] '));
            }

            return $this->emulation;
        });
    }

    protected function tearDown(): void
    {
        Phrase::setRenderer($this->rendererBefore ?? new Placeholder());
    }

    private function renderer(string $language): RendererInterface
    {
        return new class ($language) implements RendererInterface {
            /** @var string */
            private $language;

            public function __construct(string $language)
            {
                $this->language = $language;
            }

            public function render(array $source, array $arguments)
            {
                return $this->language . (new Placeholder())->render($source, $arguments);
            }
        };
    }

    private function notifier(): RedirectNotifier
    {
        $factory = $this->createMock(OrderCommentSenderFactory::class);
        $factory->method('create')->willReturnCallback(function (array $args) {
            $this->factoryArgs = $args;

            return $this->sender;
        });

        return new RedirectNotifier(
            $this->resource,
            $this->comments,
            new TrackingUrl(),
            $factory,
            $this->getMockBuilder(RedirectNoticeIdentity::class)->disableOriginalConstructor()->onlyMethods([])->getMock(),
            $this->createMock(LoggerInterface::class),
            $this->emulation
        );
    }

    private function row(array $data = []): Shipment
    {
        $row = $this->getMockBuilder(Shipment::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $row->setData($data + ['id' => 77, 'reference_number' => 'B2', 'redirected_from' => 'A1']);

        return $row;
    }

    private function order(int $storeId = 2): Order
    {
        $order = $this->getMockBuilder(Order::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $order->setData('store_id', $storeId);

        return $order;
    }

    public function testTheClaimantSendsTheNewNumberWhateverTheEmailSetting(): void
    {
        $this->resource->expects(self::once())->method('claimCustomerNotification')->with(77)->willReturn(true);
        $this->resource->expects(self::never())->method('releaseCustomerNotification');
        $this->sender->expects(self::once())->method('send')->with(
            self::anything(),
            true,
            self::callback(static function (string $text): bool {
                return strpos($text, 'has a new number: B2.') !== false
                    && strpos($text, 'https://track.pigeonexpress.com/?tracking_number=B2') !== false;
            })
        )->willReturn(true);
        $this->comments->expects(self::once())->method('addForCustomer')->with(self::anything(), self::anything(), true);

        $row = $this->row();
        self::assertTrue($this->notifier()->notify($this->order(), $row));
        self::assertNotNull($row->getCustomerNotifiedAt());
        // Magento's comment email, with the identity that ignores the on/off switch.
        self::assertInstanceOf(RedirectNoticeIdentity::class, $this->factoryArgs['identityContainer']);
        self::assertTrue($this->factoryArgs['identityContainer']->isEnabled());
    }

    public function testNobodyElseSendsItAgain(): void
    {
        $this->resource->method('claimCustomerNotification')->willReturn(false);
        $this->sender->expects(self::never())->method('send');
        $this->comments->expects(self::never())->method('addForCustomer');

        self::assertFalse($this->notifier()->notify($this->order(), $this->row()));
    }

    public function testAFailedSendGivesTheClaimBack(): void
    {
        $this->resource->method('claimCustomerNotification')->willReturn(true);
        $this->resource->expects(self::once())->method('releaseCustomerNotification')->with(77);
        $this->sender->method('send')->willThrowException(new \RuntimeException('mail server down'));
        $this->comments->expects(self::never())->method('addForCustomer');
        $this->comments->expects(self::once())->method('add');

        self::assertFalse($this->notifier()->notify($this->order(), $this->row()));
    }

    public function testRefreshRetriesOnlyAnEmailStillOwed(): void
    {
        $this->resource->expects(self::once())->method('claimCustomerNotification')->willReturn(true);
        $this->sender->method('send')->willReturn(true);
        $notifier = $this->notifier();

        self::assertFalse($notifier->retryPending($this->order(), $this->row(['redirected_from' => null])));
        self::assertFalse($notifier->retryPending($this->order(), $this->row(['customer_notified_at' => '2026-11-20 10:00:00'])));
        self::assertTrue($notifier->retryPending($this->order(), $this->row()));
    }

    public function testTheCustomerReadsTheNewNumberInTheLanguageOfTheOrdersStore(): void
    {
        $this->resource->method('claimCustomerNotification')->willReturn(true);
        $this->emulation->expects(self::once())->method('startEnvironmentEmulation')
            ->with(2, Area::AREA_FRONTEND, true);
        $this->emulation->expects(self::once())->method('stopEnvironmentEmulation');

        $emailed = null;
        $this->sender->expects(self::once())->method('send')->willReturnCallback(
            function (Order $order, bool $notify, string $text) use (&$emailed): bool {
                // The sender emulates the store itself, and only one can run.
                self::assertFalse($this->emulating, 'the emulation ends before the email is sent');
                $emailed = $text;

                return true;
            }
        );
        $visible = null;
        $this->comments->expects(self::once())->method('addForCustomer')->willReturnCallback(
            static function ($order, string $text) use (&$visible): bool {
                $visible = $text;

                return true;
            }
        );

        self::assertTrue($this->notifier()->notify($this->order(2), $this->row()));

        self::assertIsString($emailed);
        self::assertStringStartsWith('[store] The delivery of your order was changed', $emailed);
        self::assertSame($emailed, $visible, 'the comment in My Account is the email text');
        self::assertSame('[admin] x', (string) __('x'), 'the admin language is back afterwards');
    }

    public function testTheMerchantsOwnCommentStaysInTheAdminsLanguage(): void
    {
        $this->resource->method('claimCustomerNotification')->willReturn(true);
        $this->sender->method('send')->willReturn(false);
        $this->emulation->expects(self::once())->method('stopEnvironmentEmulation');
        $private = null;
        $this->comments->expects(self::once())->method('add')->willReturnCallback(
            static function ($order, string $text) use (&$private): void {
                $private = $text;
            }
        );

        self::assertFalse($this->notifier()->notify($this->order(2), $this->row()));
        self::assertIsString($private);
        self::assertStringStartsWith('[admin] The customer could not be emailed', $private);
    }

    public function testTheEmulationAlwaysEndsAndAFailureGivesTheClaimBack(): void
    {
        $this->resource->method('claimCustomerNotification')->willReturn(true);
        $this->resource->expects(self::once())->method('releaseCustomerNotification')->with(77);
        // Fails half way, as a store that cannot be emulated would.
        $this->emulation->expects(self::once())->method('startEnvironmentEmulation')->willReturnCallback(function () {
            $this->emulating = true;
            Phrase::setRenderer($this->renderer('[store] '));

            throw new \RuntimeException('store cannot be emulated');
        });
        $this->emulation->expects(self::once())->method('stopEnvironmentEmulation');
        $this->sender->expects(self::never())->method('send');
        $this->comments->expects(self::never())->method('addForCustomer');

        self::assertFalse($this->notifier()->notify($this->order(2), $this->row()));
        self::assertFalse($this->emulating);
    }

    public function testAnOrderWithoutAStoreIsNotEmulated(): void
    {
        $this->resource->method('claimCustomerNotification')->willReturn(true);
        $this->emulation->expects(self::never())->method('startEnvironmentEmulation');
        $this->sender->expects(self::once())->method('send')
            ->with(self::anything(), true, self::stringStartsWith('[admin] The delivery of your order'))
            ->willReturn(true);

        self::assertTrue($this->notifier()->notify($this->order(0), $this->row()));
    }
}
