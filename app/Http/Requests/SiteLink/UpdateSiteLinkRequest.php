<?php

namespace App\Http\Requests\SiteLink;

use App\Models\SiteLink;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSiteLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'site_a_id' => ['sometimes', 'required', Rule::exists('sites', 'id'), $this->pairNotAlreadyLinked()],
            'site_b_id' => [
                'sometimes', 'required',
                Rule::exists('sites', 'id'),
                Rule::notIn([$this->input('site_a_id')]),
            ],
            'media_type' => ['sometimes', 'nullable', 'string', 'max:16', Rule::in(['fiber', 'wireless', 'other'])],
            'external_ref' => [
                'sometimes', 'nullable', 'string', 'max:255',
                Rule::unique('site_links', 'external_ref')->ignore($this->route('site_link')?->id),
            ],
        ];
    }

    /**
     * Rejects re-pointing the link at a pair that already exists in either orientation (A-B and
     * B-A are the same link), other than the link being edited itself.
     */
    private function pairNotAlreadyLinked(): \Closure
    {
        $request = $this;
        $selfId = $this->route('site_link')?->id;

        return function (string $attribute, mixed $value, \Closure $fail) use ($request, $selfId) {
            $a = $request->input('site_a_id');
            $b = $request->input('site_b_id');
            if ($a === null || $b === null || $a === $b) {
                return; // shape errors are the other rules' job
            }
            $clash = SiteLink::query()
                ->where(function ($q) use ($a, $b) {
                    $q->where('site_a_id', $a)->where('site_b_id', $b);
                })->orWhere(function ($q) use ($a, $b) {
                    $q->where('site_a_id', $b)->where('site_b_id', $a);
                })
                ->whereKeyNot($selfId ?? 0)
                ->exists();
            if ($clash) {
                $fail('A link between these two sites already exists.');
            }
        };
    }
}
