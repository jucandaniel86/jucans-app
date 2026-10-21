<?php

namespace App\Http\Requests\Diet;

use App\Enums\DietStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateDietRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'status' => ['sometimes', new Enum(DietStatus::class)],
            'description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'thumbnail' => [
                'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192',
                'dimensions:max_width=6000,max_height=6000',
            ],
            'remove_thumbnail' => ['sometimes', 'boolean'],
        ];
    }
}
