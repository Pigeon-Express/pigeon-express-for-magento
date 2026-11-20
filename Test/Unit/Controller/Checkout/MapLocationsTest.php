<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Controller\Checkout;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\LocationSearchInterface;
use PigeonExpress\Shipping\Controller\Checkout\MapLocations;

/**
 * The map's pins: only with the setting on, only offices or lockers, the
 * picker row plus a rounded point, nothing else of the table.
 */
class MapLocationsTest extends TestCase
{
    /** @var array{status: int, data: array<string, mixed>} */
    private $response = ['status' => 200, 'data' => []];

    public function testEveryPinnedLocationInThePickerShapeWithItsPoint(): void
    {
        $this->dispatch(true, 'office', [[
            'id' => 86, 'name' => 'Лиляна', 'address' => 'Пловдив, 4000, Д-Р Г.М.ДИМИТРОВ, № 27Б',
            'city' => 'Пловдив', 'postcode' => '4000', 'region_id' => 655, 'type' => 'office', 'hours' => '',
            'latitude' => 42.13789420, 'longitude' => 24.74672091, 'api_id' => 12, 'code' => 'X',
        ]]);

        $this->assertSame(200, $this->response['status']);
        $this->assertTrue($this->response['data']['success']);
        $this->assertSame([[
            'id' => 86, 'name' => 'Лиляна', 'address' => 'Пловдив, 4000, Д-Р Г.М.ДИМИТРОВ, № 27Б',
            'city' => 'Пловдив', 'postcode' => '4000', 'region_id' => 655, 'type' => 'office', 'hours' => '',
            'lat' => 42.13789, 'lng' => 24.74672,
        ]], $this->response['data']['locations']);
    }

    public function testAnEmptyListWhenNoneHasAPoint(): void
    {
        $this->dispatch(true, 'aps', []);

        $this->assertSame(['success' => true, 'locations' => []], $this->response['data']);
    }

    public function testNothingWhileTheSettingIsOff(): void
    {
        $this->dispatch(false, 'office', null);

        $this->assertSame(400, $this->response['status']);
        $this->assertFalse($this->response['data']['success']);
    }

    public function testOnlyOfficesAndLockers(): void
    {
        $this->dispatch(true, 'address', null);

        $this->assertSame(400, $this->response['status']);
        $this->assertSame('Unknown location type.', $this->response['data']['message']);
    }

    /**
     * @param array<int, array<string, mixed>>|null $rows Null: the table must not be read
     */
    private function dispatch(bool $enabled, string $type, ?array $rows): void
    {
        $test = $this;

        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(function ($key, $default = null) use ($type) {
            return $key === 'type' ? $type : $default;
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

        $config = $this->createMock(ConfigInterface::class);
        $config->method('isLocationMapEnabled')->willReturn($enabled);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $search = $this->createMock(LocationSearchInterface::class);
        if ($rows === null) {
            $search->expects($this->never())->method('listWithCoordinates');
        } else {
            $search->expects($this->once())->method('listWithCoordinates')->with($type, 1)->willReturn($rows);
        }

        (new MapLocations($request, $jsonFactory, $config, $storeManager, $search))->execute();
    }
}
