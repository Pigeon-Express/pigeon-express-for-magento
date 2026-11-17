<?php
/**
 * Whether the order panel offers a return shipment.
 *
 * Pigeon Express enables return (reverse) shipments per account and has no
 * endpoint that says whether it did: a body that fails validation is answered
 * the same way either way. So the panel offers the form until Pigeon Express
 * refuses one for that reason. The refusal is remembered for the environment
 * it came from and hides the form there, until the Pigeon Express settings
 * are saved again (Observer\Config\ClearReverseUnavailable) or the shop
 * switches environment. As WooCommerce's ReverseShipmentService::is_offered().
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\ReturnShipment;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\FlagManager;
use PigeonExpress\Exceptions\ValidationException as SdkValidationException;
use PigeonExpress\Shipping\Model\Environment;

class Availability
{
    public const XML_PATH_ENABLED = 'carriers/pigeonexpress/reverse_shipments';

    /** Flag row (table flag) holding {environment, at} of the last refusal. */
    public const FLAG_UNAVAILABLE = 'pigeonexpress_reverse_unavailable';

    /** @var ScopeConfigInterface */
    private $scopeConfig;

    /** @var FlagManager */
    private $flagManager;

    /** @var Environment */
    private $environment;

    public function __construct(ScopeConfigInterface $scopeConfig, FlagManager $flagManager, Environment $environment)
    {
        $this->scopeConfig = $scopeConfig;
        $this->flagManager = $flagManager;
        $this->environment = $environment;
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    public function isOffered(): bool
    {
        return $this->isEnabled() && !$this->isUnavailable();
    }

    /**
     * Whether Pigeon Express refused return shipments in the environment the
     * shop is in now. The other environment is another account setting.
     */
    public function isUnavailable(): bool
    {
        $stored = $this->flagManager->getFlagData(self::FLAG_UNAVAILABLE);

        return is_array($stored) && (string) ($stored['environment'] ?? '') === $this->environment->current();
    }

    public function markUnavailable(): void
    {
        $this->flagManager->saveFlag(self::FLAG_UNAVAILABLE, [
            'environment' => $this->environment->current(),
            'at'          => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function clear(): void
    {
        $this->flagManager->deleteFlag(self::FLAG_UNAVAILABLE);
    }

    /**
     * Whether a refused create says the account does not have return shipments.
     *
     * A business-rule 422 with no field errors and a message naming return
     * shipments and permission or the administrator (seen in the test
     * environment: "Нямате разрешение за създаване на обратни пратки.
     * Обърнете се към администратор."), or a field error under is_reverse.
     */
    public static function isNotEnabledRefusal(\Throwable $error): bool
    {
        while ($error !== null && !$error instanceof SdkValidationException) {
            $error = $error->getPrevious();
        }
        if ($error === null || (int) $error->getHttpStatusCode() !== 422) {
            return false;
        }

        $errors = $error->getErrors();
        if (is_array($errors) && $errors !== []) {
            return array_key_exists('is_reverse', $errors);
        }

        $message = mb_strtolower((string) $error->getMessage());

        return preg_match('/обратни(те)?\s+пратки/u', $message) === 1
            && (mb_strpos($message, 'разрешение') !== false || mb_strpos($message, 'администратор') !== false);
    }
}
