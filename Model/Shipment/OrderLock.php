<?php
/**
 * A per-order lock around writes to Pigeon Express.
 *
 * Two Send requests for one order arriving together (a double click, two
 * admins, a retry by the browser) would both find no shipment and both create
 * one. A named database lock lets only one of them in; the other is told to
 * wait. The lock belongs to the database connection, so it is released by
 * the server even when PHP dies half way.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Shipment;

use Magento\Framework\App\ResourceConnection;

class OrderLock
{
    /** @var ResourceConnection */
    private $resource;

    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    /**
     * Take the lock without waiting.
     *
     * @param string $scope What is being done, so unrelated writes do not block each other
     * @return bool False when another request holds it
     */
    public function acquire(int $orderId, string $scope = 'send'): bool
    {
        $connection = $this->resource->getConnection();

        return (int) $connection->fetchOne('SELECT GET_LOCK(?, 0)', [$this->name($orderId, $scope)]) === 1;
    }

    public function release(int $orderId, string $scope = 'send'): void
    {
        try {
            $this->resource->getConnection()->fetchOne(
                'SELECT RELEASE_LOCK(?)',
                [$this->name($orderId, $scope)]
            );
        } catch (\Throwable $e) {
            // The server drops it with the connection anyway.
        }
    }

    /**
     * Lock names are server wide and at most 64 characters, so the database
     * is part of the name: two shops on one server must not block each other.
     */
    private function name(int $orderId, string $scope): string
    {
        $connection = $this->resource->getConnection();
        $config = method_exists($connection, 'getConfig') ? $connection->getConfig() : [];
        $database = is_array($config) && isset($config['dbname']) ? (string) $config['dbname'] : '';

        return 'pe_' . substr(md5($database), 0, 12) . '_' . substr(preg_replace('/\W/', '', $scope), 0, 16)
            . '_' . $orderId;
    }
}
