<?php

namespace Tests\Unit;

use App\Services\RouterOs\EvilFreelancerRouterOsClient;
use PHPUnit\Framework\TestCase;
use RouterOS\Exceptions\BadCredentialsException;
use RouterOS\Exceptions\ClientException;
use RouterOS\Exceptions\ConfigException;
use RouterOS\Exceptions\ConnectException;

/**
 * Pure classification test - no socket, no live device. evilfreelancer/routeros-api-php
 * throws RouterOS\Exceptions\ConnectException from the socket-level connect (timeout,
 * refused, no route - see SocketTrait::openSocket()/Client::connect()) and
 * RouterOS\Exceptions\BadCredentialsException only from Client::login(), which is reached
 * *after* that socket connect already succeeded. isTransportFailure() is what
 * App\Services\Polling\ConnectBackoff relies on (via RouterOsClientException::$transport,
 * set by EvilFreelancerRouterOsClient::open()) to decide whether a connect failure should
 * trip the per-device backoff circuit breaker.
 */
class EvilFreelancerRouterOsClientTest extends TestCase
{
    public function test_bad_credentials_is_not_a_transport_failure(): void
    {
        // The device answered and rejected the login - it's alive, just misconfigured.
        $this->assertFalse(EvilFreelancerRouterOsClient::isTransportFailure(
            new BadCredentialsException('Invalid user name or password')
        ));
    }

    public function test_connect_exception_is_a_transport_failure(): void
    {
        // Socket-level failure (timeout/refused/no-route) - the device never answered at all.
        $this->assertTrue(EvilFreelancerRouterOsClient::isTransportFailure(
            new ConnectException('Unable to connect to 10.0.0.1:8728')
        ));
    }

    public function test_other_client_library_exceptions_are_treated_as_transport_failures(): void
    {
        // ConfigException/ClientException/StreamException/etc. all mean we never got a
        // working session - conservatively treated the same as a connect failure.
        $this->assertTrue(EvilFreelancerRouterOsClient::isTransportFailure(new ConfigException('bad config')));
        $this->assertTrue(EvilFreelancerRouterOsClient::isTransportFailure(new ClientException('socket timeout reached')));
    }

    public function test_unexpected_throwable_defaults_to_transport_failure(): void
    {
        $this->assertTrue(EvilFreelancerRouterOsClient::isTransportFailure(new \RuntimeException('boom')));
    }
}
