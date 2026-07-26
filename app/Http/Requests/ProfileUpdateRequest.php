<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            'phone' => ['nullable', 'string', 'max:32'],
            'booking_enabled' => ['sometimes', 'boolean'],
            'booking_slug' => [
                'nullable', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/',
                Rule::unique(User::class, 'booking_slug')->ignore($this->user()->id),
            ],
            'booking_duration_min' => ['sometimes', 'integer', 'in:15,30,45,60'],
        ];
    }
}
