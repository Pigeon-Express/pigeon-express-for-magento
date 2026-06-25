<?php
/**
 * Pigeon Express APS (locker) model.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model;

use Magento\Framework\Model\AbstractModel;
use PigeonExpress\Shipping\Model\ResourceModel\Aps as ApsResource;

class Aps extends AbstractModel
{
    protected function _construct()
    {
        $this->_init(ApsResource::class);
    }

    public function getApiId(): ?int
    {
        $v = $this->getData('api_id');
        return $v !== null ? (int) $v : null;
    }

    public function setApiId(int $apiId): self
    {
        return $this->setData('api_id', $apiId);
    }

    public function getName(): ?string
    {
        return $this->getData('name');
    }

    public function setName(string $name): self
    {
        return $this->setData('name', $name);
    }

    public function getAddress(): ?string
    {
        return $this->getData('address');
    }

    public function setAddress(string $address): self
    {
        return $this->setData('address', $address);
    }

    public function getCityId(): ?int
    {
        $v = $this->getData('city_id');
        return $v !== null ? (int) $v : null;
    }

    public function setCityId(?int $cityId): self
    {
        return $this->setData('city_id', $cityId);
    }

    public function getCity(): ?string
    {
        return $this->getData('city');
    }

    public function setCity(?string $city): self
    {
        return $this->setData('city', $city);
    }

    public function getCountry(): ?string
    {
        return $this->getData('country');
    }

    public function setCountry(?string $country): self
    {
        return $this->setData('country', $country);
    }

    public function getPostcode(): ?string
    {
        return $this->getData('postcode');
    }

    public function setPostcode(?string $postcode): self
    {
        return $this->setData('postcode', $postcode);
    }

    public function getLatitude(): ?float
    {
        $v = $this->getData('latitude');
        return $v !== null ? (float) $v : null;
    }

    public function setLatitude(?float $latitude): self
    {
        return $this->setData('latitude', $latitude);
    }

    public function getLongitude(): ?float
    {
        $v = $this->getData('longitude');
        return $v !== null ? (float) $v : null;
    }

    public function setLongitude(?float $longitude): self
    {
        return $this->setData('longitude', $longitude);
    }

    public function getIsActive(): bool
    {
        return (bool) $this->getData('is_active');
    }

    public function setIsActive(bool $isActive): self
    {
        return $this->setData('is_active', $isActive ? 1 : 0);
    }
}
