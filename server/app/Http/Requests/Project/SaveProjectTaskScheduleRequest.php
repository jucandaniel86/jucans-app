<?php

namespace App\Http\Requests\Project;

use App\Enums\ProjectRecurrenceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class SaveProjectTaskScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('configureSchedule', $this->route('task'));
    }

    public function rules(): array
    {
        return [
            'recurrence_type' => ['required', new Enum(ProjectRecurrenceType::class)],
            'interval_days' => ['nullable', 'required_if:recurrence_type,every_n_days', 'prohibited_unless:recurrence_type,every_n_days', 'integer', 'min:1', 'max:4294967295'],
            'weekdays' => ['nullable', 'required_if:recurrence_type,weekdays', 'prohibited_unless:recurrence_type,weekdays', 'array', 'min:1', 'max:7'],
            'weekdays.*' => ['integer', 'distinct', 'min:1', 'max:7'],
            'month_day' => ['nullable', 'required_if:recurrence_type,monthly', 'prohibited_unless:recurrence_type,monthly', 'integer', 'min:1', 'max:31'],
            'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'scheduled_time' => ['sometimes', 'nullable', 'date_format:H:i'],
        ];
    }
}
