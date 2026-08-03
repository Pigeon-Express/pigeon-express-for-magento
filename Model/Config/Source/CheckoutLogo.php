<?php
/**
 * Which Pigeon Express logo, if any, appears beside the methods at checkout.
 *
 * A fixed list rather than a scan of the images folder. A scan could only ever
 * offer filenames, and the most important thing about two of these is that they
 * are white on transparent and vanish against a light checkout. It also means
 * the stored value is a code rather than a filename, so the artwork can be
 * re-exported without silently breaking every store that selected it.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class CheckoutLogo implements OptionSourceInterface
{
    /** Chosen when nothing is configured. */
    public const NONE = '';

    /**
     * Code to file, and whether the artwork needs something dark behind it.
     *
     * @var array<string, array{file:string, dark:bool}>
     */
    private const LOGOS = [
        'color'      => ['file' => 'pigeon-express-color.svg', 'dark' => false],
        'black'      => ['file' => 'pigeon-express-black.svg', 'dark' => false],
        'white'      => ['file' => 'pigeon-express-white.svg', 'dark' => true],
        'blue_light' => ['file' => 'pigeon-express-blue-light.svg', 'dark' => true],
    ];

    /**
     * @return array<int, array{value:string, label:\Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::NONE, 'label' => __('No logo')],
            ['value' => 'color', 'label' => __('Full colour')],
            ['value' => 'black', 'label' => __('Black and grey')],
            ['value' => 'white', 'label' => __('White (needs a dark background)')],
            ['value' => 'blue_light', 'label' => __('White and orange (needs a dark background)')],
        ];
    }

    /**
     * The file a stored code refers to, or an empty string for no logo.
     *
     * An unrecognised code means no logo rather than a broken image: artwork can
     * be withdrawn, and a store that selected it should degrade quietly.
     */
    public function fileFor(string $code): string
    {
        return self::LOGOS[$code]['file'] ?? '';
    }

    /**
     * Whether the chosen artwork is drawn to sit on something dark.
     */
    public function needsDarkBackdrop(string $code): bool
    {
        return !empty(self::LOGOS[$code]['dark']);
    }
}
