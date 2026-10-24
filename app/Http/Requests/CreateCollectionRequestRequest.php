<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateCollectionRequestRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'requester_name_text' => ['required', 'string', 'max:255'],
            'requested_child_text' => ['required', 'string', 'max:255'],
            'requester_latitude' => ['nullable', 'required_with:requester_longitude', 'numeric', 'between:-90,90'],
            'requester_longitude' => ['nullable', 'required_with:requester_latitude', 'numeric', 'between:-180,180'],
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
            'requester_latitude' => 'latitude',
            'requester_longitude' => 'longitude',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'photo.required_if' => 'Please take a photo before sending your request.',
        ];
    }
}
