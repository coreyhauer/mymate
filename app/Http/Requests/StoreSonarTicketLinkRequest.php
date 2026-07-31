<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The raw `reference` an operator pastes in - a bare ticket id ("108314"), a `#`-prefixed id
 * ("#108314"), or a full Sonar ticket URL. SonarTicketLinkController parses the id out of it
 * (any of those shapes) and 422s if none can be found.
 */
class StoreSonarTicketLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'max:2048'],
        ];
    }
}
