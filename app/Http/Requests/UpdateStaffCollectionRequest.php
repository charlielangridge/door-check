<?php

namespace App\Http\Requests;

use App\CollectionCancellationReason;
use App\PhotoVerificationReason;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStaffCollectionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('access staff') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'revision' => ['required', 'integer', 'min:1'],
            'action' => ['required', Rule::in(['review', 'prepare', 'collect', 'cancel', 'discard', 'flag_duplicate'])],
            'adult_id' => ['nullable', 'integer'],
            'child_id' => ['required_if:action,flag_duplicate', 'nullable', 'integer', 'min:1'],
            'identity_confirmed' => ['required_if:action,review', 'accepted_if:action,review'],
            'relationship_override_reason' => ['nullable', 'string', 'max:2000'],
            'match_change_reason' => ['nullable', 'string', 'max:2000'],
            'photo_verification_reason' => ['nullable', Rule::enum(PhotoVerificationReason::class)],
            'photo_verification_notes' => ['nullable', 'string', 'max:2000', 'required_if:photo_verification_reason,other'],
            'unavailable_photos' => ['nullable', 'array'],
            'unavailable_photos.*' => [Rule::in(['child', 'stored_adult', 'current_adult'])],
            'cancellation_reason' => ['required_if:action,cancel', 'nullable', Rule::enum(CollectionCancellationReason::class)],
            'cancellation_notes' => ['nullable', 'string', 'max:2000', 'required_if:cancellation_reason,other'],
        ];
    }
}
