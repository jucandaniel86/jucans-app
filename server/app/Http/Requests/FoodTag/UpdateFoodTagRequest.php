<?php

namespace App\Http\Requests\FoodTag;

use App\Models\FoodTag;
use App\Support\NameNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateFoodTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'emoji' => ['sometimes', 'nullable', 'string', 'max:32'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $values = [];

        if ($this->has('name')) {
            $values['name'] = trim((string) $this->input('name'));
        }

        if ($this->has('emoji')) {
            $emoji = trim((string) $this->input('emoji'));
            $values['emoji'] = $emoji === '' ? null : $emoji;
        }

        $this->merge($values);
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->has('name') || $validator->errors()->has('name')) {
                    return;
                }

                $tag = $this->route('foodTag');
                $duplicateExists = FoodTag::query()
                    ->where('normalized_name', NameNormalizer::normalize($this->string('name')->toString()))
                    ->when($tag instanceof FoodTag, fn ($query) => $query->whereKeyNot($tag->getKey()))
                    ->exists();

                if ($duplicateExists) {
                    $validator->errors()->add('name', 'A tag with an equivalent name already exists.');
                }
            },
        ];
    }
}
