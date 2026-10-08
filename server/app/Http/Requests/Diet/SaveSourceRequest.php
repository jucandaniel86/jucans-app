<?php

  namespace App\Http\Requests\Diet;

  use App\Enums\DietSourceType;
  use Illuminate\Foundation\Http\FormRequest;
  use Illuminate\Validation\Rules\Enum;

  class SaveSourceRequest extends FormRequest
  {
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
      return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
      return [
        'title' => ['required', 'string', 'max:255'],
        'url' => ['nullable', 'string'],
        'type' => ['required', new Enum(DietSourceType::class)],
        'is_official' => ['required', 'boolean'],
        'notes' => ['nullable', 'string'],
      ];
    }
  }
