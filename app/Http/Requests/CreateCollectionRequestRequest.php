<?php

namespace App\Http\Requests;

use App\Models\CollectionEntry;
use Illuminate\Foundation\Http\FormRequest;

class CreateCollectionRequestRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $entry = $this->route('entry');
        if ($entry instanceof CollectionEntry && $entry->collection_request_id !== null) {
            return [];
        }

        return [
            'requester_name_text' => ['required', 'string', 'max:255'],
            'requested_child_text' => ['required', 'string', 'max:255'],
            'camera_available' => ['sometimes', 'boolean'],
            'photo' => ['required_if:camera_available,true', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'requester_name_text' => 'your name',
            'requested_child_text' => 'child you are collecting',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'photo.required_if' => 'Please take a photo before sending your request.',
        ];
    }
}
