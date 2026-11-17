<?php
/**
 * Under Magento's tracking popup: a link to the Pigeon Express tracking page
 * for each Pigeon Express number whose table shows a status.
 *
 * Magento's popup shows either a link or the status fields for a number,
 * never both (Magento_Shipping::tracking/details.phtml), and the customer
 * should get both. The status goes in the table; the link comes here.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Block\Tracking;

use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;
use Magento\Shipping\Model\Tracking\Result\Status;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\Magento\TrackingInfo;

class PigeonLinks extends Template
{
    /** @var Registry */
    private $registry;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(Template\Context $context, Registry $registry, array $data = [])
    {
        $this->registry = $registry;
        parent::__construct($context, $data);
    }

    /**
     * @return array<string, string> Number => tracking page address
     */
    public function getLinks(): array
    {
        $info = $this->registry->registry('current_shipping_info');
        if (!is_object($info) || !method_exists($info, 'getTrackingInfo')) {
            return [];
        }

        $links = [];
        foreach ((array) $info->getTrackingInfo() as $tracks) {
            foreach ((array) $tracks as $track) {
                if (!$track instanceof Status
                    || $track->getCarrier() !== ConfigInterface::CARRIER_CODE
                    || $track->getUrl()
                ) {
                    continue;
                }
                $url = (string) $track->getData(TrackingInfo::URL_KEY);
                if ($url !== '') {
                    $links[(string) $track->getTracking()] = $url;
                }
            }
        }

        return $links;
    }
}
