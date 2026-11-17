<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\I18n;

use PHPUnit\Framework\TestCase;

/**
 * Magento loads a language pack after the module, so a phrase both translate
 * shows the pack's wording ("Created" became "Създаден:"). Module phrases
 * that a pack also has must therefore say the same, or use a phrase of
 * their own that reads as English in any locale ("Shipment created").
 */
class LanguagePackCollisionTest extends TestCase
{
    /**
     * Generic Magento words of the standard carrier settings, where the pack's
     * wording matches the rest of the admin and is accepted.
     */
    private const ACCEPTED = [
        'Carrier Title',
        'Displayed Error Message',
        'Download',
        'edit',
        'Enable',
        'Enable Free Shipping Threshold',
        'Location',
        'Select',
        'Ship to Applicable Countries',
        'Ship to Specific Countries',
        'Sort Order',
        'This action cannot be undone.',
        'Type',
    ];

    public function testNoModulePhraseIsOverriddenByTheBulgarianPack(): void
    {
        $packDir = dirname(__DIR__, 6) . '/i18n/CommunityEngineering/bg_bg';
        $files = is_dir($packDir) ? (glob($packDir . '/*.csv') ?: []) : [];
        if ($files === []) {
            $this->markTestSkipped('The Bulgarian language pack is not installed here.');
        }

        $pack = [];
        foreach ($files as $file) {
            $pack += $this->read($file);
        }

        $collisions = [];
        foreach ($this->read($this->moduleCsv('bg_BG')) as $key => $value) {
            if (isset($pack[$key]) && $pack[$key] !== $value && !in_array($key, self::ACCEPTED, true)) {
                $collisions[] = sprintf('"%s": module "%s", pack "%s"', $key, $value, $pack[$key]);
            }
        }

        $this->assertSame([], $collisions, 'Give these phrases a module-specific key.');
    }

    /**
     * A key is shown as it is in every locale the module has no file for
     * (en_GB, de_DE), so it must read as English: no "Context: word"
     * machine keys. "Label: %1" with a value is fine.
     */
    public function testNoKeyIsAMachineKey(): void
    {
        $machine = [];
        foreach (array_keys($this->read($this->moduleCsv('en_US'))) as $key) {
            if (preg_match('/^[A-Z][A-Za-z]*(?: [A-Za-z]+){0,3}: [A-Za-z#0-9 ]{1,40}$/', $key)) {
                $machine[] = $key;
            }
        }

        $this->assertSame([], $machine, 'Use a natural English phrase as the key.');
    }

    public function testEnglishReadsAsTheKey(): void
    {
        $wrong = [];
        foreach ($this->read($this->moduleCsv('en_US')) as $key => $value) {
            if ($key !== $value) {
                $wrong[] = $key . ' => ' . $value;
            }
        }

        $this->assertSame([], $wrong);
    }

    public function testBothLanguagesHaveTheSameKeys(): void
    {
        $english = array_keys($this->read($this->moduleCsv('en_US')));
        $bulgarian = array_keys($this->read($this->moduleCsv('bg_BG')));
        sort($english);
        sort($bulgarian);

        $this->assertSame($english, $bulgarian);
    }

    private function moduleCsv(string $locale): string
    {
        return dirname(__DIR__, 3) . '/i18n/' . $locale . '.csv';
    }

    /**
     * @return array<string, string>
     */
    private function read(string $file): array
    {
        $out = [];
        $handle = fopen($file, 'rb');
        $this->assertNotFalse($handle, $file);
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if (isset($row[0], $row[1]) && $row[0] !== '') {
                $out[(string) $row[0]] = (string) $row[1];
            }
        }
        fclose($handle);

        return $out;
    }
}
