<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreNoteRequest;
use App\Http\Resources\NoteResource;
use App\Models\Device;
use App\Models\Link;
use App\Models\Note;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Threaded notes attachable to a device, site, or link. One controller for all three subject
 * types (rather than three near-identical controllers) - the thin `for*`/`storeFor*` wrappers
 * exist only because each subject needs its own route-model-bound parameter; they all delegate
 * to the shared `index`/`store` below. PATCH/DELETE are keyed by the note id directly, since a
 * note doesn't need its subject once it exists.
 */
class NoteController extends Controller
{
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

    public function storeForDevice(StoreNoteRequest $request, Device $device): JsonResponse
    {
        return $this->store($request, $device);
    }

    public function storeForSite(StoreNoteRequest $request, Site $site): JsonResponse
    {
        return $this->store($request, $site);
    }

    public function storeForLink(StoreNoteRequest $request, Link $link): JsonResponse
    {
        return $this->store($request, $link);
    }

    public function update(StoreNoteRequest $request, Note $note): NoteResource
    {
        $this->authorizeEdit($request, $note);

        $note->update(['body' => $request->validated()['body']]);

        return new NoteResource($note);
    }

    public function destroy(Request $request, Note $note): Response
    {
        $this->authorizeEdit($request, $note);

        $note->delete();

        return response()->noContent();
    }

    /** @param  Device|Site|Link  $notable  (each has a `notes(): MorphMany` relation) */
    private function index(Model $notable): AnonymousResourceCollection
    {
        /** @var MorphMany $relation */
        $relation = $notable->notes();

        return NoteResource::collection($relation->latest()->get());
    }

    /** @param  Device|Site|Link  $notable  (each has a `notes(): MorphMany` relation) */
    private function store(StoreNoteRequest $request, Model $notable): JsonResponse
    {
        $user = $request->user();

        /** @var MorphMany $relation */
        $relation = $notable->notes();

        $note = $relation->create([
            'body' => $request->validated()['body'],
            'author_id' => $user?->id,
            'author_name' => $user?->name,
        ]);

        return (new NoteResource($note))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /** Non-admin may edit/delete only their own note; admin may edit/delete any. */
    private function authorizeEdit(Request $request, Note $note): void
    {
        $user = $request->user();

        if (! $user || (! $user->isAdmin() && $note->author_id !== $user->id)) {
            abort(Response::HTTP_FORBIDDEN, 'You may only edit or delete your own notes.');
        }
    }
}
