<?php

namespace Tests\Feature\Api;

use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectCustomField;
use App\Models\ProjectProgressNote;
use App\Models\ProjectProgressNoteValue;
use App\Models\User;
use App\Services\ProjectService;
use App\Services\ProjectSharingService;
use Database\Seeders\ProjectCategorySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectProgressNoteValuesApiTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private int $type;

    private array $fields;

    private int $option;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProjectCategorySeeder::class);
        $this->owner = User::create(['username' => 'values-owner', 'pin' => '1234']);
        Sanctum::actingAs($this->owner);
        $this->project = app(ProjectService::class)->create($this->owner, ['name' => 'Values', 'category_id' => ProjectCategory::firstOrFail()->id, 'note_template' => 'Legacy']);
        $this->base = "/api/projects/{$this->project->id}";
        $this->type = $this->postJson("{$this->base}/note-types", ['name' => 'General', 'template' => "Context:\n"])->assertCreated()->json('data.id');
        foreach (['number', 'text', 'boolean', 'select'] as $kind) {
            $extra = $kind === 'number' ? ['min' => '0', 'max' => '10', 'step' => '0.5', 'unit' => 'points'] : [];
            $this->fields[$kind] = $this->postJson("{$this->base}/custom-fields", ['name' => $kind, 'key' => $kind, 'type' => $kind, ...$extra])->assertCreated()->json('data.id');
            $this->postJson("{$this->base}/note-types/{$this->type}/fields", ['field_id' => $this->fields[$kind]])->assertCreated();
        }
        $this->option = $this->postJson("{$this->base}/custom-fields/{$this->fields['select']}/options", ['label' => 'Original'])->assertCreated()->json('data.id');
    }

    private function payload(array $overrides = []): array
    {
        return ['title' => 'Observation', 'content' => 'Text', 'noted_at' => '2020-01-01', 'note_type_id' => $this->type, ...$overrides];
    }

    private function entries(array $values): array
    {
        return collect($values)->map(fn ($value, $kind) => ['field_id' => $this->fields[$kind], 'value' => $value])->values()->all();
    }

    private function note(array $values = []): int
    {
        return $this->postJson("{$this->base}/notes", $this->payload(['values' => $this->entries($values)]))->assertCreated()->json('data.id');
    }

    private function values(int $id): array
    {
        return collect($this->getJson("{$this->base}/notes/{$id}")->assertOk()->json('data.values'))->pluck('value', 'field_id')->all();
    }

    public function test_all_types_round_trip_and_backdating_does_not_choose_configuration(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 10, 31)->setTime(12, 0, 0, 123456));
        $id = $this->note(['number' => 0, 'text' => "  Notes:\n\n", 'boolean' => false, 'select' => $this->option]);
        $response = $this->getJson("{$this->base}/notes/{$id}")->assertOk();
        $this->assertSame('2020-01-01', $response->json('data.noted_at'));
        $this->assertSame('2026-10-31T12:00:00.123456Z', $response->json('data.configuration_captured_at'));
        $this->assertSame(['0.000000', "  Notes:\n\n", false, $this->option], array_values($this->values($id)));
        $this->assertSame($this->type, $response->json('data.configuration.note_type.id'));
        $this->assertNotNull($response->json('data.configuration.fields.0.association_id'));
        $this->assertSame('2026-10-31T12:00:00.123456Z', ProjectCustomField::find($this->fields['number'])->first_value_at->toISOString());
    }

    public function test_required_zero_and_false_are_valid_and_missing_or_empty_values_fail_atomically(): void
    {
        foreach (['number', 'boolean'] as $kind) {
            $this->patchJson("{$this->base}/note-types/{$this->type}/fields/{$this->fields[$kind]}", ['required' => true])->assertOk();
        }
        $this->postJson("{$this->base}/notes", $this->payload())->assertUnprocessable();
        $this->postJson("{$this->base}/notes", $this->payload(['values' => []]))->assertUnprocessable();
        $this->assertDatabaseCount('project_progress_notes', 0);
        $this->assertDatabaseCount('project_progress_note_values', 0);
        $this->assertNull(ProjectCustomField::find($this->fields['number'])->first_value_at);
        $id = $this->note(['number' => 0, 'boolean' => false]);
        $this->patchJson("{$this->base}/notes/{$id}", ['values' => $this->entries(['boolean' => null])])->assertUnprocessable();
        $this->assertFalse($this->values($id)[$this->fields['boolean']]);
    }

    public function test_omitted_empty_partial_and_clear_semantics_preserve_other_values_and_first_marker(): void
    {
        $id = $this->note(['number' => '1.500000', 'text' => 'First', 'boolean' => false]);
        $marker = ProjectCustomField::find($this->fields['text'])->first_value_at->toISOString();
        $this->patchJson("{$this->base}/notes/{$id}", ['title' => 'Only title'])->assertOk();
        $this->patchJson("{$this->base}/notes/{$id}", ['values' => []])->assertOk();
        $this->patchJson("{$this->base}/notes/{$id}", ['values' => $this->entries(['text' => 'Second'])])->assertOk();
        $this->assertSame('1.500000', $this->values($id)[$this->fields['number']]);
        $this->assertFalse($this->values($id)[$this->fields['boolean']]);
        $this->patchJson("{$this->base}/notes/{$id}", ['values' => $this->entries(['text' => null])])->assertOk();
        $this->assertNull($this->values($id)[$this->fields['text']]);
        $this->assertSame($marker, ProjectCustomField::find($this->fields['text'])->first_value_at->toISOString());
        $this->assertDatabaseCount('project_progress_note_values', 4);
    }

    public function test_wrong_types_numeric_bounds_step_duplicate_and_unknown_ids_are_rejected(): void
    {
        foreach ([['number' => true], ['number' => '0.1'], ['number' => 11], ['number' => '-0.5'], ['number' => '1e2'], ['boolean' => 0], ['text' => false], ['select' => (string) $this->option], ['select' => [$this->option]], ['select' => 999999]] as $values) {
            $this->postJson("{$this->base}/notes", $this->payload(['values' => $this->entries($values)]))->assertUnprocessable();
        }
        $duplicate = [['field_id' => $this->fields['text'], 'value' => 'One'], ['field_id' => (string) $this->fields['text'], 'value' => 'Two']];
        $this->postJson("{$this->base}/notes", $this->payload(['values' => $duplicate]))->assertUnprocessable();
        $this->postJson("{$this->base}/notes", $this->payload(['values' => [['field_id' => 999999, 'value' => 1]]]))->assertUnprocessable();
        $this->assertDatabaseCount('project_progress_notes', 0);
    }

    public function test_foreign_project_types_fields_options_and_unattached_fields_are_rejected(): void
    {
        $other = app(ProjectService::class)->create($this->owner, ['name' => 'Other', 'category_id' => ProjectCategory::firstOrFail()->id]);
        $foreignType = $this->postJson("/api/projects/{$other->id}/note-types", ['name' => 'Other'])->assertCreated()->json('data.id');
        $foreignField = $this->postJson("/api/projects/{$other->id}/custom-fields", ['name' => 'Other', 'key' => 'other', 'type' => 'select'])->assertCreated()->json('data.id');
        $foreignOption = $this->postJson("/api/projects/{$other->id}/custom-fields/{$foreignField}/options", ['label' => 'Foreign'])->assertCreated()->json('data.id');
        $unattached = $this->postJson("{$this->base}/custom-fields", ['name' => 'Extra', 'key' => 'extra', 'type' => 'text'])->assertCreated()->json('data.id');
        $this->postJson("{$this->base}/notes", $this->payload(['note_type_id' => $foreignType]))->assertUnprocessable();
        foreach ([$foreignField, $unattached] as $fieldId) {
            $this->postJson("{$this->base}/notes", $this->payload(['values' => [['field_id' => $fieldId, 'value' => 'Text']]]))->assertUnprocessable();
        }
        $this->postJson("{$this->base}/notes", $this->payload(['values' => $this->entries(['select' => $foreignOption])]))->assertUnprocessable();
    }

    public function test_snapshot_is_immutable_after_required_order_metadata_option_and_period_changes(): void
    {
        $id = $this->note(['text' => 'Original', 'select' => $this->option]);
        $before = $this->getJson("{$this->base}/notes/{$id}")->json('data.configuration');
        $this->patchJson("{$this->base}/note-types/{$this->type}", ['name' => 'Renamed', 'template' => 'New'])->assertOk();
        $this->patchJson("{$this->base}/custom-fields/{$this->fields['text']}", ['name' => 'Renamed field'])->assertOk();
        $this->patchJson("{$this->base}/custom-fields/{$this->fields['select']}/options/{$this->option}", ['label' => 'Renamed option'])->assertOk();
        $this->patchJson("{$this->base}/note-types/{$this->type}/fields/{$this->fields['number']}", ['required' => true])->assertOk();
        $this->deleteJson("{$this->base}/note-types/{$this->type}/fields/{$this->fields['text']}")->assertNoContent();
        $this->postJson("{$this->base}/note-types/{$this->type}/fields", ['field_id' => $this->fields['text'], 'required' => true])->assertCreated();
        $this->postJson("{$this->base}/note-types/{$this->type}/fields/reorder", ['ids' => array_reverse(array_values($this->fields))])->assertOk();
        $this->patchJson("{$this->base}/notes/{$id}", ['content' => 'Edited', 'noted_at' => '2010-01-01', 'values' => $this->entries(['text' => 'Changed'])])->assertOk();
        $this->assertSame($before, $this->getJson("{$this->base}/notes/{$id}")->json('data.configuration'));
        $this->assertSame('Changed', $this->values($id)[$this->fields['text']]);
    }

    public function test_new_fields_are_not_required_or_accepted_by_historical_notes(): void
    {
        $id = $this->note();
        $new = $this->postJson("{$this->base}/custom-fields", ['name' => 'New', 'key' => 'new', 'type' => 'text'])->assertCreated()->json('data.id');
        $this->postJson("{$this->base}/note-types/{$this->type}/fields", ['field_id' => $new, 'required' => true])->assertCreated();
        $this->patchJson("{$this->base}/notes/{$id}", ['content' => 'Unrelated'])->assertOk();
        $this->patchJson("{$this->base}/notes/{$id}", ['values' => [['field_id' => $new, 'value' => 'No']]])->assertUnprocessable();
        $this->postJson("{$this->base}/notes", $this->payload())->assertUnprocessable();
    }

    public function test_archived_fields_allow_retaining_or_clearing_optional_values_but_no_replacement_until_restored(): void
    {
        $id = $this->note(['text' => 'Keep']);
        $this->postJson("{$this->base}/custom-fields/{$this->fields['text']}/archive")->assertOk();
        $this->patchJson("{$this->base}/notes/{$id}", ['content' => 'Unrelated', 'values' => $this->entries(['text' => 'Keep'])])->assertOk();
        $this->patchJson("{$this->base}/notes/{$id}", ['values' => $this->entries(['text' => 'Replace'])])->assertConflict();
        $this->patchJson("{$this->base}/notes/{$id}", ['values' => $this->entries(['text' => null])])->assertOk();
        $this->patchJson("{$this->base}/notes/{$id}", ['values' => $this->entries(['text' => 'Introduce'])])->assertConflict();
        $response = $this->getJson("{$this->base}/notes/{$id}")->assertOk();
        $this->assertNull($response->json('data.configuration.fields.1.definition.archived_at'));
        $this->assertNotNull($response->json('data.current_configuration_state.fields.1.archived_at'));
        $this->postJson("{$this->base}/custom-fields/{$this->fields['text']}/restore")->assertOk();
        $this->patchJson("{$this->base}/notes/{$id}", ['values' => $this->entries(['text' => 'Restored'])])->assertOk();
    }

    public function test_archived_options_can_be_retained_but_not_newly_selected_and_restore_reenables_selection(): void
    {
        $id = $this->note(['select' => $this->option]);
        $empty = $this->note();
        $url = "{$this->base}/custom-fields/{$this->fields['select']}/options/{$this->option}";
        $this->postJson("{$url}/archive")->assertOk();
        $this->patchJson("{$this->base}/notes/{$id}", ['values' => $this->entries(['select' => $this->option])])->assertOk();
        $this->patchJson("{$this->base}/notes/{$empty}", ['values' => $this->entries(['select' => $this->option])])->assertUnprocessable();
        $this->patchJson("{$this->base}/notes/{$id}", ['values' => $this->entries(['select' => null])])->assertOk();
        $this->postJson("{$url}/restore")->assertOk();
        $this->patchJson("{$this->base}/notes/{$id}", ['values' => $this->entries(['select' => $this->option])])->assertOk();
        $newOption = $this->postJson("{$this->base}/custom-fields/{$this->fields['select']}/options", ['label' => 'Later'])->assertCreated()->json('data.id');
        $this->patchJson("{$this->base}/notes/{$id}", ['values' => $this->entries(['select' => $newOption])])->assertUnprocessable();
    }

    public function test_detachment_and_archived_note_type_do_not_prevent_historical_value_edits(): void
    {
        $id = $this->note(['text' => 'Before']);
        $secondType = $this->postJson("{$this->base}/note-types", ['name' => 'Second'])->assertCreated()->json('data.id');
        $this->postJson("{$this->base}/note-types/{$secondType}/set-default")->assertOk();
        $this->deleteJson("{$this->base}/note-types/{$this->type}/fields/{$this->fields['text']}")->assertNoContent();
        $this->postJson("{$this->base}/note-types/{$this->type}/archive")->assertOk();
        $this->patchJson("{$this->base}/notes/{$id}", ['values' => $this->entries(['text' => 'Historical edit'])])->assertOk();
        $this->postJson("{$this->base}/notes", $this->payload())->assertConflict();
        $this->postJson("{$this->base}/note-types/{$this->type}/restore")->assertOk();
        $this->postJson("{$this->base}/notes", $this->payload())->assertCreated();
    }

    public function test_incompatible_type_and_unit_changes_before_first_value_conflict_without_rebinding(): void
    {
        $id = $this->note();
        $this->patchJson("{$this->base}/custom-fields/{$this->fields['text']}", ['type' => 'boolean'])->assertOk();
        $this->patchJson("{$this->base}/custom-fields/{$this->fields['number']}", ['unit' => 'different'])->assertOk();
        foreach (['text' => 'Text', 'number' => 1] as $kind => $value) {
            $this->patchJson("{$this->base}/notes/{$id}", ['values' => $this->entries([$kind => $value])])->assertConflict();
        }
        $this->patchJson("{$this->base}/notes/{$id}", ['content' => 'Still editable'])->assertOk();
    }

    public function test_historical_validation_uses_captured_bounds_not_later_widened_bounds(): void
    {
        $id = $this->note(['number' => 1]);
        $this->patchJson("{$this->base}/custom-fields/{$this->fields['number']}", ['max' => '20'])->assertOk();
        $this->patchJson("{$this->base}/notes/{$id}", ['values' => $this->entries(['number' => 15])])->assertUnprocessable();
        $this->assertSame('1.000000', $this->values($id)[$this->fields['number']]);
    }

    public function test_legacy_clients_remain_untyped_and_cannot_be_implicitly_converted(): void
    {
        $payload = $this->payload();
        unset($payload['note_type_id']);
        $id = $this->postJson("{$this->base}/notes", $payload)->assertCreated()->assertJsonPath('data.note_type_id', null)->assertJsonPath('data.configuration', null)->assertJsonPath('data.values', [])->json('data.id');
        $this->patchJson("{$this->base}/notes/{$id}", ['note_type_id' => $this->type])->assertConflict();
        $this->patchJson("{$this->base}/notes/{$id}", ['values' => $this->entries(['text' => 'No'])])->assertUnprocessable();
        $typed = $this->note();
        $this->patchJson("{$this->base}/notes/{$typed}", ['note_type_id' => null])->assertConflict();
        $this->patchJson("{$this->base}/notes/{$typed}", ['note_type_id' => $this->type])->assertOk();
        $this->assertSame('Legacy', $this->project->fresh()->note_template);
    }

    public function test_clients_cannot_supply_snapshot_or_capture_time(): void
    {
        foreach (['configuration', 'configuration_snapshot', 'configuration_captured_at', 'first_value_at'] as $key) {
            $this->postJson("{$this->base}/notes", $this->payload([$key => 'Untrusted']))->assertUnprocessable();
        }
    }

    public function test_collaborators_keep_existing_note_permissions(): void
    {
        $author = User::create(['username' => 'values-author', 'pin' => '1234']);
        $reader = User::create(['username' => 'values-reader', 'pin' => '1234']);
        foreach ([$author, $reader] as $user) {
            app(ProjectSharingService::class)->add($this->owner, $this->project, ['user_id' => $user->id, 'sharing_mode' => 'all_tasks']);
        }
        Sanctum::actingAs($author);
        $id = $this->note(['boolean' => false]);
        $this->patchJson("{$this->base}/notes/{$id}", ['values' => $this->entries(['text' => 'Author edit'])])->assertOk();
        Sanctum::actingAs($reader);
        $this->getJson("{$this->base}/notes/{$id}")->assertNotFound();
        $this->patchJson("{$this->base}/notes/{$id}", ['values' => $this->entries(['text' => 'No'])])->assertForbidden();
        Sanctum::actingAs($this->owner);
        $this->patchJson("{$this->base}/notes/{$id}", ['values' => $this->entries(['text' => 'Owner edit'])])->assertOk();
        $this->patchJson("{$this->base}/status", ['status' => 'archived'])->assertOk();
        $this->patchJson("{$this->base}/notes/{$id}", ['title' => 'No'])->assertForbidden();
    }

    public function test_late_write_failure_rolls_back_note_values_and_first_value_markers(): void
    {
        ProjectProgressNoteValue::creating(function ($row) {
            if ($row->value_type === 'boolean') {
                throw new \RuntimeException('Injected late value failure');
            }
        });
        try {
            $this->postJson("{$this->base}/notes", $this->payload(['values' => $this->entries(['number' => 1, 'boolean' => false])]))->assertStatus(500);
            $this->assertDatabaseCount('project_progress_notes', 0);
            $this->assertDatabaseCount('project_progress_note_values', 0);
            $this->assertNull(ProjectCustomField::find($this->fields['number'])->first_value_at);
        } finally {
            ProjectProgressNoteValue::flushEventListeners();
        }
    }

    public function test_failure_after_marker_update_rolls_back_the_entire_save(): void
    {
        $markerObserved = false;
        Project::saving(function () use (&$markerObserved) {
            if (ProjectCustomField::whereNotNull('first_value_at')->exists()) {
                $markerObserved = true;
                throw new \RuntimeException('Failure after marker persistence');
            }
        });
        try {
            $this->postJson("{$this->base}/notes", $this->payload(['values' => $this->entries(['number' => 0, 'boolean' => false])]))->assertStatus(500);
            $this->assertDatabaseCount('project_progress_notes', 0);
            $this->assertDatabaseCount('project_progress_note_values', 0);
            $this->assertSame(0, ProjectCustomField::whereNotNull('first_value_at')->count());
            $this->assertTrue($markerObserved);
        } finally {
            Project::flushEventListeners();
        }
    }

    public function test_malformed_value_entries_and_note_type_rebinding_are_rejected(): void
    {
        foreach ([null, 'wrong', [['field_id' => $this->fields['text']]], [['field_id' => $this->fields['text'], 'value' => 'Text', 'extra' => true]], ['named' => ['field_id' => $this->fields['text'], 'value' => 'Text']]] as $values) {
            $this->postJson("{$this->base}/notes", $this->payload(['values' => $values]))->assertUnprocessable();
        }
        $id = $this->note();
        $second = $this->postJson("{$this->base}/note-types", ['name' => 'Other'])->assertCreated()->json('data.id');
        $this->patchJson("{$this->base}/notes/{$id}", ['note_type_id' => $second])->assertConflict();
        $this->assertSame($this->type, ProjectProgressNote::find($id)->note_type_id);
    }

    public function test_decimal_precision_and_empty_optional_text_are_preserved(): void
    {
        $this->patchJson("{$this->base}/custom-fields/{$this->fields['number']}", ['min' => null, 'max' => null, 'step' => null])->assertOk();
        $id = $this->note(['number' => '999999999999.123456', 'text' => '']);
        $this->assertSame('999999999999.123456', $this->values($id)[$this->fields['number']]);
        $this->assertSame('', $this->values($id)[$this->fields['text']]);
    }

    public function test_listing_eager_loads_values_and_current_state_without_per_note_queries(): void
    {
        $this->note(['boolean' => false]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson("{$this->base}/notes")->assertOk();
        $single = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($i = 0; $i < 3; $i++) {
            $this->note(['boolean' => false]);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson("{$this->base}/notes")->assertOk()->assertJsonCount(4, 'data')->assertJsonPath('data.0.values.2.value', false);
        $multiple = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($single, $multiple, 'Adding notes must not add per-note configuration queries.');
    }

    public function test_database_rejects_foreign_options_and_invalid_typed_columns(): void
    {
        $id = $this->note();
        try {
            DB::table('project_progress_note_values')->where('note_id', $id)->where('field_id', $this->fields['text'])->update(['boolean_value' => 1]);
            $this->fail('Expected typed-column constraint');
        } catch (QueryException) {
        }
        try {
            DB::table('project_progress_note_values')->where('note_id', $id)->where('field_id', $this->fields['number'])->update(['value_type' => 'select', 'select_option_id' => $this->option]);
            $this->fail('Expected option ownership constraint');
        } catch (QueryException) {
        }
        $this->assertDatabaseCount('project_progress_note_values', 4);
    }

    public function test_database_rejects_duplicate_values_foreign_projects_invalid_context_and_destructive_deletes(): void
    {
        $id = $this->note(['select' => $this->option]);
        $other = app(ProjectService::class)->create($this->owner, ['name' => 'Other', 'category_id' => ProjectCategory::firstOrFail()->id]);
        $foreignType = $this->postJson("/api/projects/{$other->id}/note-types", ['name' => 'Foreign'])->assertCreated()->json('data.id');
        $attempts = [
            fn () => DB::table('project_progress_note_values')->insert(['project_id' => $this->project->id, 'note_id' => $id, 'field_id' => $this->fields['text'], 'value_type' => 'text']),
            fn () => DB::table('project_progress_note_values')->where('note_id', $id)->update(['project_id' => $other->id]),
            fn () => DB::table('project_progress_note_values')->where('note_id', $id)->where('field_id', $this->fields['boolean'])->update(['boolean_value' => 2]),
            fn () => DB::table('project_progress_notes')->where('id', $id)->update(['note_type_id' => $foreignType]),
            fn () => DB::table('project_progress_notes')->where('id', $id)->update(['configuration_snapshot' => null]),
            fn () => DB::table('project_progress_notes')->where('id', $id)->update(['configuration_snapshot' => '{}']),
            fn () => DB::table('project_progress_notes')->where('id', $id)->update(['configuration_captured_at' => '2030-01-01 00:00:00']),
            fn () => DB::table('project_custom_fields')->where('id', $this->fields['text'])->delete(),
            fn () => DB::table('project_custom_field_options')->where('id', $this->option)->delete(),
            fn () => DB::table('project_note_types')->where('id', $this->type)->delete(),
            fn () => DB::table('project_progress_notes')->where('id', $id)->delete(),
        ];
        foreach ($attempts as $attempt) {
            try {
                $attempt();
                $this->fail('Expected database integrity rejection.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertDatabaseCount('project_progress_note_values', 4);
    }

    public function test_configuration_is_checked_inside_the_locked_save_and_conflicts_roll_back(): void
    {
        // Simulate a change after request validation but before context capture.
        ProjectProgressNote::creating(function () {
            DB::table('project_note_types')->where('id', $this->type)->update(['archived_at' => now('UTC')]);
        });
        try {
            $this->postJson("{$this->base}/notes", $this->payload())->assertConflict();
            $this->assertDatabaseCount('project_progress_notes', 0);
            $this->assertDatabaseCount('project_progress_note_values', 0);
            $this->assertNull($this->project->noteTypes()->find($this->type)->archived_at);
        } finally {
            ProjectProgressNote::flushEventListeners();
        }
    }

    public function test_required_archived_values_can_be_retained_but_not_cleared(): void
    {
        $this->patchJson("{$this->base}/note-types/{$this->type}/fields/{$this->fields['text']}", ['required' => true])->assertOk();
        $id = $this->note(['text' => 'Keep']);
        $this->postJson("{$this->base}/custom-fields/{$this->fields['text']}/archive")->assertOk();
        $this->patchJson("{$this->base}/notes/{$id}", ['title' => 'Still editable'])->assertOk();
        $this->patchJson("{$this->base}/notes/{$id}", ['values' => $this->entries(['text' => 'Keep'])])->assertOk();
        $this->patchJson("{$this->base}/notes/{$id}", ['values' => $this->entries(['text' => null])])->assertUnprocessable();
        // Archived fields are excluded from the applicable configuration of NEW notes.
        $new = $this->postJson("{$this->base}/notes", $this->payload())->assertCreated();
        $this->assertCount(3, $new->json('data.configuration.fields'));
    }

    public function test_soft_delete_and_restore_keep_values_snapshot_and_marker(): void
    {
        $id = $this->note(['number' => 0]);
        $snapshot = ProjectProgressNote::find($id)->configuration_snapshot;
        $marker = ProjectCustomField::find($this->fields['number'])->first_value_at->toISOString();
        $this->deleteJson("{$this->base}/notes/{$id}")->assertNoContent();
        $this->assertDatabaseCount('project_progress_note_values', 4);
        $this->postJson("{$this->base}/notes/{$id}/restore")->assertOk()->assertJsonPath('data.configuration', $snapshot);
        $this->assertSame('0.000000', $this->values($id)[$this->fields['number']]);
        $this->assertSame($marker, ProjectCustomField::find($this->fields['number'])->first_value_at->toISOString());
    }

    public function test_mysql_enforces_native_checks_foreign_keys_uniqueness_and_snapshot_trigger(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native MySQL integrity verification.');
        }
        $id = $this->note(['select' => $this->option]);
        $checks = DB::select("SELECT CONSTRAINT_NAME, ENFORCED FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME IN ('note_context_check','note_value_type_check')");
        $this->assertCount(2, $checks);
        foreach ($checks as $check) {
            $this->assertSame('YES', $check->ENFORCED);
        }
        $column = DB::selectOne("SELECT DATA_TYPE, NUMERIC_PRECISION, NUMERIC_SCALE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'project_progress_note_values' AND COLUMN_NAME = 'number_value'");
        $this->assertSame('decimal', $column->DATA_TYPE);
        $this->assertSame(20, (int) $column->NUMERIC_PRECISION);
        $this->assertSame(6, (int) $column->NUMERIC_SCALE);
        $engines = DB::select("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('project_progress_notes','project_progress_note_values','project_note_types','project_custom_fields','project_custom_field_options')");
        $this->assertCount(5, $engines);
        foreach ($engines as $engine) {
            $this->assertSame('InnoDB', $engine->ENGINE);
        }
        $trigger = (array) DB::selectOne('SHOW CREATE TRIGGER note_context_immutable');
        $this->assertTrue(str_contains($trigger['SQL Original Statement'], 'Saved note configuration is immutable'), 'Expected immutable-context trigger body.');
        $attempts = [
            [3819, fn () => DB::table('project_progress_note_values')->where('note_id', $id)->where('field_id', $this->fields['text'])->update(['boolean_value' => 1])],
            [3819, fn () => DB::table('project_progress_note_values')->where('note_id', $id)->where('field_id', $this->fields['boolean'])->update(['boolean_value' => 2])],
            [[1216, 1452], fn () => DB::table('project_progress_note_values')->where('note_id', $id)->where('field_id', $this->fields['number'])->update(['value_type' => 'select', 'select_option_id' => $this->option])],
            [[1216, 1452], fn () => DB::table('project_progress_note_values')->where('note_id', $id)->update(['project_id' => 999999])],
            [1062, fn () => DB::table('project_progress_note_values')->insert(['project_id' => $this->project->id, 'note_id' => $id, 'field_id' => $this->fields['text'], 'value_type' => 'text'])],
            [[1217, 1451], fn () => DB::table('project_custom_field_options')->where('id', $this->option)->delete()],
            [[1217, 1451], fn () => DB::table('project_progress_notes')->where('id', $id)->delete()],
            [1644, fn () => DB::table('project_progress_notes')->where('id', $id)->update(['configuration_snapshot' => '{}'])],
            [3819, fn () => DB::table('project_progress_notes')->insert(['project_id' => $this->project->id, 'created_by' => $this->owner->id, 'title' => 'Bad context', 'content' => 'Text', 'noted_at' => '2020-01-01', 'note_type_id' => $this->type])],
        ];
        foreach ($attempts as [$code, $attempt]) {
            try {
                $attempt();
                $this->fail('Expected native MySQL integrity rejection.');
            } catch (QueryException $failure) {
                $this->assertContains((int) $failure->errorInfo[1], (array) $code);
                if (is_array($code)) {
                    $this->assertSame('23000', $failure->errorInfo[0]);
                }
            }
        }
    }
}
