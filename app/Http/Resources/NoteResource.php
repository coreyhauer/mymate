<?php

namespace App\Http\Resources;

use App\Models\Note;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Note */
class NoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'body' => $this->body,
            'author_id' => $this->author_id,
            'author_name' => $this->author_name,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            // Current viewer may edit/delete this note: they wrote it, or they're an admin.
            'editable' => $user !== null && ($user->isAdmin() || $user->id === $this->author_id),
        ];
    }
}
