<?php

namespace App\Http\Requests;

use App\Models\Adult;
use App\Models\AdultAccess;
use App\Models\Child;
use App\Models\Site;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStaffPersonRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['also_known_as' => $this->input('also_known_as', [])]);
        if ($this->has('email')) {
            $this->merge(['email' => filled($this->input('email')) ? mb_strtolower(trim($this->input('email'))) : null]);
        }
    }

    public function authorize(): bool
    {
        $site = $this->route('site');
        $child = $this->route('child');

        abort_unless($site instanceof Site && $child instanceof Child, 404);
        abort_unless($site->children()->whereKey($child->getKey())->exists(), 404);
        $adult = $this->route('adult');
        if ($adult !== null) {
            abort_unless($adult instanceof Adult, 404);
            abort_unless($child->adults()->whereKey($adult->getKey())->exists(), 404);
        }

        return $this->user()->can('processCollections', $site);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'also_known_as' => ['present', 'array', 'max:50'],
            'also_known_as.*' => ['required', 'string', 'max:255', 'distinct'],
            'new_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
        if ($this->route('adult') !== null) {
            $rules['relationship'] = ['required', 'string', 'max:255'];
            $rules['phone'] = ['sometimes', 'nullable', 'string', 'max:50'];
            $adult = $this->route('adult');
            abort_unless($adult instanceof Adult, 404);
            $rules['email'] = ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('adults', 'email')->ignore($adult->id), function (string $attribute, mixed $value, \Closure $fail) use ($adult): void {
                if ($value !== $adult->email && AdultAccess::query()->where('adult_id', $adult->id)->exists()) {
                    $fail('Email changes for adults with online access must use the verified adult-page update workflow.');
                }
            }];
        }

        return $rules;
    }
}
