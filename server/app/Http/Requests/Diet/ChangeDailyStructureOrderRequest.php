<?php

	namespace App\Http\Requests\Diet;

	use Illuminate\Foundation\Http\FormRequest;
	use Illuminate\Validation\Rule;

	class ChangeDailyStructureOrderRequest extends FormRequest
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
				'direction' => ['required', Rule::in(['up', 'down'])],
			];
		}
	}