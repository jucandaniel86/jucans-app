<?php

namespace App\Http\Requests\Recipe;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListRecipesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['integer', 'distinct', 'exists:food_tags,id'],
            'sort' => ['nullable', Rule::in(['newest', 'name'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'random' => ['nullable', 'boolean'],
            'exclude' => ['nullable', 'integer', 'exists:recipes,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $tags = $this->input('tags');

        if (is_string($tags)) {
            $tags = array_values(array_filter(explode(',', $tags), fn (string $tag) => $tag !== ''));
        }

        $this->merge([
            'search' => trim((string) $this->input('search', '')) ?: null,
            'tags' => $tags,
            'sort' => $this->input('sort', 'newest'),
            'random' => $this->boolean('random'),
        ]);
    }
}
