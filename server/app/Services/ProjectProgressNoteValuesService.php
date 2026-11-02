<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectProgressNote;
use App\Models\ProjectProgressNoteValue;
use Illuminate\Validation\ValidationException;

class ProjectProgressNoteValuesService
{
    // Called only inside ProjectProgressNoteService's shared parent-project lock/transaction.
    public function save(Project $project, ProjectProgressNote $note, array $input, bool $creating): bool
    {
        if (! array_is_list($input['values'] ?? [])) {
            $this->invalid('values', 'Provide a list of field/value entries.');
        }
        if (! $creating && $note->note_type_id !== null && array_key_exists('note_type_id', $input)) {
            abort_if(($input['note_type_id'] === null ? null : (int) $input['note_type_id']) !== $note->note_type_id,
                409, 'A typed note cannot change or remove its saved Note Type.');
        }
        // Null-to-typed assignment shares creation capture; same-type retries never recapture.
        $capturedContext = false;
        if ($note->note_type_id === null && ($input['note_type_id'] ?? null) !== null) {
            $type = $project->noteTypes()->with('fields.field.options')->find($input['note_type_id']);
            if (! $type) {
                $this->invalid('note_type_id', 'Choose a Note Type from this project.');
            }
            abort_if($type->archived_at !== null, 409, 'Choose an active Note Type before assigning configuration.');
            $snapshot = [
                'schema_version' => 1,
                'note_type' => $type->only(['id', 'project_id', 'name', 'description', 'template', 'is_default', 'archived_at']),
                'fields' => $type->fields->filter(fn ($period) => $period->field->archived_at === null)->map(fn ($period) => [
                    'field_id' => $period->field_id, 'association_id' => $period->id,
                    'required' => $period->required, 'sort_order' => $period->sort_order,
                    'definition' => $period->field->only(['id', 'project_id', 'key', 'name', 'description', 'type', 'unit', 'min', 'max', 'step', 'aggregation', 'archived_at']),
                    'options' => $period->field->options->map(fn ($option) => $option->only(['id', 'field_id', 'label', 'sort_order', 'archived_at']))->all(),
                ])->values()->all(),
            ];
            $note->forceFill(['note_type_id' => $type->id, 'configuration_snapshot' => $snapshot, 'configuration_captured_at' => now('UTC')])->save();
            $capturedContext = true;
        }
        if ($note->note_type_id === null) {
            if (! empty($input['values'])) {
                $this->invalid('values', 'Untyped notes cannot store custom values. Assign a Note Type explicitly.');
            }

            return false;
        }
        $captured = collect($note->configuration_snapshot['fields'])->keyBy('field_id');
        $current = $project->customFields()->with('options')->whereIn('id', $captured->keys())->get()->keyBy('id');
        $existing = $note->fieldValues()->get()->keyBy('field_id');
        $merged = $captured->map(fn ($config, $id) => $existing->get($id)?->typedValue())->all();
        $seen = [];
        foreach ($input['values'] ?? [] as $index => $entry) {
            $id = (int) $entry['field_id'];
            $key = "values.{$index}.value";
            if (isset($seen[$id])) {
                $this->invalid("values.{$index}.field_id", 'Provide each field ID only once.');
            }
            $seen[$id] = true;
            if (! $captured->has($id)) {
                $this->invalid("values.{$index}.field_id", 'This field is not in the note configuration.');
            }
            $config = $captured[$id];
            $value = $this->typed($entry['value'], $config, $key);
            $old = $merged[$id];
            if ($value !== null && $value !== $old) {
                $field = $current->get($id);
                abort_if(! $field || $field->archived_at !== null, 409, 'Archived fields cannot receive new or replacement values.');
                abort_if($field->type !== $config['definition']['type'] || $field->unit !== $config['definition']['unit'],
                    409, 'The current field type or unit is incompatible with this historical configuration.');
                if ($config['definition']['type'] === 'select') {
                    $option = $field->options->firstWhere('id', $value);
                    if (! $option || $option->archived_at !== null) {
                        $this->invalid($key, 'Choose a currently active option belonging to this captured field.');
                    }
                }
                $this->validateCaptured($value, $config, $key);
            }
            $merged[$id] = $value;
        }
        foreach ($captured as $id => $config) {
            if ($config['required'] && ($merged[$id] === null || (is_string($merged[$id]) && trim($merged[$id]) === ''))) {
                $this->invalid('values', "Field {$id} ({$config['definition']['name']}) is required by this note configuration.");
            }
        }
        $changed = $capturedContext;
        $valuedFields = [];
        foreach ($captured as $id => $config) {
            $value = $merged[$id];
            $row = $existing->get($id);
            if (! $row || $row->typedValue() !== $value) {
                $columns = ['number_value' => null, 'text_value' => null, 'boolean_value' => null, 'select_option_id' => null];
                $column = ['number' => 'number_value', 'text' => 'text_value', 'boolean' => 'boolean_value', 'select' => 'select_option_id'][$config['definition']['type']];
                $columns[$column] = $value;
                $row ??= new ProjectProgressNoteValue;
                $row->forceFill(['project_id' => $project->id, 'note_id' => $note->id, 'field_id' => $id, 'value_type' => $config['definition']['type'], ...$columns])->save();
                $changed = true;
            }
            if ($value !== null) {
                $valuedFields[] = $id;
            }
        }

        if ($valuedFields !== []) {
            $project->customFields()->whereIn('id', $valuedFields)->whereNull('first_value_at')->update(['first_value_at' => now('UTC')->format('Y-m-d H:i:s.u')]);
        }

        return $changed;
    }

    private function typed(mixed $value, array $config, string $key): string|bool|int|null
    {
        if ($value === null) {
            return null;
        }

        return match ($config['definition']['type']) {
            'number' => $this->number($value, $key),
            'text' => is_string($value) && mb_strlen($value) <= 100000 ? $value : $this->invalid($key, 'Provide text of at most 100000 characters.'),
            'boolean' => is_bool($value) ? $value : $this->invalid($key, 'Provide a JSON Boolean (true or false).'),
            'select' => is_int($value) && $value > 0 && collect($config['options'])->contains('id', $value) ? $value : $this->invalid($key, 'Provide one option ID belonging to the captured field.'),
        };
    }

    private function number(mixed $value, string $key): string
    {
        if (is_bool($value) || (! is_string($value) && ! is_int($value) && ! is_float($value))) {
            $this->invalid($key, 'Provide an exact decimal value.');
        }
        if (is_float($value)) {
            $decimal = sprintf('%.6F', $value);
            if (! is_finite($value) || (float) $decimal !== $value) {
                $this->invalid($key, 'Use at most six decimal places; send exact decimals as strings.');
            }
            $value = $decimal;
        }
        if (! preg_match('/^-?\d{1,12}(\.\d{1,6})?$/', (string) $value)) {
            $this->invalid($key, 'Use at most twelve integer digits and six decimal places.');
        }
        $scaled = $this->scaled((string) $value);
        $magnitude = abs($scaled);

        return ($scaled < 0 ? '-' : '').intdiv($magnitude, 1000000).'.'.str_pad((string) ($magnitude % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function validateCaptured(string|bool|int $value, array $config, string $key): void
    {
        if ($config['definition']['type'] !== 'number') {
            return;
        }
        $settings = $config['definition'];
        $scaled = $this->scaled($value);
        if (($settings['min'] !== null && $scaled < $this->scaled($settings['min'])) ||
            ($settings['max'] !== null && $scaled > $this->scaled($settings['max']))) {
            $this->invalid($key, 'The value is outside the captured numeric bounds.');
        }
        if ($settings['step'] !== null && ($scaled - $this->scaled($settings['min'] ?? '0')) % $this->scaled($settings['step']) !== 0) {
            $this->invalid($key, 'The value does not match the captured step (origin: minimum or zero).');
        }
    }

    private function scaled(string $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
        $magnitude = (int) $whole * 1000000 + (int) str_pad($fraction, 6, '0');

        return str_starts_with($value, '-') ? -$magnitude : $magnitude;
    }

    private function invalid(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
