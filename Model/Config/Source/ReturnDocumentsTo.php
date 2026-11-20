<?php
/**
 * Where return documents go: the same choices as for undelivered shipments,
 * kept by the return documents setting. Shown only while
 * ConfigInterface::RETURN_DOCUMENTS_CHOICE is on.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Config\Source;

use PigeonExpress\Shipping\Api\ConfigInterface;

class ReturnDocumentsTo extends ReturnTo
{
    /**
     * @inheritdoc
     */
    protected function path(): string
    {
        return ConfigInterface::XML_PATH_RETURN_DOCUMENTS_TO;
    }
}
