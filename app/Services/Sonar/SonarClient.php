<?php

namespace App\Services\Sonar;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Read-only client for Sonar's GraphQL ticketing API (gigfire.sonar.software). My Mate only
 * ever *reads* tickets here - it never creates/updates/closes them (that stays in Sonar's own
 * UI/workflow); see SonarTicketLinkController + RefreshSonarTicketLinksCommand for the callers.
 *
 * Batching: Sonar's `tickets` field takes a single `id` (Int64Bit) - schema introspection
 * confirms there is no id-list/IN filter (the `search.integer_fields` range filter only offers
 * EQ/NEQ/GT/GTE/LT/LTE against ONE value, and `id` itself isn't a list type). A multi-id fetch
 * is therefore batched as ONE HTTP request using GraphQL query aliasing
 * (`t0: tickets(id: ...) { entities { ... } } t1: tickets(id: ...) { ... }`), verified against
 * production to still cost exactly 1 request against the 5000/token rate limit.
 */
class SonarClient
{
    /** Fields pulled for every ticket - kept in one place so every fetch has the same shape. */
    private const TICKET_FIELDS = <<<'GQL'
        id subject status priority description created_at updated_at closed_at due_date
        ticketable_type ticketable_id
        ticket_group { id name }
        user { id name }
        ticketable { id ... on Account { id name } ... on NetworkSite { id name } }
        GQL;

    /** Fetch a single ticket by id, or null if Sonar has no ticket with that id. */
    public function fetchTicket(int $id): ?array
    {
        return $this->fetchTickets([$id])[$id] ?? null;
    }

    /**
     * Fetch multiple tickets by id in one HTTP request (aliased sub-queries - see class docs).
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>> keyed by ticket id; an id Sonar doesn't have is
     *                                           simply absent from the result, not an error
     */
    public function fetchTickets(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            fn (int $id): bool => $id > 0,
        )));

        if ($ids === []) {
            return [];
        }

        $aliases = [];
        foreach ($ids as $i => $id) {
            // $id is cast to int above, so this is safe to interpolate directly.
            $aliases[] = "t{$i}: tickets(id: {$id}) { entities { ".self::TICKET_FIELDS." } }";
        }

        $body = $this->execute('query { '.implode(' ', $aliases).' }');

        $out = [];
        foreach ($ids as $i => $id) {
            $entities = $body['data']["t{$i}"]['entities'] ?? [];
            if ($entities !== []) {
                $out[$id] = $entities[0];
            }
        }

        return $out;
    }

    /** Map a raw Sonar ticket payload onto the columns SonarTicketLink caches. */
    public static function toCacheAttributes(array $ticket): array
    {
        return [
            'subject' => $ticket['subject'] ?? null,
            'status' => $ticket['status'] ?? null,
            'priority' => $ticket['priority'] ?? null,
            'assignee_name' => $ticket['user']['name'] ?? null,
            'group_name' => $ticket['ticket_group']['name'] ?? null,
            'account_name' => $ticket['ticketable']['name'] ?? null,
            'ticketable_type' => $ticket['ticketable_type'] ?? null,
            'ticketable_id' => isset($ticket['ticketable_id']) ? (int) $ticket['ticketable_id'] : null,
            'sonar_created_at' => $ticket['created_at'] ?? null,
            'sonar_closed_at' => $ticket['closed_at'] ?? null,
            'cached_at' => now(),
        ];
    }

    /** Base client: bearer token + JSON + a short timeout with one retry. Never logs the token. */
    private function client(): PendingRequest
    {
        $token = (string) config('mymate.sonar.token', '');
        if ($token === '') {
            throw new SonarUnavailableException('Sonar integration is not configured (no API token set).');
        }

        return Http::withToken($token)
            ->acceptJson()
            ->timeout(10)
            ->retry(1, 250);
    }

    /** @return array<string, mixed> decoded GraphQL response body */
    private function execute(string $query): array
    {
        $url = (string) config('mymate.sonar.url');

        try {
            $response = $this->client()->post($url, ['query' => $query]);
        } catch (SonarUnavailableException $e) {
            throw $e;
        } catch (Throwable $e) {
            // Connection/timeout failures - message is transport-level, never carries the token.
            throw new SonarUnavailableException('Could not reach Sonar: '.$e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            throw new SonarUnavailableException("Sonar returned HTTP {$response->status()}.");
        }

        $body = $response->json();

        // Auth failures come back as HTTP 200 with {"error":"Unauthenticated."} - not a
        // GraphQL `errors` array - so this has to be checked explicitly.
        if (is_array($body) && isset($body['error'])) {
            throw new SonarUnavailableException('Sonar auth failed: '.$body['error']);
        }

        if (is_array($body) && ! empty($body['errors'])) {
            $message = collect($body['errors'])->pluck('message')->implode('; ');
            throw new SonarUnavailableException('Sonar GraphQL error: '.$message);
        }

        return is_array($body) ? $body : [];
    }
}
