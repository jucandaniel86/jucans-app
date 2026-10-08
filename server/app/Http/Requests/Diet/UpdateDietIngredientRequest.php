<?php

  namespace App\Http\Requests\Diet;

  use App\Enums\DietIngredientStatus;
  use Illuminate\Foundation\Http\FormRequest;
  use Illuminate\Validation\Rules\Enum;

  class UpdateDietIngredientRequest extends FormRequest
  {
    public function authorize(): bool
    {
      return true;
    }

    public function rules(): array
    {
      return [
        'status' => [
          'required',
          new Enum(DietIngredientStatus::class),
        ],
        'notes' => ['nullable', 'string'],
      ];
    }
  }
