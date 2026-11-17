<?php
/**
 * Return receipt as one choice of four.
 *
 * The electronic and the paper receipt are separate services, and a shop may
 * want both, so they are not exclusive: one select, each receipt still sent as
 * its own service code. A choice is offered only when every receipt it stands
 * for is in the account's synced catalogue (all of them before the first sync).
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\AdditionalServiceRepository;

class ReturnReceiptMode implements OptionSourceInterface
{
    public const CODE_ELECTRONIC = 'return_receipt';
    public const CODE_PAPER = 'paper_return_receipt';

    /** The service codes each choice stands for. */
    public const CODES = [
        ConfigInterface::RECEIPT_NONE       => [],
        ConfigInterface::RECEIPT_ELECTRONIC => [self::CODE_ELECTRONIC],
        ConfigInterface::RECEIPT_PAPER      => [self::CODE_PAPER],
        ConfigInterface::RECEIPT_BOTH       => [self::CODE_ELECTRONIC, self::CODE_PAPER],
    ];

    /** @var AdditionalServiceRepository */
    private $services;

    public function __construct(AdditionalServiceRepository $services)
    {
        $this->services = $services;
    }

    /**
     * The service codes a choice stands for; none for an unknown value.
     *
     * @return string[]
     */
    public static function codesFor(string $mode): array
    {
        return self::CODES[$mode] ?? [];
    }

    /**
     * The choice a pair of yes/no settings amounts to.
     */
    public static function modeFor(bool $electronic, bool $paper): string
    {
        if ($electronic && $paper) {
            return ConfigInterface::RECEIPT_BOTH;
        }
        if ($electronic) {
            return ConfigInterface::RECEIPT_ELECTRONIC;
        }

        return $paper ? ConfigInterface::RECEIPT_PAPER : ConfigInterface::RECEIPT_NONE;
    }

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        $labels = [
            ConfigInterface::RECEIPT_NONE       => __('No return receipt'),
            ConfigInterface::RECEIPT_ELECTRONIC => __('Electronic'),
            ConfigInterface::RECEIPT_PAPER      => __('Paper'),
            ConfigInterface::RECEIPT_BOTH       => __('Electronic and paper'),
        ];

        $offered = $this->offeredCodes();
        $options = [];
        foreach ($labels as $mode => $label) {
            if ($offered !== null && array_diff(self::codesFor($mode), $offered) !== []) {
                continue;
            }
            $options[] = ['value' => $mode, 'label' => $label];
        }

        return $options;
    }

    /**
     * The receipt services the account offers, or null before the first sync.
     *
     * @return string[]|null
     */
    public function offeredCodes(): ?array
    {
        $catalogue = $this->services->getActiveByCode();
        if (empty($catalogue)) {
            return null;
        }

        return array_values(array_filter(
            [self::CODE_ELECTRONIC, self::CODE_PAPER],
            static function (string $code) use ($catalogue): bool {
                return isset($catalogue[$code]);
            }
        ));
    }
}
