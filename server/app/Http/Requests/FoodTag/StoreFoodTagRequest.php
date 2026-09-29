<?php

namespace App\Http\Requests\FoodTag;

use Illuminate\Foundation\Http\FormRequest;

class StoreFoodTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'emoji' => ['nullable', 'string', 'max:32'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $emoji = trim((string) $this->input('emoji', ''));

        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'emoji' => $emoji === '' ? null : $emoji,
        ]);
    }
}
