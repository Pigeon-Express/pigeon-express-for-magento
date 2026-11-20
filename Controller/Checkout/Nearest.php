<?php
/**
 * The offices or parcel lockers nearest the shopper, for the top of the
 * checkout picker ("Nearest office" setting).
 *
 * Open to guests like the other picker endpoints, but only as a POST with the
 * page's form key, only from a session that has a cart, and only while the
 * store has the setting on. Throttled per cart, per address and for the whole
 * shop inside NearestLocations. The point is rounded here, before anything
 * else sees it, so not even a stack trace of an unexpected failure can hold
 * more than the rounded point; such a failure answers the generic 503 and is
 * not logged. The point is used for this one lookup: never stored with the
 * quote, never logged, and never sent back.
 *
 * POST: form_key, type (office|aps), lat, lng.
 * Answers {success: true, locations: [picker row + distance]}, nearest first,
 * or {success: false, message} with 400, 403, 429 or 503.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Controller\Checkout;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Phrase;
use Magento\Store\Model\StoreManagerInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\Location\NearestLocations;
use PigeonExpress\Shipping\Model\Location\NearestRefusedException;

class Nearest implements HttpPostActionInterface
{
    /** @var RequestInterface */
    private $request;

    /** @var JsonFactory */
    private $resultJsonFactory;

    /** @var FormKeyValidator */
    private $formKeyValidator;

    /** @var CheckoutSession */
    private $checkoutSession;

    /** @var ConfigInterface */
    private $config;

    /** @var StoreManagerInterface */
    private $storeManager;

    /** @var NearestLocations */
    private $nearestLocations;

    /** @var RemoteAddress */
    private $remoteAddress;

    public function __construct(
        RequestInterface $request,
        JsonFactory $resultJsonFactory,
        FormKeyValidator $formKeyValidator,
        CheckoutSession $checkoutSession,
        ConfigInterface $config,
        StoreManagerInterface $storeManager,
        NearestLocations $nearestLocations,
        RemoteAddress $remoteAddress
    ) {
        $this->request = $request;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->formKeyValidator = $formKeyValidator;
        $this->checkoutSession = $checkoutSession;
        $this->config = $config;
        $this->storeManager = $storeManager;
        $this->nearestLocations = $nearestLocations;
        $this->remoteAddress = $remoteAddress;
    }

    public function execute(): ResultInterface
    {
        $storeId = (int) $this->storeManager->getStore()->getId();

        if (!$this->config->isNearestOfficeEnabled($storeId)) {
            return $this->refuse(400, __('Unknown request.'));
        }

        // Magento skips its own form key check for XHR posts, so it is made here.
        if (!$this->formKeyValidator->validate($this->request)) {
            return $this->refuse(403, $this->noSession());
        }

        $quoteId = (int) $this->checkoutSession->getQuoteId();
        if ($quoteId <= 0) {
            return $this->refuse(400, $this->noSession());
        }

        $type = (string) $this->post('type');
        if ($type !== ConfigInterface::DELIVERY_TYPE_OFFICE && $type !== ConfigInterface::DELIVERY_TYPE_APS) {
            return $this->refuse(400, __('Unknown location type.'));
        }

        $lat = trim((string) $this->post('lat'));
        $lng = trim((string) $this->post('lng'));
        $point = is_numeric($lat) && is_numeric($lng)
            ? NearestLocations::roundPoint((float) $lat, (float) $lng)
            : null;
        if ($point === null) {
            return $this->refuse(400, __('The location could not be read.'));
        }

        try {
            $locations = $this->nearestLocations->find(
                $type,
                $point[0],
                $point[1],
                'quote:' . $quoteId,
                (string) $this->remoteAddress->getRemoteAddress(),
                $storeId
            );
        } catch (NearestRefusedException $e) {
            return $this->refuse($this->statusFor($e->getReason()), $e->getMessage());
        } catch (\Throwable $e) {
            // A database or cache failure: the picker simply goes without.
            return $this->refuse(503, __('The nearest offices could not be found just now.'));
        }

        return $this->resultJsonFactory->create()->setData([
            'success' => true,
            'locations' => $locations,
        ]);
    }

    /**
     * A field of the POST body only: a point in a query string would end up
     * in access logs.
     *
     * @return mixed
     */
    private function post(string $key)
    {
        if ($this->request instanceof HttpRequest) {
            $value = $this->request->getPost($key);
        } else {
            $value = $this->request->getParam($key);
        }

        return is_scalar($value) ? $value : '';
    }

    private function statusFor(string $reason): int
    {
        switch ($reason) {
            case NearestRefusedException::BUSY:
                return 429;
            case NearestRefusedException::UNAVAILABLE:
                return 503;
            default:
                return 400;
        }
    }

    /**
     * @param Phrase|string $message
     */
    private function refuse(int $status, $message): ResultInterface
    {
        $result = $this->resultJsonFactory->create();
        $result->setHttpResponseCode($status);

        return $result->setData([
            'success' => false,
            'message' => (string) $message,
        ]);
    }

    private function noSession(): Phrase
    {
        return __('The nearest locations are not available right now. Reload the page and try again.');
    }
}
