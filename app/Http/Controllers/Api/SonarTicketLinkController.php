<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSonarTicketLinkRequest;
use App\Http\Resources\SonarTicketLinkResource;
use App\Models\Device;
use App\Models\Link;
use App\Models\Site;
use App\Models\SonarTicketLink;
use App\Services\Sonar\SonarClient;
use App\Services\Sonar\SonarUnavailableException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Sonar tickets linked to a device, site, or link - the NOC's live-ticket panel. Mirrors
 * NoteController's shape: one controller for all three subject types, thin `for*`/`storeFor*`
 * wrappers for the route-model-bound parameter, shared private logic underneath.
 *
 * Reads (`index`) opportunistically refresh any stale-cached link from Sonar, batched into one
 * HTTP request (SonarClient::fetchTickets). Sonar being unreachable never fails the read -
 * callers get the last-known-good cached data back with `stale: true` on the affected rows
 * (see SonarTicketLinkResource) instead of a 5xx.
 */
class SonarTicketLinkController extends Controller
{
    public function __construct(private SonarClient $sonar) {}

    public function forDevice(Device $device): AnonymousResourceCollection
    {
        return $this->index($device);
    }

    public function forSite(Site $site): AnonymousResourceCollection
    {
        return $this->index($site);
    }

    public function forLink(Link $link): AnonymousResourceCollection
    {
        return $this->index($link);
    }

    public function storeForDevice(StoreSonarTicketLinkRequest $request, Device $device): JsonResponse
    {
        return $this->store($request, $device);
    }

    public function storeForSite(StoreSonarTicketLinkRequest $request, Site $site): JsonResponse
    {
        return $this->store($request, $site);
    }

    public function storeForLink(StoreSonarTicketLinkRequest $request, Link $link): JsonResponse
    {
        return $this->store($request, $link);
    }

    /** Force-refresh one linked ticket from Sonar now, regardless of cache age. */
    public function refresh(SonarTicketLink $sonarTicketLink): SonarTicketLinkResource
    {
        $ticket = null;
        $failed = false;

        try {
            $ticket = $this->sonar->fetchTicket((int) $sonarTicketLink->ticket_id);
        } catch (SonarUnavailableException $e) {
            Log::warning('sonar: manual refresh failed', ['ticket_id' => $sonarTicketLink->ticket_id, 'error' => $e->getMessage()]);
            $failed = true;
        }

        if ($ticket !== null) {
            $sonarTicketLink->update(SonarClient::toCacheAttributes($ticket));
        } elseif ($failed) {
            $sonarTicketLink->setAttribute('is_stale', true);
        }

        return new SonarTicketLinkResource($sonarTicketLink);
    }

    public function destroy(Request $request, SonarTicketLink $sonarTicketLink): Response
    {
        $this->authorizeEdit($request, $sonarTicketLink);

        $sonarTicketLink->delete();

        return response()->noContent();
    }

    /** @param  Device|Site|Link  $notable  (each has a `sonarTicketLinks(): MorphMany` relation) */
    private function index(Model $notable): AnonymousResourceCollection
    {
        /** @var MorphMany $relation */
        $relation = $notable->sonarTicketLinks();
        $links = $relation->latest()->get();

        $ttl = (int) config('mymate.sonar.cache_ttl', 300);
        $cutoff = now()->subSeconds($ttl);
        $expired = $links->filter(
            fn (SonarTicketLink $link): bool => $link->cached_at === null || $link->cached_at->lt($cutoff)
        );

        if ($expired->isNotEmpty()) {
            if (! config('mymate.sonar.enabled')) {
                $expired->each(fn (SonarTicketLink $link) => $link->setAttribute('is_stale', true));
            } else {
                try {
                    $fresh = $this->sonar->fetchTickets(
                        $expired->pluck('ticket_id')->map(fn ($id): int => (int) $id)->all()
                    );

                    foreach ($expired as $link) {
                        if (isset($fresh[(int) $link->ticket_id])) {
                            $link->forceFill(SonarClient::toCacheAttributes($fresh[(int) $link->ticket_id]))->save();
                        } else {
                            // Not a transport failure - Sonar answered and simply doesn't have
                            // this id any more (deleted ticket). Keep the cached row, flag it.
                            $link->setAttribute('is_stale', true);
                        }
                    }
                } catch (SonarUnavailableException $e) {
                    Log::warning('sonar: batch refresh failed', ['error' => $e->getMessage()]);
                    $expired->each(fn (SonarTicketLink $link) => $link->setAttribute('is_stale', true));
                }
            }
        }

        return SonarTicketLinkResource::collection($links);
    }

    /** @param  Device|Site|Link  $notable  (each has a `sonarTicketLinks(): MorphMany` relation) */
    private function store(StoreSonarTicketLinkRequest $request, Model $notable): JsonResponse
    {
        if (! config('mymate.sonar.enabled')) {
            abort(Response::HTTP_SERVICE_UNAVAILABLE, 'Sonar is not configured on this install.');
        }

        $ticketId = $this->parseTicketReference($request->validated()['reference']);
        if ($ticketId === null) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Could not find a ticket number in that reference.');
        }

        /** @var MorphMany $relation */
        $relation = $notable->sonarTicketLinks();

        if ($relation->where('ticket_id', $ticketId)->exists()) {
            abort(Response::HTTP_CONFLICT, "Ticket #{$ticketId} is already linked here.");
        }

        try {
            $ticket = $this->sonar->fetchTicket($ticketId);
        } catch (SonarUnavailableException $e) {
            Log::warning('sonar: link lookup failed', ['ticket_id' => $ticketId, 'error' => $e->getMessage()]);
            abort(Response::HTTP_SERVICE_UNAVAILABLE, 'Could not reach Sonar to verify this ticket - try again shortly.');
        }

        if ($ticket === null) {
            abort(Response::HTTP_NOT_FOUND, "Sonar ticket #{$ticketId} was not found.");
        }

        $user = $request->user();

        $link = $relation->create([
            ...SonarClient::toCacheAttributes($ticket),
            'ticket_id' => $ticketId,
            'linked_by' => $user?->id,
            'linked_by_name' => $user?->name,
        ]);

        return (new SonarTicketLinkResource($link))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Pull a ticket id out of whatever an operator pastes in: a bare number ("108314"), a
     * `#`-prefixed number ("#108314"), or a full Sonar ticket URL
     * (https://gigfire.sonar.software/app#/tickets/show/108314). Returns null when nothing
     * usable is found.
     */
    private function parseTicketReference(string $reference): ?int
    {
        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }

        if (preg_match('/^#?(\d+)$/', $reference, $matches)) {
            return (int) $matches[1];
        }

        // Sonar's own ticket URL shape, matched explicitly so a trailing query string or
        // fragment ("...\/show\/108314?tab=2") can't make the fallback below grab the "2".
        if (preg_match('#/tickets/(?:show/)?(\d+)#', $reference, $matches)) {
            return (int) $matches[1];
        }

        if (preg_match('/(\d+)\D*$/', $reference, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /** Non-admin may remove only a link they created; admin may remove any. */
    private function authorizeEdit(Request $request, SonarTicketLink $link): void
    {
        $user = $request->user();

        if (! $user || (! $user->isAdmin() && $link->linked_by !== $user->id)) {
            abort(Response::HTTP_FORBIDDEN, 'You may only remove ticket links you created.');
        }
    }
}
