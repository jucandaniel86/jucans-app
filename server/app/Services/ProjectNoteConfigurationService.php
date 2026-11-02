<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectCustomField;
use App\Models\ProjectCustomFieldOption;
use App\Models\ProjectNoteType;
use App\Models\ProjectNoteTypeField;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProjectNoteConfigurationService
{
    // Every configuration writer locks the same parent, including initialization.
    public function write(User $user, Project $project, Closure $operation): mixed
    {
        return DB::transaction(function () use ($user, $project, $operation) {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('update', $project);

            return $operation($project);
        }, 3);
    }

    public function saveField(Project $project, array $input, ?ProjectCustomField $field = null): ProjectCustomField
    {
        abort_if($field !== null && (int) $field->project_id !== (int) $project->id, 404);
        $field ??= new ProjectCustomField;
        $candidate = array_replace($field->only($field->getFillable()), $input);
        // PHP stringifies small JSON floats in exponent notation. Normalize exact
        // six-place decimals before validating, without silently rounding input.
        foreach (['min', 'max', 'step'] as $key) {
            $value = $candidate[$key] ?? null;
            if (is_float($value) && is_finite($value)) {
                $decimal = sprintf('%.6F', $value);
                if ((float) $decimal === $value) {
                    $candidate[$key] = $decimal;
                }
            }
        }
        $numeric = ['nullable', 'numeric', 'regex:/^-?\d{1,12}(\.\d{1,6})?$/'];
        $data = Validator::make($candidate, [
            'name' => ['required', 'string', 'max:255'],
            'key' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('project_custom_fields')->where('project_id', $project->id)->ignore($field->id)],
            'description' => ['nullable', 'string', 'max:10000'],
            'type' => ['required', Rule::in(['number', 'select', 'boolean', 'text'])],
            'unit' => ['nullable', 'string', 'max:100'],
            'min' => $numeric, 'max' => $numeric, 'step' => [...$numeric, 'gt:0'],
            'aggregation' => ['nullable', Rule::in(['average', 'sum', 'min', 'max', 'latest'])],
        ])->validate();
        if ($field->exists && $field->key !== $data['key']) {
            $this->invalid('key', 'The field key is stable and cannot change.');
        }
        if ($data['type'] !== 'number') {
            foreach (['unit', 'min', 'max', 'step', 'aggregation'] as $key) {
                if (($data[$key] ?? null) !== null) {
                    $this->invalid($key, 'Numeric configuration requires a number field.');
                }
            }
        } else {
            $data['aggregation'] ??= 'average';
            if (isset($data['min'], $data['max']) && $this->scaled($data['min']) > $this->scaled($data['max'])) {
                $this->invalid('max', 'Maximum must be greater than or equal to minimum.');
            }
        }
        if ($field->exists && $data['type'] !== 'select' && $field->options()->exists()) {
            $this->invalid('type', 'A field with option history must remain a select field.');
        }
        if ($field->first_value_at !== null) {
            $this->preserveValueDomain($field, $data);
        }
        $field->fill($data);
        if (! $field->exists) {
            $field->project_id = $project->id;
        }
        $field->save();

        return $field->refresh();
    }

    // Without note values in Phase 1, only changes preserving the entire old domain
    // are accepted. Phase 2 may relax this by checking actual persisted values.
    private function preserveValueDomain(ProjectCustomField $field, array $data): void
    {
        foreach (['type', 'unit'] as $key) {
            if (($data[$key] ?? null) !== $field->$key) {
                $this->invalid($key, 'Cannot change after the first value.');
            }
        }
        foreach (['min', 'max'] as $key) {
            $old = $field->$key;
            $new = $data[$key] ?? null;
            if ($new !== null && ($old === null || ($key === 'min' ? $this->scaled($new) > $this->scaled($old) : $this->scaled($new) < $this->scaled($old)))) {
                $this->invalid($key, 'Cannot narrow the existing value domain.');
            }
        }
        $oldStep = $field->step;
        $newStep = $data['step'] ?? null;
        if ($newStep !== null) {
            $step = $this->scaled($newStep);
            if ($oldStep === null || $this->scaled($oldStep) % $step !== 0 ||
                ($this->scaled($field->min ?? 0) - $this->scaled($data['min'] ?? 0)) % $step !== 0) {
                $this->invalid('step', 'The step must preserve all previously valid values (origin: min or zero).');
            }
        }
    }

    private function scaled(string|int|float $value): int
    {
        $parts = explode('.', (string) $value, 2);
        $negative = str_starts_with($parts[0], '-');
        $magnitude = (int) ltrim($parts[0], '-') * 1000000 + (int) str_pad($parts[1] ?? '', 6, '0');

        return $negative ? -$magnitude : $magnitude;
    }

    public function archiveField(ProjectCustomField $field, bool $archive): ProjectCustomField
    {
        // Preserve active associations and their history; archived definitions remain readable.
        $field->forceFill(['archived_at' => $archive ? ($field->archived_at ?? now('UTC')) : null])->save();

        return $field;
    }

    public function saveOption(ProjectCustomField $field, array $input, ?ProjectCustomFieldOption $option = null): ProjectCustomFieldOption
    {
        $this->selectField($field);
        abort_if($field->archived_at !== null, 409, 'Restore the field before editing options.');
        abort_if($option !== null && (int) $option->field_id !== (int) $field->id, 404);
        $option ??= new ProjectCustomFieldOption;
        $option->fill($input);
        if (! $option->exists) {
            $option->field_id = $field->id;
        }
        $option->save();

        return $option->refresh();
    }

    public function archiveOption(ProjectCustomField $field, ProjectCustomFieldOption $option, bool $archive): ProjectCustomFieldOption
    {
        $this->selectField($field);
        abort_unless((int) $option->field_id === (int) $field->id, 404);
        abort_if($field->archived_at !== null, 409, 'Restore the field before editing options.');
        $option->forceFill(['archived_at' => $archive ? ($option->archived_at ?? now('UTC')) : null])->save();

        return $option;
    }

    public function selectField(ProjectCustomField $field): void
    {
        abort_unless($field->type === 'select', 422, 'Options require a select field.');
    }

    public function reorderOptions(ProjectCustomField $field, array $ids): void
    {
        $this->selectField($field);
        abort_if($field->archived_at !== null, 409, 'Restore the field before editing options.');
        $this->exactIds($field->options()->pluck('id')->all(), $ids);
        foreach ($ids as $position => $id) {
            $field->options()->whereKey($id)->update(['sort_order' => $position]);
        }
    }

    public function saveType(Project $project, array $input, ?ProjectNoteType $type = null): ProjectNoteType
    {
        abort_if($type !== null && (int) $type->project_id !== (int) $project->id, 404);
        $type ??= new ProjectNoteType;
        $data = Validator::make(array_replace($type->only($type->getFillable()), $input), [
            'name' => ['required', 'string', 'max:255', Rule::unique('project_note_types')->where('project_id', $project->id)->ignore($type->id)],
            'description' => ['nullable', 'string', 'max:10000'],
            'template' => ['nullable', 'string', 'max:100000'],
        ])->validate();
        $type->fill($data);
        if (! $type->exists) {
            $type->project_id = $project->id;
            if (! $project->noteTypes()->exists()) {
                $type->is_default = '1';
            }
        }
        $type->save();

        return $type->refresh();
    }

    public function setDefault(Project $project, ProjectNoteType $type): ProjectNoteType
    {
        abort_unless((int) $type->project_id === (int) $project->id, 404);
        abort_if($type->archived_at !== null, 409, 'An archived Note Type cannot be default.');
        $project->noteTypes()->whereNotNull('is_default')->update(['is_default' => null]);
        $type->forceFill(['is_default' => '1'])->save();

        return $type;
    }

    public function archiveType(Project $project, ProjectNoteType $type, bool $archive): ProjectNoteType
    {
        abort_unless((int) $type->project_id === (int) $project->id, 404);
        if ($archive && $type->archived_at === null) {
            abort_if($type->is_default, 409, 'Select another active default before archiving.');
            abort_if($project->noteTypes()->whereNull('archived_at')->count() <= 1, 409, 'Keep at least one active Note Type.');
        }
        $type->forceFill(['archived_at' => $archive ? ($type->archived_at ?? now('UTC')) : null])->save();

        return $type;
    }

    // Explicit service entry point only: never invoked by migrations, reads or project creation.
    public function initialize(User $user, Project $project): ProjectNoteType
    {
        return $this->write($user, $project, function (Project $project) {
            $default = $project->noteTypes()->whereNotNull('is_default')->first();
            if ($default) {
                return $default;
            }
            abort_if($project->noteTypes()->exists(), 409, 'Configuration exists without a default; repair explicitly.');

            return $this->saveType($project, ['name' => 'General', 'template' => $project->note_template]);
        });
    }

    public function attach(Project $project, ProjectNoteType $type, ProjectCustomField $field, array $input): ProjectNoteTypeField
    {
        $this->activeType($type);
        abort_if($field->archived_at !== null, 409, 'Archived fields cannot be attached.');
        abort_unless((int) $field->project_id === (int) $project->id && (int) $type->project_id === (int) $project->id, 404);
        abort_if($type->fields()->where('field_id', $field->id)->exists(), 409, 'Field is already attached.');

        return $type->fieldHistory()->create([
            'project_id' => $project->id, 'field_id' => $field->id,
            'sort_order' => $input['sort_order'] ?? (($type->fields()->max('sort_order') ?? -1) + 1),
            'required' => $input['required'] ?? false, 'activated_at' => now('UTC'), 'active_slot' => '1',
        ]);
    }

    public function changeAssociation(ProjectNoteType $type, ProjectCustomField $field, array $input, bool $detach = false): ?ProjectNoteTypeField
    {
        $this->activeType($type);
        abort_unless((int) $field->project_id === (int) $type->project_id, 404);
        $association = $type->fields()->where('field_id', $field->id)->firstOrFail();
        $next = array_replace($association->only(['sort_order', 'required']), $input);
        if (! $detach && $association->sort_order === (int) $next['sort_order'] && $association->required === (bool) $next['required']) {
            return $association;
        }
        $at = now('UTC');
        $association->forceFill(['deactivated_at' => $at, 'active_slot' => null])->save();
        if ($detach) {
            return null;
        }

        // Replacement periods also preserve previous required/order settings.
        return $type->fieldHistory()->create([
            'project_id' => $type->project_id, 'field_id' => $field->id,
            ...$next, 'activated_at' => $at, 'active_slot' => '1',
        ]);
    }

    public function reorderFields(ProjectNoteType $type, array $ids): void
    {
        $this->activeType($type);
        $this->exactIds($type->fields()->pluck('field_id')->all(), $ids);
        foreach ($ids as $position => $id) {
            $this->changeAssociation($type, ProjectCustomField::findOrFail($id), ['sort_order' => $position]);
        }
    }

    private function activeType(ProjectNoteType $type): void
    {
        abort_if($type->archived_at !== null, 409, 'Restore the Note Type before changing its fields.');
    }

    private function exactIds(array $expected, array $actual): void
    {
        $actual = array_map('intval', $actual);
        $sorted = $actual;
        sort($expected);
        sort($sorted);
        if ($expected !== $sorted) {
            $this->invalid('ids', 'Provide every current ID exactly once, with no foreign IDs.');
        }
    }

    private function invalid(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
