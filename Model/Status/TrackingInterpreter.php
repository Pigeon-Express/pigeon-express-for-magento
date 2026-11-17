<?php
/**
 * The status a Pigeon Express tracking entry stands for.
 *
 * Copied from the WooCommerce plugin (ShipmentStatus::from_tracking()).
 *
 * FOLLOWS THE CHAIN. When a parcel is not collected or is refused, the carrier
 * sends it back as a NEW shipment linked after the first (`chain_after`), and
 * the first one keeps its last status ("not collected") for good. Read alone,
 * the order would look stuck forever and never as returned. So when linked
 * shipments follow, the last one decides: a link made from a redirected
 * shipment carries the parcel on to the same customer; any other link carries
 * it back. Once on its way back, the parcel arriving is "returned", not
 * "delivered". A shipment already delivered is delivered whatever follows it.
 *
 * A "returned" read from the chain alone comes with `confirm` and `fallback`:
 * tracking does not say how a link was made, so the caller asks the carrier
 * (the details of the shipment the link was made from name the relation)
 * before recording it. See StatusRefresher::confirmReturn().
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Status;

class TrackingInterpreter
{
    /**
     * @param array<string, mixed> $entry A tracking entry, single or bulk
     * @return array{code:string, name:string, at:string, confirm?:array{parent:string, reference:string, code:string, started:string}, fallback?:array{code:string, name:string, at:string}}|null
     */
    public function interpret(array $entry): ?array
    {
        if (array_key_exists('found', $entry) && !$entry['found']) {
            return null;
        }

        $root = $this->statusOf($entry);
        if ($root['code'] === '') {
            return null;
        }
        $root['at'] = $this->eventTime($entry, $root['code']);

        $chain = $this->chain($entry);
        if ($root['code'] === StatusCodes::DELIVERED || $chain === []) {
            return $root;
        }

        $previous = $root['code'];
        $returning = false;
        $last = null;
        $current = (string) ($entry['reference_number'] ?? '');
        $from = $current;
        $created = '';
        $delivered = '';

        foreach ($chain as $link) {
            if ($previous !== StatusCodes::REDIRECTED) {
                $returning = true;
            }
            // Each link is made from the shipment before it.
            $from = $current;
            $current = (string) ($link['reference_number'] ?? '');
            $last = $this->statusOf($link);
            $previous = $last['code'];
            $created = (string) ($link['created_at'] ?? '');
            $delivered = (string) ($link['delivery_date'] ?? '');
        }

        if ($last === null || $last['code'] === '') {
            return $root;
        }

        $ended = in_array($last['code'], [StatusCodes::DELIVERED, StatusCodes::RETURNED], true);
        $at = $ended && $delivered !== '' ? $delivered : $created;

        if (!$returning) {
            return ['code' => $last['code'], 'name' => $last['name'], 'at' => $at];
        }

        if ($ended) {
            return [
                'code'     => StatusCodes::RETURNED,
                'name'     => '',
                'at'       => $delivered,
                'confirm'  => [
                    'parent'    => $from,
                    'reference' => $current,
                    'code'      => $last['code'],
                    'started'   => $created,
                ],
                'fallback' => $root,
            ];
        }

        if (in_array($last['code'], [StatusCodes::CANCELLED, StatusCodes::ABANDONED], true)) {
            return ['code' => $last['code'], 'name' => $last['name'], 'at' => $at];
        }

        return ['code' => StatusCodes::RETURNING_TO_SENDER, 'name' => '', 'at' => $at];
    }

    /**
     * The status in a shipment details answer (GET or PUT /shipments/{ref}),
     * or null when it cannot stand for the shipment by itself: a shipment
     * with linked shipments after it is described by the last of them, which
     * this answer does not carry, so the tracking read decides.
     *
     * @param array<string, mixed> $data The carrier's `data`
     * @return array{code:string, name:string, at:string}|null
     */
    public function fromDetails(array $data): ?array
    {
        $status = $this->statusOf($data);
        if ($status['code'] === '') {
            return null;
        }

        foreach ((array) ($data['related_shipments'] ?? []) as $link) {
            if (is_array($link) && (string) ($link['relation'] ?? '') !== 'original') {
                return null;
            }
        }

        // Only the times the answer really carries.
        $at = '';
        if ($status['code'] === StatusCodes::REGISTERED) {
            $at = (string) ($data['created_at'] ?? '');
        } elseif ($status['code'] === StatusCodes::DELIVERED) {
            $at = (string) ($data['delivery_date'] ?? '');
        }

        return ['code' => $status['code'], 'name' => $status['name'], 'at' => $at];
    }

    /**
     * When the entry reached its current status: the latest event with that
     * code, else the delivery date of a delivered parcel, else the latest
     * event of any kind, else the creation time.
     *
     * @param array<string, mixed> $entry
     */
    public function eventTime(array $entry, string $code): string
    {
        $events = isset($entry['tracking']) && is_array($entry['tracking']) ? $entry['tracking'] : [];
        $match = '';
        $latest = '';

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $time = (string) ($event['created_at'] ?? '');
            if ($time === '') {
                continue;
            }
            $latest = $time;
            if ($this->statusOf($event)['code'] === $code) {
                $match = $time;
            }
        }

        if ($match !== '') {
            return $match;
        }
        if ($code === StatusCodes::DELIVERED && (string) ($entry['delivery_date'] ?? '') !== '') {
            return (string) $entry['delivery_date'];
        }

        return $latest !== '' ? $latest : (string) ($entry['created_at'] ?? '');
    }

    /**
     * @param array<string, mixed> $node
     * @return array{code:string, name:string}
     */
    private function statusOf(array $node): array
    {
        $status = $node['status'] ?? '';

        if (is_array($status)) {
            return [
                'code' => StatusCodes::clean((string) ($status['code'] ?? '')),
                'name' => (string) ($status['name'] ?? ''),
            ];
        }

        return [
            'code' => StatusCodes::clean((string) ($node['status_code'] ?? '')),
            'name' => is_scalar($status) ? (string) $status : '',
        ];
    }

    /**
     * The shipments linked after this one, oldest first.
     *
     * @param array<string, mixed> $entry
     * @return array<int, array<string, mixed>>
     */
    private function chain(array $entry): array
    {
        $links = isset($entry['chain_after']) && is_array($entry['chain_after']) ? $entry['chain_after'] : [];
        $keyed = [];

        foreach (array_values($links) as $index => $link) {
            if (is_array($link)) {
                $time = strtotime((string) ($link['created_at'] ?? ''));
                $keyed[] = [$time !== false ? $time : 0, $index, $link];
            }
        }

        usort($keyed, static function (array $a, array $b): int {
            return [$a[0], $a[1]] <=> [$b[0], $b[1]];
        });

        return array_column($keyed, 2);
    }
}
