<?php
/**
 * Pigeon Express City model.
 *
 * @copyright Copyright (c). All rights reserved.
 */
declare(strict_types=1);

namespace PigeonExpress\Shipping\Model;

use Magento\Framework\Model\AbstractModel;
use PigeonExpress\Shipping\Model\ResourceModel\City as CityResource;

class City extends AbstractModel
{
    protected function _construct()
    {
        $this->_init(CityResource::class);
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

    public function getNameEn(): ?string
    {
        return $this->getData('name_en');
    }

    public function setNameEn(?string $nameEn): self
    {
        return $this->setData('name_en', $nameEn);
    }

    public function getPostalCode(): ?string
    {
        return $this->getData('postal_code');
    }

    public function setPostalCode(?string $postalCode): self
    {
        return $this->setData('postal_code', $postalCode);
    }
}
