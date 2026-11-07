<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectCustomField;
use App\Models\ProjectProgressNoteValue;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ProjectChartReportService
{
    public function report(User $user, Project $project, array $input): array
    {
        Gate::forUser($user)->authorize('view', $project);

        return DB::transaction(fn () => $this->buildReport($user, $project, $input));
    }

    private function buildReport(User $user, Project $project, array $input): array
    {
        $from = CarbonImmutable::parse($input['start_date'], 'UTC')->startOfDay();
        $to = CarbonImmutable::parse($input['end_date'], 'UTC')->startOfDay();
        if ($from > $to || $from->diffInDays($to) >= 366) {
            throw ValidationException::withMessages(['end_date' => 'Selectează un interval inclusiv de maximum 366 de zile.']);
        }
        $charts = $project->charts()->where('active', true)->with(['fields.options', 'pieValueField'])->get();
        $legacyFields = $charts->flatMap->fields->unique('id')->keyBy('id');
        $sumCharts = $charts->filter(fn ($chart) => $chart->type === 'pie' && $chart->pie_aggregation === 'sum'
            && $chart->fields->count() === 1 && $chart->fields->first()->type === 'select'
            && $chart->pieValueField?->type === 'number' && $chart->pieValueField->project_id === $project->id);
        $fields = $legacyFields->values()->concat($sumCharts->pluck('pieValueField'))->unique('id')->keyBy('id');
        $pieSums = [];
        $intervals = [];
        $previousFields = [];
        foreach ($charts as $chart) {
            $selected = $input['group_by'][$chart->id] ?? 'auto';
            $interval = $chart->type === 'line' ? 'day' : ($selected === 'auto'
                ? ($from->diffInDays($to) + 1 <= 31 ? 'day' : ($from->diffInDays($to) + 1 <= 90 ? 'week' : 'month')) : $selected);
            $intervals[$chart->id] = $interval;
            if ($chart->type === 'pie' && $chart->fields->first()?->type === 'number') {
                $previousFields[] = $chart->fields->first()->id;
            }
        }
        $groups = [];
        $previous = [];
        $boolean = [];
        $selections = [];
        $counts = [];
        $errors = [];
        $numericAvailable = extension_loaded('bcmath');
        if ($fields->isNotEmpty()) {
            $notes = $project->notes()->visibleTo($user)
                ->select(['project_progress_notes.id', 'project_id', 'noted_at', 'created_at', 'configuration_snapshot'])
                ->where('noted_at', '<=', $to->toDateString())
                ->where(fn ($q) => $q->where('noted_at', '>=', $from->toDateString())
                    ->orWhereHas('fieldValues', fn ($values) => $values->whereIn('field_id', $previousFields)))
                ->whereHas('fieldValues', fn ($q) => $q->whereIn('field_id', $fields->keys()))
                ->with(['fieldValues' => fn ($q) => $q->whereIn('field_id', $fields->keys())])
                ->orderBy('noted_at')->orderBy('created_at')->orderBy('id');
            // One visibility-scoped stream for all charts; no task/note joins or per-chart queries.
            foreach ($notes->lazy(200) as $note) {
                $inside = $note->noted_at >= $from->toDateString();
                foreach ($note->fieldValues as $value) {
                    if (! $legacyFields->has($value->field_id) || $value->typedValue() === null) {
                        continue;
                    }
                    $field = $fields[$value->field_id];
                    if (! $inside && ! in_array($field->id, $previousFields, true)) {
                        continue;
                    }
                    $snapshot = $note->configuration_snapshot;
                    $entries = is_array($snapshot) && ($snapshot['schema_version'] ?? null) === 1 && is_array($snapshot['fields'] ?? null) && array_is_list($snapshot['fields'])
                        ? array_values(array_filter($snapshot['fields'], fn ($entry) => is_array($entry) && ($entry['field_id'] ?? null) === $field->id)) : [];
                    $definition = count($entries) === 1 ? ($entries[0]['definition'] ?? null) : null;
                    $valid = is_array($definition) && ($definition['id'] ?? null) === $field->id
                        && ($definition['project_id'] ?? null) === (int) $project->id
                        && ($definition['type'] ?? null) === $field->type && $value->value_type === $field->type;
                    if ($field->type === 'number') {
                        $valid = $valid && array_key_exists('unit', $definition ?? []) && $definition['unit'] === $field->unit
                            && is_string($value->number_value) && preg_match('/^-?\d{1,14}(\.\d{1,6})?$/', $value->number_value);
                    } elseif ($field->type === 'boolean') {
                        $valid = $valid && is_bool($value->boolean_value);
                    } elseif ($field->type === 'select') {
                        $options = $entries[0]['options'] ?? null;
                        $valid = $valid && is_int($value->select_option_id)
                            && is_array($options)
                            && collect($options)->contains(fn ($option) => is_array($option)
                                && ($option['id'] ?? null) === $value->select_option_id
                                && ($option['field_id'] ?? null) === $field->id)
                            && $field->options->contains('id', $value->select_option_id);
                        // Missing/invalid selections do not contribute to the distribution.
                        if (! $valid) {
                            continue;
                        }
                    } else {
                        $valid = false;
                    }
                    if (! $valid) {
                        $errors[$field->id][$inside ? 'current' : 'previous'] = 'Istoricul câmpului conține tipuri sau unități incompatibile.';

                        continue;
                    }
                    if ($field->type === 'number' && ! $numericAvailable) {
                        continue;
                    }
                    $counts[$field->id][$inside ? 'current' : 'previous'] = ($counts[$field->id][$inside ? 'current' : 'previous'] ?? 0) + 1;
                    if (! $inside) {
                        $previous[$field->id] = bcadd($previous[$field->id] ?? '0', $value->number_value, 6);

                        continue;
                    }
                    if ($field->type === 'boolean') {
                        $key = $value->boolean_value ? 'yes' : 'no';
                        $boolean[$field->id][$key] = ($boolean[$field->id][$key] ?? 0) + 1;

                        continue;
                    }
                    if ($field->type === 'select') {
                        $optionId = $value->select_option_id;
                        $selections[$field->id][$optionId] = ($selections[$field->id][$optionId] ?? 0) + 1;

                        continue;
                    }
                    foreach (array_unique(['day', ...array_values($intervals)]) as $interval) {
                        $key = $this->start(CarbonImmutable::parse($note->noted_at, 'UTC'), $interval)->toDateString();
                        $bucket = &$groups[$interval][$field->id][$key];
                        $bucket ??= ['count' => 0, 'sum' => '0', 'min' => $value->number_value, 'max' => $value->number_value, 'latest' => $value->number_value];
                        $bucket['count']++;
                        $bucket['sum'] = bcadd($bucket['sum'], $value->number_value, 6);
                        if (bccomp($value->number_value, $bucket['min'], 6) < 0) {
                            $bucket['min'] = $value->number_value;
                        }
                        if (bccomp($value->number_value, $bucket['max'], 6) > 0) {
                            $bucket['max'] = $value->number_value;
                        }
                        // Observation DATE, then note creation timestamp, then note ID.
                        $bucket['latest'] = $value->number_value;
                        unset($bucket);
                    }
                }
                // Pair values within this note only; no joins to tasks, reviews or occurrences.
                if ($inside && $numericAvailable && $sumCharts->isNotEmpty()) {
                    $values = $note->fieldValues->keyBy('field_id');
                    foreach ($sumCharts as $chart) {
                        $group = $chart->fields->first();
                        $amount = $chart->pieValueField;
                        $category = $values->get($group->id);
                        $number = $values->get($amount->id);
                        if (! $this->validPieValue($group, $category, $note->configuration_snapshot, $project->id)
                            || ! $this->validPieValue($amount, $number, $note->configuration_snapshot, $project->id)) {
                            continue;
                        }
                        $bucket = &$pieSums[$chart->id][$category->select_option_id];
                        $bucket ??= ['sum' => '0.000000', 'count' => 0];
                        $bucket['sum'] = bcadd($bucket['sum'], $number->number_value, 6);
                        $bucket['count']++;
                        unset($bucket);
                    }
                }
            }
        }
        $result = [];
        foreach ($charts as $chart) {
            $row = ['id' => $chart->id, 'title' => $chart->title, 'type' => $chart->type,
                'priority' => $chart->priority, 'y_min' => $chart->y_min, 'y_max' => $chart->y_max,
                'error' => null, 'series' => [], 'segments' => [], 'observation_count' => 0];
            if ($chart->fields->isEmpty()) {
                $row['error'] = 'Graficul nu mai are câmpuri disponibile.';
            }
            if ($chart->type === 'pie' && $chart->fields->count() !== 1) {
                $row['error'] = 'Pie necesită un singur câmp.';
            }
            foreach ($chart->fields as $field) {
                if (isset($errors[$field->id]['current'])) {
                    $row['error'] = $errors[$field->id]['current'];
                }
                if ($chart->type === 'pie' && $field->type === 'number' && isset($errors[$field->id]['previous'])) {
                    $row['error'] = $errors[$field->id]['previous'];
                }
                if ($field->type === 'number' && ! $numericAvailable) {
                    $row['error'] = 'Graficele numerice necesită extensia PHP BCMath pe server.';
                }
                if ($chart->type !== 'pie' && ($field->type !== 'number' || ! in_array($field->aggregation, ['sum', 'average', 'min', 'max', 'latest'], true))) {
                    $row['error'] = 'Câmpul numeric nu mai are o agregare compatibilă.';
                }
                if ($chart->type === 'pie' && ! (in_array($field->type, ['boolean', 'select'], true) || ($field->type === 'number' && $field->aggregation === 'sum'))) {
                    $row['error'] = 'Pie necesită Boolean, Select sau Number cu SUM.';
                }
            }
            if ($chart->type === 'pie' && $chart->pie_aggregation === 'sum') {
                if (! $sumCharts->contains('id', $chart->id)) {
                    $row['error'] = 'Pie SUM necesită un câmp Select și un câmp Number din același proiect.';
                } elseif (! $numericAvailable) {
                    $row['error'] = 'Graficele numerice necesită extensia PHP BCMath pe server.';
                }
            }
            if ($row['error'] !== null) {
                $result[] = $row;

                continue;
            }
            if ($chart->type === 'pie') {
                $field = $chart->fields->first();
                $row['unit'] = $field->unit;
                if ($field->type === 'boolean') {
                    $row['segments'] = [['label' => 'Da', 'value' => (string) ($boolean[$field->id]['yes'] ?? 0)], ['label' => 'Nu', 'value' => (string) ($boolean[$field->id]['no'] ?? 0)]];
                    $row['observation_count'] = $counts[$field->id]['current'] ?? 0;
                } elseif ($field->type === 'select' && $chart->pie_aggregation === 'sum') {
                    $buckets = $pieSums[$chart->id] ?? [];
                    $total = '0.000000';
                    $negative = false;
                    foreach ($buckets as $bucket) {
                        $total = bcadd($total, $bucket['sum'], 6);
                        $negative = $negative || bccomp($bucket['sum'], '0', 6) < 0;
                        $row['observation_count'] += $bucket['count'];
                    }
                    $row['pie_aggregation'] = 'sum';
                    $row['unit'] = $chart->pieValueField->unit;
                    $row['total'] = $total;
                    foreach ($field->options as $option) {
                        if (! isset($buckets[$option->id])) {
                            continue;
                        }
                        $value = $buckets[$option->id]['sum'];
                        $segment = ['option_id' => $option->id, 'label' => $option->label, 'value' => $value];
                        if (! $negative && bccomp($total, '0', 6) > 0) {
                            $segment['percentage'] = (float) bcadd(bcdiv(bcmul($value, '100', 6), $total, 6), '0.005', 2);
                        }
                        $row['segments'][] = $segment;
                    }
                    if ($negative || bccomp($total, '0', 6) <= 0) {
                        $row['pie_message'] = $negative
                            ? 'Există sume negative pe categorii. Valorile reale sunt afișate mai jos, fără reprezentare în cerc.'
                            : 'Totalul SUM este zero. Valorile sunt afișate mai jos; procentele și cercul nu pot fi calculate.';
                    }
                } elseif ($field->type === 'select') {
                    $row['pie_aggregation'] = 'count';
                    $total = $counts[$field->id]['current'] ?? 0;
                    $row['total'] = (string) $total;
                    $row['unit'] = null;
                    $row['observation_count'] = $total;
                    foreach ($field->options as $option) {
                        $count = $selections[$field->id][$option->id] ?? 0;
                        if ($count > 0) {
                            $row['segments'][] = ['option_id' => $option->id, 'label' => $option->label,
                                'value' => (string) $count, 'percentage' => round($count / $total * 100, 2)];
                        }
                    }
                } else {
                    if ($chart->total === null || ! is_numeric($chart->total) || bccomp((string) $chart->total, '0', 6) <= 0) {
                        $row['error'] = 'Totalul graficului numeric trebuie să fie pozitiv.';
                        $result[] = $row;

                        continue;
                    }
                    $before = $previous[$field->id] ?? '0.000000';
                    $current = '0.000000';
                    foreach ($groups['day'][$field->id] ?? [] as $bucket) {
                        $current = bcadd($current, $bucket['sum'], 6);
                    }
                    $remaining = bcsub(bcsub((string) $chart->total, $before, 6), $current, 6);
                    $row['total'] = $chart->total;
                    $row['excess'] = bccomp($remaining, '0', 6) < 0 ? bcsub('0', $remaining, 6) : '0.000000';
                    $row['segments'] = [['label' => 'Anterior intervalului', 'value' => $before], ['label' => 'În interval', 'value' => $current], ['label' => 'Rămas', 'value' => bccomp($remaining, '0', 6) > 0 ? $remaining : '0.000000']];
                    $row['observation_count'] = ($counts[$field->id]['current'] ?? 0) + ($counts[$field->id]['previous'] ?? 0);
                }
            } else {
                $interval = $intervals[$chart->id];
                $row['group_by'] = $interval;
                foreach ($chart->fields as $field) {
                    $points = [];
                    for ($date = $this->start($from, $interval); $date <= $to; $date = $this->next($date, $interval)) {
                        $key = $date->toDateString();
                        $bucket = $groups[$interval][$field->id][$key] ?? null;
                        $points[] = ['date' => $key, 'from' => max($from->toDateString(), $key),
                            'to' => min($to->toDateString(), $this->next($date, $interval)->subDay()->toDateString()),
                            'value' => $bucket ? $this->aggregate($bucket, $field->aggregation) : null,
                            'count' => $bucket['count'] ?? 0];
                    }
                    $row['series'][] = ['field_id' => $field->id, 'name' => $field->name, 'unit' => $field->unit,
                        'archived' => $field->archived_at !== null, 'aggregation' => $field->aggregation, 'points' => $points];
                    $row['observation_count'] += $counts[$field->id]['current'] ?? 0;
                }
            }
            $result[] = $row;
        }

        return ['range' => ['start_date' => $from->toDateString(), 'end_date' => $to->toDateString(), 'max_days' => 366], 'charts' => $result];
    }

    // New Pie SUM skips invalid pairs without changing legacy chart error semantics.
    private function validPieValue(ProjectCustomField $field, ?ProjectProgressNoteValue $value, mixed $snapshot, int $projectId): bool
    {
        if ($value === null || (int) $value->project_id !== $projectId || (int) $field->project_id !== $projectId
            || $value->typedValue() === null || $value->value_type !== $field->type
            || ! is_array($snapshot) || ($snapshot['schema_version'] ?? null) !== 1
            || ! is_array($snapshot['fields'] ?? null) || ! array_is_list($snapshot['fields'])) {
            return false;
        }
        $entries = array_values(array_filter($snapshot['fields'], fn ($entry) => is_array($entry) && ($entry['field_id'] ?? null) === $field->id));
        $definition = count($entries) === 1 ? ($entries[0]['definition'] ?? null) : null;
        if (! is_array($definition) || ($definition['id'] ?? null) !== $field->id
            || ($definition['project_id'] ?? null) !== $projectId || ($definition['type'] ?? null) !== $field->type) {
            return false;
        }
        if ($field->type === 'number') {
            return array_key_exists('unit', $definition) && $definition['unit'] === $field->unit
                && is_string($value->number_value) && preg_match('/^-?\d{1,14}(\.\d{1,6})?$/', $value->number_value) === 1;
        }
        return $field->type === 'select' && is_int($value->select_option_id)
            && is_array($entries[0]['options'] ?? null)
            && collect($entries[0]['options'])->contains(fn ($option) => is_array($option)
                && ($option['id'] ?? null) === $value->select_option_id && ($option['field_id'] ?? null) === $field->id)
            && $field->options->contains('id', $value->select_option_id);
    }

    private function aggregate(array $bucket, string $aggregation): string
    {
        if ($aggregation === 'average') {
            $value = bcdiv($bucket['sum'], (string) $bucket['count'], 7);

            return bcadd($value, bccomp($value, '0', 7) < 0 ? '-0.0000005' : '0.0000005', 6);
        }

        return bcadd($bucket[$aggregation], '0', 6);
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
