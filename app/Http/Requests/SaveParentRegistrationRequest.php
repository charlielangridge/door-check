<?php

namespace App\Http\Requests;

use App\Models\ParentRegistration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveParentRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $registration = $this->route('registration');
        abort_unless($registration instanceof ParentRegistration && $registration->canEdit()
            && hash_equals($registration->access_key, (string) $this->query('key')), 410, 'This registration link is no longer valid.');
        if ($this->input('action') !== 'verify') {
            abort_unless($this->session()->get('parent_registration.'.$registration->uuid) === $registration->access_key, 403);
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->input('action') === 'verify') {
            return ['action' => ['required', Rule::in(['verify'])], 'date_of_birth' => ['required', 'date_format:Y-m-d']];
        }
        $required = $this->input('action') === 'submit' ? 'required' : 'nullable';

        return [
            'action' => ['required', Rule::in(['draft', 'submit'])],
            'name' => [$required, 'string', 'max:255'],
            'also_known_as' => ['sometimes', 'array', 'max:50'],
            'also_known_as.*' => ['required', 'string', 'max:255', 'distinct'],
            'new_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'adults' => ['required', 'array', 'list', 'min:1', 'max:20'],
            'adults.*.key' => ['required', 'uuid', 'distinct'],
            'adults.*.name' => [$required, 'string', 'max:255'],
            'adults.*.date_of_birth' => [$required, 'date_format:Y-m-d', 'before_or_equal:today'],
            'adults.*.phone' => [$required, 'string', 'max:50'],
            'adults.*.email' => ['nullable', 'email', 'max:255', 'distinct:ignore_case'],
            'adults.*.relationship' => [$required, 'string', 'max:255'],
            'adults.*.also_known_as' => ['sometimes', 'array', 'max:50'],
            'adults.*.also_known_as.*' => ['required', 'string', 'max:255', 'distinct'],
            'adults.*.new_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'adults.*.document_responsible' => ['required', 'boolean'],
            'documents' => ['sometimes', 'array', 'max:100'],
            'documents.*' => ['required', 'integer', 'distinct'],
        ];
    }
}
