<?php
/**
 * Pigeon Express API exception.
 *
 * When the error came from a Pigeon Express answer, the SDK exception is kept
 * as the cause, and the HTTP status, the API's machine-readable `code` and the
 * whole error body can be read from here. Decide by those, never by the
 * message, which is meant for people.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Exception;

use Magento\Framework\Exception\LocalizedException;
use PigeonExpress\Exceptions\PigeonExpressException;

class ApiException extends LocalizedException
{
    /**
     * HTTP status of the Pigeon Express answer behind this error, or 0 when
     * no answer came (a timeout, missing credentials, a check made here).
     */
    public function getHttpStatus(): int
    {
        $cause = $this->sdkCause();

        return $cause !== null ? (int) $cause->getHttpStatusCode() : 0;
    }

    /**
     * The API's machine-readable reason (`code` in its answer, for example
     * requires_office_payment, already_redirected, not_found or
     * backend_unavailable), or '' when it gave none.
     */
    public function getApiErrorCode(): string
    {
        $cause = $this->sdkCause();

        return $cause !== null ? (string) $cause->getErrorCode() : '';
    }

    /**
     * The API's whole error answer, decoded (`message`, `code`, `errors`,
     * `price`, `active_reference_number`, `conflicts`, ...), or [] when there
     * was none.
     *
     * @return array<string, mixed>
     */
    public function getApiErrorData(): array
    {
        $cause = $this->sdkCause();
        $data = $cause !== null ? $cause->getResponseData() : [];

        return is_array($data) ? $data : [];
    }

    /**
     * The SDK exception this error was raised for, looked up along the chain
     * of causes, or null.
     */
    private function sdkCause(): ?PigeonExpressException
    {
        for ($cause = $this->getPrevious(); $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof PigeonExpressException) {
                return $cause;
            }
        }

        return null;
    }
}
