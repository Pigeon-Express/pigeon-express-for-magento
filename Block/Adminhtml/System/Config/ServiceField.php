<?php
/**
 * An additional service toggle that hides itself when the account cannot use it.
 *
 * Every service the module supports has a field here, but a given Pigeon Express
 * account only includes some of them. Showing a switch that silently does
 * nothing is worse than showing nothing, so a service missing from the synced
 * catalogue is simply not rendered.
 *
 * Until the catalogue has been synced for the first time every field is shown,
 * because an empty catalogue means "not yet known", not "nothing is available".
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use PigeonExpress\Shipping\Model\AdditionalServiceRepository;
use PigeonExpress\Shipping\Model\Service\ServiceCodesBuilder;

class ServiceField extends Field
{
    /** Admin field ids carry this prefix, the rest is the config code. */
    private const FIELD_PREFIX = 'service_';

    /** @var AdditionalServiceRepository */
    private $serviceRepository;

    public function __construct(
        Context $context,
        AdditionalServiceRepository $serviceRepository,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->serviceRepository = $serviceRepository;
    }

    public function render(AbstractElement $element): string
    {
        if (!$this->isOffered($element)) {
            return '';
        }

        return parent::render($element);
    }

    /**
     * Whether the account's catalogue includes the service behind this field.
     */
    private function isOffered(AbstractElement $element): bool
    {
        $catalogue = $this->serviceRepository->getActiveByCode();

        // Never synced: show everything rather than hide the whole section.
        if (empty($catalogue)) {
            return true;
        }

        $apiCode = $this->resolveApiCode((string) $element->getId());

        // A field we cannot map is not ours to hide.
        if ($apiCode === null) {
            return true;
        }

        return isset($catalogue[$apiCode]);
    }

    /**
     * Turn an admin field id such as `service_return_receipt` into the API
     * service code, using the same map the payload builder uses.
     */
    private function resolveApiCode(string $fieldId): ?string
    {
        // Element ids are prefixed with the section and group when rendered.
        $position = strrpos($fieldId, self::FIELD_PREFIX);
        if ($position === false) {
            return null;
        }

        $configCode = substr($fieldId, $position + strlen(self::FIELD_PREFIX));

        return ServiceCodesBuilder::CONFIG_TO_API_CODE[$configCode] ?? null;
    }
}
