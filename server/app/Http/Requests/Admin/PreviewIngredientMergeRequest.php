<?php

namespace App\Http\Requests\Admin;

use App\Models\Ingredient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewIngredientMergeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var Ingredient $source */
        $source = $this->route('source');

        return [
            'target_ingredient_id' => [
                'required',
                'integer',
                Rule::exists('ingredients', 'id'),
                Rule::notIn([$source->id]),
            ],
        ];
    }
}
