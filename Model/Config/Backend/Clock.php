<?php
/**
 * A time of day setting (HH:MM). Anything else keeps the value it had.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use PigeonExpress\Shipping\Model\Courier\CourierSettings;

class Clock extends Value
{
    public function beforeSave()
    {
        $value = trim((string) $this->getValue());
        if (preg_match('/^\d:\d\d$/', $value)) {
            $value = '0' . $value;
        }
        $value = substr($value, 0, 5);

        if (CourierSettings::isClock($value)) {
            $this->setValue($value);
        } else {
            $this->setValue((string) $this->getOldValue());
        }

        return parent::beforeSave();
    }
}
