<?php
/**
 * Pigeon Express Shipment resource model.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Shipment extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('pigeonexpress_shipment', 'entity_id');
    }

    /**
     * Claim the once-only customer email of a redirected shipment.
     *
     * Sets customer_notified_at on the row only while it is still empty, in
     * one statement, so of two processes racing (a quick action, Refresh, the
     * status cron, a replayed answer) exactly one gets true and sends the
     * email. Everyone else gets false and sends nothing.
     *
     * @param int $entityId pigeonexpress_shipment.entity_id
     * @param string|null $utc "Y-m-d H:i:s" in UTC; null for now
     * @return bool Whether this caller made the claim
     */
    public function claimCustomerNotification(int $entityId, ?string $utc = null): bool
    {
        if ($entityId <= 0) {
            return false;
        }

        $affected = $this->getConnection()->update(
            $this->getMainTable(),
            ['customer_notified_at' => $utc ?? gmdate('Y-m-d H:i:s')],
            ['entity_id = ?' => $entityId, 'customer_notified_at IS NULL']
        );

        return $affected === 1;
    }

    /**
     * Give a claim back, for when the email could not be sent after all, so
     * a later attempt may send it.
     *
     * @param int $entityId pigeonexpress_shipment.entity_id
     */
    public function releaseCustomerNotification(int $entityId): void
    {
        if ($entityId <= 0) {
            return;
        }

        $this->getConnection()->update(
            $this->getMainTable(),
            ['customer_notified_at' => null],
            ['entity_id = ?' => $entityId]
        );
    }
}
