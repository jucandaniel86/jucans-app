<?php

  namespace App\Http\Requests\Diet;

  use App\Enums\DietIngredientStatus;
  use Illuminate\Foundation\Http\FormRequest;
  use Illuminate\Validation\Rule;
  use Illuminate\Validation\Rules\Enum;

  class AddDietIngredientRequest extends FormRequest
  {
    public function authorize(): bool
    {
      return true;
    }

    public function rules(): array
    {
      return [
        'ingredient_id' => [
          'required',
          'integer',
          Rule::exists('ingredients', 'id'),
        ],
        'status' => [
          'sometimes',
          new Enum(DietIngredientStatus::class),
        ],
        'notes' => ['nullable', 'string'],
      ];
    }
  }
