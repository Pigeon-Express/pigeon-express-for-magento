<?php
/**
 * Additional services catalogue via Pigeon Express PHP SDK: GET /additional-services.
 *
 * Mirrors the retry and logging behaviour of RateClient so catalogue syncs fail
 * the same way rate lookups do.
 *
 * @copyright Copyright (c). All rights reserved.
 */
declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Api;

use PigeonExpress\Exceptions\PigeonExpressException as SdkException;
use PigeonExpress\Shipping\Api\AdditionalServicesClientInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use Psr\Log\LoggerInterface;

class AdditionalServicesClient implements AdditionalServicesClientInterface
{
    private const MAX_ATTEMPTS = 3;

    private const RETRY_DELAY_MS = 1200;

    /** @var ConfigInterface */
    private $config;

    /** @var LoggerInterface */
    private $logger;

    /** @var ClientFactory */
    private $clientFactory;

    public function __construct(ConfigInterface $config, LoggerInterface $logger, ClientFactory $clientFactory)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->clientFactory = $clientFactory;
    }

    /**
     * @inheritdoc
     */
    public function fetchAll(int $storeId): array
    {
        // Throws at once when no key is configured: nothing to retry then.
        $client = $this->clientFactory->create();

        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $services = $client->additionalServices()->all();

                $rows = [];
                foreach ($services as $service) {
                    $code = $service->getCode();
                    if ($code === '') {
                        continue;
                    }
                    $rows[] = [
                        'code' => $code,
                        'name' => $service->getName(),
                        'description' => $service->getDescription(),
                        'input_type' => $service->getInputType(),
                        'option_group' => $service->getOptionGroup(),
                    ];
                }

                if ($this->config->isLoggingEnabled($storeId)) {
                    $this->logger->info('[PigeonExpress] Additional services fetched', [
                        'storeId' => $storeId,
                        'count' => count($rows),
                    ]);
                }

                return $rows;
            } catch (SdkException $e) {
                $lastException = $e;
                if ($this->config->isLoggingEnabled($storeId)) {
                    $this->logger->warning(
                        '[PigeonExpress] Additional services attempt ' . $attempt . ' failed: ' . $e->getMessage()
                    );
                }
                if ($attempt < self::MAX_ATTEMPTS) {
                    usleep(self::RETRY_DELAY_MS * 1000);
                }
            } catch (\Throwable $e) {
                $lastException = $e;
                if ($this->config->isLoggingEnabled($storeId)) {
                    $this->logger->warning(
                        '[PigeonExpress] Additional services attempt ' . $attempt . ' failed: ' . $e->getMessage()
                    );
                }
                if ($attempt < self::MAX_ATTEMPTS) {
                    usleep(self::RETRY_DELAY_MS * 1000);
                }
            }
        }

        $msg = $lastException ? $lastException->getMessage() : 'Unknown error';
        throw new ApiException(__('Failed to load Pigeon Express additional services: %1', $msg));
    }
}
