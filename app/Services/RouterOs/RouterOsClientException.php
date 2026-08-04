<?php

namespace App\Services\RouterOs;

use RuntimeException;

/**
 * RouterOS API failure (connect timeout on a filtered port, bad credentials,
 * transport error, missing credential). Messages carry the host + error only -
 * never the username/password.
 *
 * `$transport` is true only when the device never answered at the wire level
 * (connect timeout/refused/no route) - set at the single connect site in
 * EvilFreelancerRouterOsClient::open() via its isTransportFailure() classifier.
 * ConnectBackoff keys its circuit breaker off this flag: an auth/config failure
 * (device answered, rejected us) must never back a device off.
 */
class RouterOsClientException extends RuntimeException
{
    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly bool $transport = false,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
