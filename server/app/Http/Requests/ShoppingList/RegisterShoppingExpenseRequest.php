<?php

namespace App\Http\Requests\ShoppingList;

use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterShoppingExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $amount = $this->input('amount');
        if (is_int($amount)) {
            $this->merge(['amount' => (string) $amount]);
        } elseif (is_float($amount) && is_finite($amount)) {
            $decimal = sprintf('%.6F', $amount);
            if ((float) $decimal === $amount) $this->merge(['amount' => $decimal]);
        }
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
            'close_list' => ['required', 'boolean'],
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC))],
            'user_id' => ['prohibited'], 'created_by' => ['prohibited'],
            'note_type_id' => ['prohibited'], 'values' => ['prohibited'],
        ];
    }
}
