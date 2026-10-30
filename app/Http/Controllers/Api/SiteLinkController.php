<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SiteLink\StoreSiteLinkRequest;
use App\Http\Requests\SiteLink\UpdateSiteLinkRequest;
use App\Http\Resources\SiteLinkResource;
use App\Models\SiteLink;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Site-to-site backhaul links (the geo map's dashed/solid tower-to-tower lines). Read is open
 * to any operator; writes are gated to admins by the global RestrictWritesToAdmins middleware.
 *
 * This used to be artisan-only (mymate:sites:import --links), which made an operator-spotted
 * missing pair unseen until someone opened a shell. Same table, same pair semantics:
 * external_ref carries the order-independent `pair:min-max` key, so an API-created link and a
 * re-run OSS import agree on uniqueness instead of duplicating the pair.
 */
class SiteLinkController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $links = SiteLink::query()
            ->with(['siteA:id,name', 'siteB:id,name'])
            ->orderBy('id')
            ->get();

        return SiteLinkResource::collection($links);
    }

    public function store(StoreSiteLinkRequest $request): SiteLinkResource
    {
        $data = $this->pairKey($request->validated());

        return new SiteLinkResource(SiteLink::create($data)->loadMissing(['siteA:id,name', 'siteB:id,name']));
    }

    public function update(UpdateSiteLinkRequest $request, SiteLink $site_link): SiteLinkResource
    {
        $data = $this->pairKey($request->validated());
        $site_link->update($data);

        return new SiteLinkResource($site_link->loadMissing(['siteA:id,name', 'siteB:id,name']));
    }

    public function destroy(SiteLink $site_link): Response
    {
        $site_link->delete();

        return response()->noContent();
    }

    /**
     * Fill external_ref with the importer's order-independent pair key when the caller did not
     * supply one, so A-B and B-A stay the same link across both write paths.
     */
    private function pairKey(array $data): array
    {
        if (empty($data['external_ref']) && isset($data['site_a_id'], $data['site_b_id'])) {
            $data['external_ref'] = 'pair:'.min($data['site_a_id'], $data['site_b_id']).'-'.max($data['site_a_id'], $data['site_b_id']);
        }

        return $data;
    }
}
