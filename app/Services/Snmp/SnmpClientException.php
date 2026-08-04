<?php

namespace App\Services\Snmp;

use RuntimeException;

/**
 * SNMP failure (timeout, unreachable, no community). Named to avoid colliding
 * with PHP's built-in \SNMPException (class names are case-insensitive, so
 * "SnmpException" and "SNMPException" would resolve to the same symbol).
 *
 * Messages must never contain the community string - only host + transport error.
 *
 * `$transport` is true only when the device never answered (get/walk timeout at
 * the wire in PhpSnmpClient) - the flag ConnectBackoff's circuit breaker keys on.
 * A missing/misconfigured credential leaves it false: the device may be fine.
 */
class SnmpClientException extends RuntimeException
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
