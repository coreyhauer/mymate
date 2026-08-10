<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceSiteChange;
use App\Services\Assignment\DeviceSiteAssigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The review queue for suggested device->site corrections.
 *
 * Reading is open to any operator - seeing what the detector thinks is harmless and
 * useful. Deciding is not: approving applies a real move, so it goes through the same
 * `canMoveDevices` gate as the device editor and the same DeviceSiteAssigner, which
 * means an approval lands in the audit log and is revertible like any other change.
 *
 * A rejection is as valuable as an approval - together they are the labelled data that
 * makes per-signal precision measurable, which is the only honest basis for ever
 * promoting a rule to automatic application.
 */
class SiteProposalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = DB::table('device_site_proposals as p')
            ->leftJoin('devices as d', 'p.device_id', '=', 'd.id')
            ->leftJoin('sites as cur', 'p.current_site_id', '=', 'cur.id')
            ->leftJoin('sites as sug', 'p.suggested_site_id', '=', 'sug.id')
            ->where('p.status', $request->query('status', 'open'))
            ->when($request->query('confidence'), fn ($q, $c) => $q->where('p.confidence', $c))
            ->orderByRaw("case p.confidence when 'high' then 0 else 1 end")
            ->orderBy('p.id')
            ->limit((int) $request->query('limit', 200))
            ->selectRaw('p.id, p.device_id, p.confidence, p.rationale, p.signals, p.status,
                         p.decided_by_name, p.decided_at, p.decision_note,
                         d.name as device_name, d.mgmt_ip::text as device_ip,
                         p.current_site_id, cur.name as current_site_name,
                         p.suggested_site_id, sug.name as suggested_site_name')
            ->get()
            ->map(fn ($r) => (array) $r + ['signals' => json_decode((string) $r->signals, true)]);

        return response()->json([
            'data' => $rows,
            'counts' => DB::table('device_site_proposals')
                ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'can_decide' => (bool) $request->user()?->canMoveDevices(),
        ]);
    }

    public function approve(Request $request, int $id, DeviceSiteAssigner $assigner): JsonResponse
    {
        $this->assertMayDecide($request);
        $p = $this->openProposal($id);

        $change = $assigner->move($p->device_id, $p->suggested_site_id, [
            'source' => DeviceSiteChange::SOURCE_AGENT,
            'site_source' => 'manual',   // a human approved it; imports must not overwrite
            'actor_id' => $request->user()->id,
            'actor_name' => $request->user()->name,
            'actor_email' => $request->user()->email,
            'reason' => "approved proposal #{$id}",
        ]);

        DB::table('device_site_proposals')->where('id', $id)->update([
            'status' => 'approved',
            'decided_by' => $request->user()->id,
            'decided_by_name' => $request->user()->name,
            'decided_at' => now(),
            'decision_note' => $request->input('note'),
            'applied_change_id' => $change?->id,
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'change_id' => $change?->id]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $this->assertMayDecide($request);
        $this->openProposal($id);

        DB::table('device_site_proposals')->where('id', $id)->update([
            'status' => 'rejected',
            'decided_by' => $request->user()->id,
            'decided_by_name' => $request->user()->name,
            'decided_at' => now(),
            'decision_note' => $request->input('note'),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    private function assertMayDecide(Request $request): void
    {
        if (! $request->user()?->canMoveDevices()) {
            throw new AccessDeniedHttpException('You are not permitted to move devices between sites.');
        }
    }

    private function openProposal(int $id): object
    {
        $p = DB::table('device_site_proposals')->where('id', $id)->first();
        if (! $p || $p->status !== 'open') {
            abort(404, 'No open proposal with that id.');
        }

        return $p;
    }
}
