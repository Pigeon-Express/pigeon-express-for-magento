<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Exception;

use PHPUnit\Framework\TestCase;
use PigeonExpress\Exceptions\ValidationException;
use PigeonExpress\Shipping\Exception\ApiException;
use PigeonExpress\Shipping\Exception\OutcomeUnknownException;

/**
 * What a caller decides by: the status, code and body of the Pigeon Express
 * answer behind a module exception.
 */
class ApiExceptionTest extends TestCase
{
    public function testTheAnswerIsReadFromTheCause(): void
    {
        $sdk = (new ValidationException('Пратката е пренасочена.', [], 422))->withResponseData([
            'code' => 'already_redirected',
            'active_reference_number' => '459073712345',
        ]);

        $e = new ApiException(__('Refused'), $sdk);

        $this->assertSame(422, $e->getHttpStatus());
        $this->assertSame('already_redirected', $e->getApiErrorCode());
        $this->assertSame('459073712345', $e->getApiErrorData()['active_reference_number']);
    }

    public function testTheCauseIsFoundFurtherDownTheChain(): void
    {
        $sdk = (new ValidationException('x', [], 422))->withResponseData(['code' => 'no_change']);
        $middle = new \RuntimeException('wrapped', 0, $sdk);

        $e = new OutcomeUnknownException(__('Unknown'), $middle);

        $this->assertSame('no_change', $e->getApiErrorCode());
        $this->assertSame(422, $e->getHttpStatus());
    }

    public function testWithoutAnAnswerThereIsNothingToRead(): void
    {
        $e = new ApiException(__('Pigeon Express API key is not configured.'));

        $this->assertSame(0, $e->getHttpStatus());
        $this->assertSame('', $e->getApiErrorCode());
        $this->assertSame([], $e->getApiErrorData());
    }
}
