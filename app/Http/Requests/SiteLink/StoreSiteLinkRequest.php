<?php

namespace App\Http\Requests\SiteLink;

use App\Models\SiteLink;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSiteLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null; // authenticated operators only (writes gated to admins by middleware)
    }

    public function rules(): array
    {
        return [
            'site_a_id' => ['required', Rule::exists('sites', 'id'), $this->pairNotAlreadyLinked()],
            'site_b_id' => [
                'required',
                Rule::exists('sites', 'id'),
                // A link to itself is meaningless (ImportSiteLinks skips these too).
                Rule::notIn([$this->input('site_a_id')]),
            ],
            'media_type' => ['nullable', 'string', 'max:16', Rule::in(['fiber', 'wireless', 'other'])],
            'external_ref' => ['nullable', 'string', 'max:255', Rule::unique('site_links', 'external_ref')],
        ];
    }

    /**
     * Rejects a pair that already exists in either orientation - A-B and B-A are the same link
     * (ImportSiteLinks keys on the order-independent pair, so the API must agree).
     */
    private function pairNotAlreadyLinked(): \Closure
    {
        $request = $this;

        return function (string $attribute, mixed $value, \Closure $fail) use ($request) {
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
                ->exists();
            if ($clash) {
                $fail('A link between these two sites already exists.');
            }
        };
    }
}
