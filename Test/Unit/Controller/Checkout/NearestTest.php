<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Controller\Checkout;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Controller\Checkout\Nearest;
use PigeonExpress\Shipping\Model\Location\NearestLocations;
use PigeonExpress\Shipping\Model\Location\NearestRefusedException;

/**
 * The public nearest endpoint: only with the setting on, the page's form key
 * and a cart; the point only from the POST body; refusals carry the status
 * the picker reads (nothing is shown to the shopper for any of them).
 */
class NearestTest extends TestCase
{
    /** @var array<string, mixed> */
    private $post = ['form_key' => 'k', 'type' => 'office', 'lat' => '42.1425', 'lng' => '24.7495'];

    /** @var array<string, mixed> */
    private $query = [];

    /** @var bool */
    private $enabled = true;

    /** @var bool */
    private $formKeyValid = true;

    /** @var int|null */
    private $quoteId = 7;

    /** @var NearestLocations&MockObject */
    private $nearest;

    /** @var array{status: int, data: array<string, mixed>} */
    private $response = ['status' => 200, 'data' => []];

    protected function setUp(): void
    {
        $this->nearest = $this->createMock(NearestLocations::class);
    }

    public function testFindsTheNearestForTheCartAndTheAddress(): void
    {
        $rows = [['id' => 86, 'name' => 'Лиляна', 'distance' => '630 m']];
        $this->nearest->expects($this->once())->method('find')
            ->with('office', 42.143, 24.75, 'quote:7', '203.0.113.5', 3)
            ->willReturn($rows);

        $this->dispatch();

        $this->assertSame(200, $this->response['status']);
        $this->assertSame(['success' => true, 'locations' => $rows], $this->response['data']);
    }

    public function testNothingWhileTheSettingIsOff(): void
    {
        $this->enabled = false;
        $this->nearest->expects($this->never())->method('find');

        $this->dispatch();

        $this->assertSame(400, $this->response['status']);
        $this->assertSame(['success' => false, 'message' => 'Unknown request.'], $this->response['data']);
    }

    public function testAWrongFormKeyIsRefused(): void
    {
        $this->formKeyValid = false;
        $this->nearest->expects($this->never())->method('find');

        $this->dispatch();

        $this->assertSame(403, $this->response['status']);
        $this->assertFalse($this->response['data']['success']);
    }

    public function testASessionWithoutACartIsRefused(): void
    {
        $this->quoteId = null;
        $this->nearest->expects($this->never())->method('find');

        $this->dispatch();

        $this->assertSame(400, $this->response['status']);
        $this->assertSame(
            'The nearest locations are not available right now. Reload the page and try again.',
            $this->response['data']['message']
        );
    }

    public function testAnUnknownTypeIsRefused(): void
    {
        $this->post['type'] = 'address';
        $this->nearest->expects($this->never())->method('find');

        $this->dispatch();

        $this->assertSame(400, $this->response['status']);
        $this->assertSame('Unknown location type.', $this->response['data']['message']);
    }

    /**
     * @dataProvider badPoints
     *
     * @param mixed $lat
     */
    public function testAPointThatIsNotANumberIsRefused($lat): void
    {
        $this->post['lat'] = $lat;
        $this->nearest->expects($this->never())->method('find');

        $this->dispatch();

        $this->assertSame(400, $this->response['status']);
        $this->assertSame('The location could not be read.', $this->response['data']['message']);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public function badPoints(): array
    {
        return [
            'empty' => [''],
            'text' => ['north'],
            'array' => [['42']],
        ];
    }

    public function testOnlyTheRoundedPointGoesFurther(): void
    {
        $this->post['lat'] = '42.14251987';
        $this->post['lng'] = '-24.74949012';
        $this->nearest->expects($this->once())->method('find')
            ->with('office', 42.143, -24.749)
            ->willReturn([]);

        $this->dispatch();

        $this->assertSame(200, $this->response['status']);
    }

    public function testAPointOffTheGlobeIsRefusedBeforeTheLookup(): void
    {
        $this->post['lat'] = '95.5';
        $this->nearest->expects($this->never())->method('find');

        $this->dispatch();

        $this->assertSame(400, $this->response['status']);
        $this->assertSame('The location could not be read.', $this->response['data']['message']);
    }

    public function testAnUnexpectedFailureAnswersTheGenericUnavailableText(): void
    {
        $this->nearest->method('find')->willThrowException(
            new \RuntimeException('SQLSTATE[HY000]: lost connection near 42.143,24.75')
        );

        $this->dispatch();

        $this->assertSame(503, $this->response['status']);
        $this->assertSame(
            ['success' => false, 'message' => 'The nearest offices could not be found just now.'],
            $this->response['data'],
            'nothing of the failure reaches the shopper'
        );
    }

    public function testThePointIsReadFromThePostBodyOnly(): void
    {
        unset($this->post['lat'], $this->post['lng']);
        $this->query = ['lat' => '42.1', 'lng' => '24.7'];
        $this->nearest->expects($this->never())->method('find');

        $this->dispatch();

        $this->assertSame(400, $this->response['status']);
    }

    /**
     * @dataProvider refusals
     */
    public function testARefusalCarriesItsStatus(string $reason, int $status): void
    {
        $this->nearest->method('find')->willThrowException(
            new NearestRefusedException($reason, __('Too many searches at once. Wait a moment and try again.'))
        );

        $this->dispatch();

        $this->assertSame($status, $this->response['status']);
        $this->assertSame(
            ['success' => false, 'message' => 'Too many searches at once. Wait a moment and try again.'],
            $this->response['data']
        );
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public function refusals(): array
    {
        return [
            'busy' => [NearestRefusedException::BUSY, 429],
            'carrier down' => [NearestRefusedException::UNAVAILABLE, 503],
            'bad point' => [NearestRefusedException::INVALID, 400],
            'no session' => [NearestRefusedException::NO_SESSION, 400],
        ];
    }

    private function dispatch(): void
    {
        $test = $this;

        $request = $this->createMock(HttpRequest::class);
        $request->method('getPost')->willReturnCallback(function ($key) use ($test) {
            return $test->post[$key] ?? null;
        });
        $request->method('getParam')->willReturnCallback(function ($key, $default = null) use ($test) {
            return $test->post[$key] ?? $test->query[$key] ?? $default;
        });

        $result = $this->createMock(Json::class);
        $result->method('setHttpResponseCode')->willReturnCallback(function ($code) use ($test, $result) {
            $test->response['status'] = (int) $code;

            return $result;
        });
        $result->method('setData')->willReturnCallback(function ($data) use ($test, $result) {
            $test->response['data'] = $data;

            return $result;
        });
        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($result);

        $formKey = $this->createMock(FormKeyValidator::class);
        $formKey->method('validate')->willReturnCallback(function () use ($test) {
            return $test->formKeyValid;
        });

        $session = $this->getMockBuilder(CheckoutSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuoteId'])
            ->getMock();
        $session->method('getQuoteId')->willReturn($this->quoteId);

        $config = $this->createMock(ConfigInterface::class);
        $config->method('isNearestOfficeEnabled')->with(3)->willReturn($this->enabled);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(3);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $remote = $this->createMock(RemoteAddress::class);
        $remote->method('getRemoteAddress')->willReturn('203.0.113.5');

        $controller = new Nearest(
            $request,
            $jsonFactory,
            $formKey,
            $session,
            $config,
            $storeManager,
            $this->nearest,
            $remote
        );

        $controller->execute();
    }
}
