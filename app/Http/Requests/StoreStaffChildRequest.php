<?php

namespace App\Http\Requests;

use App\Models\Site;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStaffChildRequest extends FormRequest
{
    public function authorize(): bool
    {
        $site = $this->route('site');

        return $site instanceof Site && $this->user()->can('processCollections', $site);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'also_known_as' => ['sometimes', 'array', 'max:50'],
            'also_known_as.*' => ['required', 'string', 'max:255', 'distinct'],
            'new_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'create_different' => ['sometimes', 'boolean'],
            'adults' => ['required', 'array', 'min:1', 'max:50'],
            'adults.*.mode' => ['required', Rule::in(['existing', 'new'])],
            'adults.*.adult_id' => ['exclude_if:adults.*.mode,new', 'required', 'integer', 'distinct'],
            'adults.*.relationship' => ['required', 'string', 'max:255'],
            'adults.*.name' => ['exclude_if:adults.*.mode,existing', 'required', 'string', 'max:255'],
            'adults.*.date_of_birth' => ['exclude_if:adults.*.mode,existing', 'required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'adults.*.also_known_as' => ['exclude_if:adults.*.mode,existing', 'sometimes', 'array', 'max:50'],
            'adults.*.also_known_as.*' => ['required', 'string', 'max:255', 'distinct'],
            'adults.*.new_photo' => ['exclude_if:adults.*.mode,existing', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'adults.*.create_different' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'adults.required' => 'Link or add at least one adult before saving the child.',
            'adults.min' => 'Link or add at least one adult before saving the child.',
            'adults.*.adult_id.required' => 'Choose an adult from this venue.',
            'adults.*.adult_id.distinct' => 'This adult is already linked in this registration.',
            'adults.*.relationship.required' => 'Enter this adult’s relationship to the child.',
        ];
    }
}
