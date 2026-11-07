<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectChart;
use Illuminate\Validation\ValidationException;

class ProjectChartConfigurationService
{
    // Called inside the existing parent-project configuration transaction/owner gate.
    public function save(Project $project, array $input, ?ProjectChart $chart = null): ProjectChart
    {
        $oldValueField = $chart?->pie_value_field_id;
        $old = $chart?->fields()->pluck('project_custom_fields.id')->all() ?? [];
        if ($chart !== null) {
            $input = array_replace($chart->only($chart->getFillable()), ['field_ids' => $old], $input);
        }
        $fields = $project->customFields()->whereIn('id', $input['field_ids'])->get()->keyBy('id');
        if ($fields->count() !== count($input['field_ids'])) {
            $this->invalid('field_ids', 'Selectează doar câmpuri din acest proiect.');
        }
        foreach ($fields as $field) {
            if ($field->archived_at !== null && ! in_array($field->id, $old, true)) {
                $this->invalid('field_ids', 'Nu poți adăuga un câmp arhivat.');
            }
            if ($input['type'] === 'pie') {
                if (count($input['field_ids']) !== 1 || ! (in_array($field->type, ['boolean', 'select'], true) || ($field->type === 'number' && $field->aggregation === 'sum'))) {
                    $this->invalid('field_ids', 'Pie necesită un singur câmp Boolean, Select sau Number cu agregare SUM.');
                }
            } elseif ($field->type !== 'number' || ! in_array($field->aggregation, ['sum', 'average', 'min', 'max', 'latest'], true)) {
                $this->invalid('field_ids', 'Line și Bar acceptă doar câmpuri numerice cu agregare validă.');
            }
        }
        $selectPie = $input['type'] === 'pie' && $fields->first()->type === 'select';
        $aggregation = $selectPie ? ($input['pie_aggregation'] ?? 'count') : null;
        $valueField = null;
        if ($aggregation === 'sum') {
            $valueField = $project->customFields()->find($input['pie_value_field_id'] ?? 0);
            if (! $valueField || $valueField->type !== 'number') {
                $this->invalid('pie_value_field_id', 'SUM necesită un câmp Number din același proiect.');
            }
            if ($valueField->archived_at !== null && $valueField->id !== $oldValueField) {
                $this->invalid('pie_value_field_id', 'Nu poți adăuga un câmp numeric arhivat.');
            }
        }
        $numericPie = $input['type'] === 'pie' && $fields->first()->type === 'number';
        if ($numericPie && (! isset($input['total']) || $this->compare((string) $input['total'], '0') <= 0)) {
            $this->invalid('total', 'Introdu un total pozitiv pentru Pie numeric.');
        }
        if (isset($input['y_min'], $input['y_max']) && $this->compare((string) $input['y_min'], (string) $input['y_max']) >= 0) {
            $this->invalid('y_max', 'Y Max trebuie să fie mai mare decât Y Min.');
        }
        $chart ??= new ProjectChart;
        $chart->fill([
            ...collect($input)->only(['title', 'type', 'priority', 'active'])->all(),
            'pie_aggregation' => $aggregation,
            'pie_value_field_id' => $valueField?->id,
            'total' => $numericPie ? $input['total'] : null,
            'y_min' => $input['type'] === 'line' ? ($input['y_min'] ?? null) : null,
            'y_max' => $input['type'] === 'line' ? ($input['y_max'] ?? null) : null,
        ]);
        $chart->project_id = $project->id;
        $chart->save();
        $chart->fields()->sync(collect($input['field_ids'])->mapWithKeys(fn ($id, $position) => [$id => ['project_id' => $project->id, 'position' => $position]])->all());

        return $chart->load(['fields', 'pieValueField']);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function compare(string $left, string $right): int
    {
        $parts = function (string $number): array {
            $negative = str_starts_with($number, '-');
            [$integer, $fraction] = array_pad(explode('.', ltrim($number, '-'), 2), 2, '');
            $digits = ltrim($integer.str_pad($fraction, 6, '0'), '0');

            return [$digits === '' ? false : $negative, $digits === '' ? '0' : $digits];
        };
        [$negativeLeft, $a] = $parts($left);
        [$negativeRight, $b] = $parts($right);
        if ($negativeLeft !== $negativeRight) {
            return $negativeLeft ? -1 : 1;
        }

        return (strlen($a) <=> strlen($b) ?: strcmp($a, $b)) * ($negativeLeft ? -1 : 1);
    }
}
