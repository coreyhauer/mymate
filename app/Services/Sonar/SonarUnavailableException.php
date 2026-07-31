<?php

namespace App\Services\Sonar;

use RuntimeException;

/**
 * Sonar's GraphQL API couldn't be reached, wasn't configured, refused auth, or returned a
 * GraphQL-level error. Distinct from "the ticket doesn't exist" - fetchTicket()/fetchTickets()
 * return null / omit the id for that, not an exception. Callers catch this to fall back to
 * serving stale cached data instead of failing the request (see SonarTicketLinkController).
 */
class SonarUnavailableException extends RuntimeException {}
