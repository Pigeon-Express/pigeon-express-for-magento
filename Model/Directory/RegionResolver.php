<?php
/**
 * The Magento region for a Pigeon Express district.
 *
 * Bulgaria is one of the countries in Magento's `general/region/state_required`
 * list, so an order cannot be placed without a region on the shipping address.
 * Nothing the customer fills in on the carrier's own fields supplies one: they
 * pick a settlement, and a settlement is not a region.
 *
 * Pigeon Express does hold the district each settlement belongs to, in
 * Bulgarian and in English, and the English form is the same set of 28 names
 * Magento ships as Bulgarian regions. That is the join, and it is done on names
 * because neither side has an identifier the other knows.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Directory;

use Magento\Framework\App\ResourceConnection;

class RegionResolver
{
    /**
     * Every Pigeon Express city is Bulgarian, so there is one country to look
     * regions up in.
     */
    public const COUNTRY_ID = 'BG';

    /**
     * Districts whose name does not lead to the right region on its own.
     *
     * The capital is the whole of it. Pigeon Express calls both the city and the
     * province around it "Sofia" in English, and they are two different Magento
     * regions, so the English name cannot tell them apart and the Bulgarian one
     * is consulted first. `София (столица)` is the capital; plain `София` is the
     * province that surrounds it.
     */
    private const ALIASES = [
        'софия (столица)' => 'Sofia City',
        'софия-град'      => 'Sofia City',
        'софия град'      => 'Sofia City',
        'sofia city'      => 'Sofia City',
        'софия'           => 'Sofia Province',
        'софия-област'    => 'Sofia Province',
        'софия област'    => 'Sofia Province',
    ];

    /** @var ResourceConnection */
    private $resource;

    /** @var array<string, int>|null Lower-cased region name or code, to region id. */
    private $map;

    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    /**
     * The region id for a district, or null when it cannot be placed.
     *
     * Both spellings are worth trying: the English one is what matches Magento
     * out of the box, and the Bulgarian one is what matches a store whose
     * region names have been translated in the directory tables.
     */
    public function resolve(?string $districtEn, ?string $district = null): ?int
    {
        // The Bulgarian name is asked about first, and only through the alias
        // list: it is the one that can tell the capital from the province of
        // the same name. Everything else falls through to a plain name match,
        // where the English form is what a stock Magento directory holds.
        foreach ([$district, $districtEn] as $candidate) {
            $id = $this->lookup($this->alias($candidate));
            if ($id !== null) {
                return $id;
            }
        }

        foreach ([$districtEn, $district] as $candidate) {
            $id = $this->lookup($candidate);
            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    /**
     * The region name a district is known by here, where it differs.
     */
    private function alias(?string $name): ?string
    {
        $key = mb_strtolower(trim((string) $name));

        return self::ALIASES[$key] ?? null;
    }

    /**
     * One name against the map, ignoring case and surrounding space.
     */
    private function lookup(?string $name): ?int
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        $map = $this->map();
        $key = mb_strtolower($name);

        return $map[$key] ?? null;
    }

    /**
     * Bulgaria has 28 regions, so the whole set is held rather than queried per
     * city: resolving a page of search results would otherwise be a query each.
     *
     * @return array<string, int>
     */
    private function map(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(
                ['r' => $this->resource->getTableName('directory_country_region')],
                ['region_id', 'code', 'default_name']
            )
            ->where('r.country_id = ?', self::COUNTRY_ID);

        $map = [];
        foreach ($connection->fetchAll($select) as $row) {
            $id = (int) $row['region_id'];

            foreach ([$row['default_name'], $row['code']] as $key) {
                $key = mb_strtolower(trim((string) $key));
                if ($key !== '') {
                    $map[$key] = $id;
                }
            }
        }

        // Names as the directory holds them for the store's own locale, which is
        // what a translated store matches its districts against.
        $names = $connection->select()
            ->from(
                ['n' => $this->resource->getTableName('directory_country_region_name')],
                ['region_id', 'name']
            )
            ->join(
                ['r' => $this->resource->getTableName('directory_country_region')],
                'r.region_id = n.region_id',
                []
            )
            ->where('r.country_id = ?', self::COUNTRY_ID);

        foreach ($connection->fetchAll($names) as $row) {
            $key = mb_strtolower(trim((string) $row['name']));
            if ($key !== '' && !isset($map[$key])) {
                $map[$key] = (int) $row['region_id'];
            }
        }

        $this->map = $map;

        return $map;
    }
}
