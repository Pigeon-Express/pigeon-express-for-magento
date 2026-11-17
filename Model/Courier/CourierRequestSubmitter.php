<?php
/**
 * The only place a courier request reaches Pigeon Express.
 *
 * Creating and cancelling a request go through submit() and cancel() here and
 * nowhere else, so one plugin on this class can stop them (a development shop
 * that must never call a real courier does exactly that). Reading a request
 * back, get(), changes nothing at the carrier.
 *
 * SDK exceptions are passed on as they are: CourierRequestService says them
 * in the merchant's words.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Courier;

use PigeonExpress\DTO\Response\CourierRequest;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\Api\ClientFactory;
use Psr\Log\LoggerInterface;

class CourierRequestSubmitter
{
    /** @var ClientFactory */
    private $clientFactory;

    /** @var ConfigInterface */
    private $config;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(ClientFactory $clientFactory, ConfigInterface $config, LoggerInterface $logger)
    {
        $this->clientFactory = $clientFactory;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Send a new courier request (POST /courier-requests).
     *
     * @param array<string, mixed> $body
     * @return array{number:string, status_code:string, status_name:string, request_type:string, replay:string}
     * @throws \Exception Whatever the SDK throws
     */
    public function submit(array $body): array
    {
        if ($this->config->isLoggingEnabled()) {
            $this->logger->info('[PigeonExpress] API request: courierRequests()->create', ['request' => $body]);
        }

        $resource = $this->clientFactory->create()->courierRequests();
        $request = $resource->create($body);

        return $this->describe($request) + ['replay' => (string) $resource->getLastReplay()];
    }

    /**
     * Cancel a courier request (POST /courier-requests/{number}/cancel).
     *
     * @return array{number:string, status_code:string, status_name:string, request_type:string}
     * @throws \Exception Whatever the SDK throws
     */
    public function cancel(string $number): array
    {
        if ($this->config->isLoggingEnabled()) {
            $this->logger->info('[PigeonExpress] API request: courierRequests()->cancel', ['number' => $number]);
        }

        return $this->describe($this->clientFactory->create()->courierRequests()->cancel($number));
    }

    /**
     * Read a courier request (GET /courier-requests/{number}).
     *
     * @return array{number:string, status_code:string, status_name:string, request_type:string}
     * @throws \Exception Whatever the SDK throws
     */
    public function get(string $number): array
    {
        return $this->describe($this->clientFactory->create()->courierRequests()->get($number));
    }

    /**
     * @return array{number:string, status_code:string, status_name:string, request_type:string}
     */
    private function describe(CourierRequest $request): array
    {
        return [
            'number'       => trim((string) $request->getRequestNumber()),
            'status_code'  => (string) preg_replace('/[^a-z0-9_]/', '', strtolower((string) $request->getStatusCode())),
            'status_name'  => trim(strip_tags((string) $request->getStatusName())),
            'request_type' => (string) $request->getRequestType(),
        ];
    }
}
