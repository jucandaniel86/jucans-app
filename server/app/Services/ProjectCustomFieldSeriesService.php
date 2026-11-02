<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ProjectCustomFieldSeriesService
{
    public const MAX_DAYS = 366;

    public function report(User $user, Project $project, int $fieldId, array $input): array
    {
        Gate::forUser($user)->authorize('view', $project);
        $field = $project->customFields()->findOrFail($fieldId);
        abort_unless($field->type === 'number', 422, 'Choose a numeric field.');
        if (! extension_loaded('bcmath')) {
            throw new HttpResponseException(response()->json([
                'code' => 'numeric_series_unavailable',
                'message' => 'Numeric charts are unavailable. Enable PHP BCMath in the HTTP runtime and reload PHP.',
                'errors' => ['runtime' => ['PHP BCMath is required for exact numeric aggregation.']],
            ], 503));
        }
        if (isset($input['note_type_id'])) {
            $project->noteTypes()->findOrFail($input['note_type_id']);
        }
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $from = CarbonImmutable::parse($input['from'] ?? $today->subDays(29)->toDateString(), 'UTC')->startOfDay();
        $to = CarbonImmutable::parse($input['to'] ?? $today->toDateString(), 'UTC')->startOfDay();
        if ($from > $to || $from->diffInDays($to) >= self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => ['Choose an inclusive range of 1–366 calendar days.']]);
        }
        $interval = $input['interval'] ?? 'day';
        $aggregation = $field->aggregation ?? 'average';
        abort_unless(in_array($aggregation, ['average', 'sum', 'min', 'max', 'latest'], true), 409, 'Invalid field aggregation.');
        $page = $interval === 'raw' ? (int) ($input['page'] ?? 1) : 1;
        $perPage = (int) ($input['per_page'] ?? 50);
        $notes = $project->notes()->visibleTo($user)
            ->select(['project_progress_notes.id', 'project_id', 'note_type_id', 'noted_at', 'configuration_snapshot'])
            ->whereNotNull('note_type_id')
            ->whereBetween('noted_at', [$from->toDateString(), $to->toDateString()])
            ->whereHas('fieldValues', fn ($q) => $q->where('field_id', $fieldId))
            ->with(['fieldValues' => fn ($q) => $q->where('field_id', $fieldId)])
            ->orderBy('noted_at')->orderBy('id');
        if (isset($input['note_type_id'])) {
            $notes->where('note_type_id', $input['note_type_id']);
        }
        $buckets = [];
        if ($interval !== 'raw') {
            for ($day = $this->start($from, $interval); $day <= $to; $day = $this->next($day, $interval)) {
                $end = $this->next($day, $interval)->subDay();
                $buckets[$day->toDateString()] = [
                    'id' => $day->toDateString(), 'date' => $day->toDateString(),
                    'bucket_from' => $day->toDateString(), 'bucket_to' => $end->toDateString(),
                    'included_from' => max($from->toDateString(), $day->toDateString()),
                    'included_to' => min($to->toDateString(), $end->toDateString()),
                    'partial' => $day < $from || $end > $to, 'count' => 0, 'value' => null,
                    'sum' => '0.000000',
                ];
            }
        }
        $total = 0;
        $raw = [];
        $conflicts = [];
        $conflictCount = 0;
        // Bounded memory; validate all eligible history, including raw pages not returned.
        foreach ($notes->lazy(200) as $note) {
            foreach ($note->fieldValues as $value) {
                if ($value->typedValue() === null) {
                    continue;
                }
                $snapshot = $note->configuration_snapshot;
                $entries = is_array($snapshot) && ($snapshot['schema_version'] ?? null) === 1 && is_array($snapshot['fields'] ?? null) && array_is_list($snapshot['fields'])
                    ? array_values(array_filter($snapshot['fields'], fn ($entry) => is_array($entry) && ($entry['field_id'] ?? null) === $fieldId)) : [];
                $definition = count($entries) === 1 ? ($entries[0]['definition'] ?? null) : null;
                $valid = is_array($definition) && ($definition['id'] ?? null) === $fieldId
                    && ($definition['project_id'] ?? null) === (int) $project->id
                    && ($definition['type'] ?? null) === 'number' && array_key_exists('unit', $definition)
                    && $definition['unit'] === $field->unit && $value->value_type === 'number'
                    && is_string($value->number_value) && preg_match('/^-?\d{1,14}(\.\d{1,6})?$/', $value->number_value);
                if (! $valid) {
                    $conflictCount++;
                    if (count($conflicts) < 20) {
                        $conflicts[] = ['note_id' => $note->id, 'field_id' => $fieldId, 'reason' => 'missing_malformed_or_incompatible_definition'];
                    }

                    continue;
                }
                $number = bcadd($value->number_value, '0', 6);
                $total++;
                if ($interval === 'raw') {
                    if ($total > ($page - 1) * $perPage && count($raw) < $perPage) {
                        $raw[] = ['id' => (string) $value->id, 'note_id' => $note->id, 'date' => $note->noted_at, 'value' => $number, 'count' => 1];
                    }

                    continue;
                }
                $key = $this->start(CarbonImmutable::parse($note->noted_at, 'UTC'), $interval)->toDateString();
                $bucket = &$buckets[$key];
                $bucket['sum'] = bcadd($bucket['sum'], $number, 6);
                $bucket['count']++;
                if ($bucket['value'] === null || $aggregation === 'latest'
                    || ($aggregation === 'min' && bccomp($number, $bucket['value'], 6) < 0)
                    || ($aggregation === 'max' && bccomp($number, $bucket['value'], 6) > 0)) {
                    $bucket['value'] = $number;
                }
                unset($bucket);
            }
        }
        if ($conflictCount) {
            throw new HttpResponseException(response()->json([
                'code' => 'incompatible_field_history', 'message' => 'Saved field history has conflicting or malformed types or units. The series cannot be combined.',
                'errors' => ['history' => ['Istoricul conține definiții lipsă, nevalide sau tipuri/unități incompatibile. Seria nu poate fi combinată.']],
                'diagnostics' => ['count' => $conflictCount, 'examples' => $conflicts, 'examples_limit' => 20],
            ], 409));
        }
        foreach ($buckets as &$bucket) {
            if ($bucket['count']) {
                if ($aggregation === 'sum') {
                    $bucket['value'] = $bucket['sum'];
                } elseif ($aggregation === 'average') {
                    $average = bcdiv($bucket['sum'], (string) $bucket['count'], 7);
                    $bucket['value'] = bcadd($average, bccomp($average, '0', 7) < 0 ? '-0.0000005' : '0.0000005', 6);
                }
            }
            unset($bucket['sum']);
        }
        unset($bucket);
        $lastPage = max(1, (int) ceil($total / $perPage));
        if ($interval === 'raw' && $page > $lastPage) {
            throw ValidationException::withMessages(['page' => ['Page is outside the available observations.']]);
        }

        return [
            'project_id' => $project->id,
            'field' => ['id' => $field->id, 'name' => $field->name, 'unit' => $field->unit, 'archived_at' => $field->archived_at],
            'aggregation' => $aggregation, 'interval' => $interval, 'timezone' => 'UTC',
            'date_convention' => 'noted_at calendar date; UTC determines default today; Monday-start weeks',
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'max_days' => self::MAX_DAYS],
            'note_type_id' => isset($input['note_type_id']) ? (int) $input['note_type_id'] : null,
            'observation_count' => $total,
            'pagination' => $interval === 'raw' ? ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => $lastPage] : null,
            'points' => $interval === 'raw' ? $raw : array_values($buckets),
        ];
    }

    private function start(CarbonImmutable $date, string $interval): CarbonImmutable
    {
        return match ($interval) {
            'week' => $date->startOfWeek(1), 'month' => $date->startOfMonth(), default => $date->startOfDay()
        };
    }

    private function next(CarbonImmutable $date, string $interval): CarbonImmutable
    {
        return match ($interval) {
            'week' => $date->addWeek(), 'month' => $date->addMonth(), default => $date->addDay()
        };
    }
}
