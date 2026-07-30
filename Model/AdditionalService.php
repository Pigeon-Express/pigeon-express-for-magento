<?php
/**
 * Pigeon Express additional service model.
 *
 * Mirrors one entry of the catalogue returned by GET /additional-services.
 * The API returns service definitions only, never prices, so the fee columns
 * on this model are populated from what the calculate endpoint actually
 * charged, never from a value written by hand.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model;

use Magento\Framework\Model\AbstractModel;
use PigeonExpress\Shipping\Model\ResourceModel\AdditionalService as AdditionalServiceResource;

class AdditionalService extends AbstractModel
{
    public const INPUT_TYPE_CHECKBOX = 'checkbox';
    public const INPUT_TYPE_TEXT = 'text';
    public const INPUT_TYPE_SELECT = 'select';

    /** Service code the API uses for the cash on delivery amount. */
    public const CODE_COD_AMOUNT = 'cod_amount';

    /** Service code the API reports back when it has charged a COD fee. */
    public const CODE_COD_FEE = 'cod_fee';

    protected function _construct()
    {
        $this->_init(AdditionalServiceResource::class);
    }

    public function getCode(): ?string
    {
        return $this->getData('code');
    }

    public function setCode(string $code): self
    {
        return $this->setData('code', $code);
    }

    public function getName(): ?string
    {
        return $this->getData('name');
    }

    public function setName(?string $name): self
    {
        return $this->setData('name', $name);
    }

    public function getDescription(): ?string
    {
        return $this->getData('description');
    }

    public function setDescription(?string $description): self
    {
        return $this->setData('description', $description);
    }

    public function getInputType(): ?string
    {
        return $this->getData('input_type');
    }

    public function setInputType(?string $inputType): self
    {
        return $this->setData('input_type', $inputType);
    }

    public function getOptionGroup(): ?string
    {
        return $this->getData('option_group');
    }

    public function setOptionGroup(?string $optionGroup): self
    {
        return $this->setData('option_group', $optionGroup);
    }

    public function getIsActive(): bool
    {
        return (bool) $this->getData('is_active');
    }

    public function setIsActive(bool $isActive): self
    {
        return $this->setData('is_active', $isActive ? 1 : 0);
    }

    public function getLastFeeAmount(): ?float
    {
        $v = $this->getData('last_fee_amount');
        return $v !== null ? (float) $v : null;
    }

    public function setLastFeeAmount(?float $amount): self
    {
        return $this->setData('last_fee_amount', $amount);
    }

    public function getLastFeeBasis(): ?float
    {
        $v = $this->getData('last_fee_basis');
        return $v !== null ? (float) $v : null;
    }

    public function setLastFeeBasis(?float $basis): self
    {
        return $this->setData('last_fee_basis', $basis);
    }

    public function getFeeSyncedAt(): ?string
    {
        return $this->getData('fee_synced_at');
    }

    public function setFeeSyncedAt(?string $syncedAt): self
    {
        return $this->setData('fee_synced_at', $syncedAt);
    }

    /**
     * Ratio of fee to basis from the last successful API resolution.
     *
     * Used only as an offline fallback for proportional services such as the
     * COD fee. Returns null when no API derived figure has ever been stored,
     * in which case callers must not invent one.
     */
    public function getLastFeeRate(): ?float
    {
        $amount = $this->getLastFeeAmount();
        $basis = $this->getLastFeeBasis();

        if ($amount === null || $basis === null || $basis <= 0.0) {
            return null;
        }

        return $amount / $basis;
    }

    public function expectsValue(): bool
    {
        return $this->getInputType() === self::INPUT_TYPE_TEXT;
    }
}
