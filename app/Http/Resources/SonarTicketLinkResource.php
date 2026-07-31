<?php

namespace App\Http\Resources;

use App\Models\SonarTicketLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SonarTicketLink */
class SonarTicketLinkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'ticket_id' => $this->ticket_id,
            'subject' => $this->subject,
            'status' => $this->status,
            'priority' => $this->priority,
            'assignee_name' => $this->assignee_name,
            'group_name' => $this->group_name,
            'account_name' => $this->account_name,
            'sonar_created_at' => $this->sonar_created_at,
            'sonar_closed_at' => $this->sonar_closed_at,
            'url' => str_replace('{id}', (string) $this->ticket_id, (string) config('mymate.sonar.ticket_url_template')),
            'cached_at' => $this->cached_at,
            // Set by SonarTicketLinkController when a refresh was due but Sonar couldn't be
            // reached - the row shown is the last-known-good cache, not necessarily current.
            'stale' => (bool) ($this->is_stale ?? false),
            'linked_by_name' => $this->linked_by_name,
            'created_at' => $this->created_at,
            // Current viewer may unlink this ticket: they linked it, or they're an admin.
            'editable' => $user !== null && ($user->isAdmin() || ($this->linked_by !== null && $user->id === $this->linked_by)),
        ];
    }
}
