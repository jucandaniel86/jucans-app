<?php

namespace Tests\Feature\Api;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectCustomField;
use App\Models\ProjectNoteType;
use App\Models\ProjectNoteTypeField;
use App\Models\User;
use App\Services\ProjectNoteConfigurationService;
use App\Services\ProjectService;
use Database\Seeders\ProjectCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectNoteConfigurationApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Project $project;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProjectCategorySeeder::class);
        $this->owner = User::create(['username' => 'config-owner', 'pin' => '1234']);
        Sanctum::actingAs($this->owner);
        $this->project = $this->newProject();
        $this->base = "/api/projects/{$this->project->id}";
    }

    private function newProject(): Project
    {
        return app(ProjectService::class)->create($this->owner, [
            'name' => 'Configuration', 'category_id' => ProjectCategory::firstOrFail()->id,
            'note_template' => '{project} legacy template',
        ]);
    }

    private function field(array $input = []): int
    {
        return $this->postJson("{$this->base}/custom-fields", [
            'name' => 'Energy', 'key' => 'energy', 'type' => 'number', ...$input,
        ])->assertCreated()->json('data.id');
    }

    private function type(string $name = 'General'): int
    {
        return $this->postJson("{$this->base}/note-types", ['name' => $name])->assertCreated()->json('data.id');
    }

    public function test_configuration_is_opt_in_and_initialization_is_idempotent_and_copies_only_template(): void
    {
        $this->getJson("{$this->base}/note-types")->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('project_note_types', 0);
        $before = $this->project->fresh()->getRawOriginal();
        $service = app(ProjectNoteConfigurationService::class);
        $first = $service->initialize($this->owner, $this->project);
        $this->assertSame('General', $first->name);
        $this->assertSame($this->project->note_template, $first->template);
        $this->assertTrue($first->is_default);
        $first->update(['template' => 'Edited independently']);
        $second = $service->initialize($this->owner, $this->project);
        $this->assertSame($first->id, $second->id);
        $this->assertSame('Edited independently', $second->template);
        $this->assertDatabaseCount('project_note_types', 1);
        $this->assertSame($before, $this->project->fresh()->getRawOriginal());
        $this->assertDatabaseCount('project_progress_notes', 0);
        $this->assertDatabaseCount('project_task_evaluations', 0);
    }

    public function test_owner_can_manage_fields_and_keys_are_unique_and_stable(): void
    {
        $id = $this->field(['unit' => 'points', 'min' => -10, 'max' => 10, 'step' => '0.5']);
        $this->getJson("{$this->base}/custom-fields/{$id}")->assertOk()
            ->assertJsonPath('data.aggregation', 'average')->assertJsonPath('data.min', '-10.000000');
        $this->patchJson("{$this->base}/custom-fields/{$id}", ['name' => 'Mood', 'description' => 'Daily'])
            ->assertOk()->assertJsonPath('data.name', 'Mood');
        $this->field(['key' => 'other']);
        $this->postJson("{$this->base}/custom-fields", ['name' => 'Duplicate', 'key' => 'energy', 'type' => 'text'])
            ->assertUnprocessable()->assertJsonValidationErrors('key');
        $this->patchJson("{$this->base}/custom-fields/{$id}", ['key' => 'changed'])->assertUnprocessable();
        $this->postJson("{$this->base}/custom-fields/{$id}/archive")->assertOk();
        $this->getJson("{$this->base}/custom-fields")->assertOk()->assertJsonCount(2, 'data');
        $this->postJson("{$this->base}/custom-fields/{$id}/restore")->assertOk()->assertJsonPath('data.archived_at', null);
        $this->deleteJson("{$this->base}/custom-fields/{$id}")->assertMethodNotAllowed();
    }

    public function test_field_configuration_validation(): void
    {
        foreach ([
            ['type' => 'date'], ['key' => 'not stable!'], ['type' => 'text', 'unit' => 'kg'],
            ['type' => 'boolean', 'min' => 0], ['min' => 2, 'max' => 1], ['step' => 0],
            ['step' => -1], ['step' => '0.0000001'], ['max' => '1000000000000'], ['aggregation' => 'median'],
            ['first_value_at' => '2026-01-01'], ['project_id' => 999],
        ] as $invalid) {
            $this->postJson("{$this->base}/custom-fields", [
                'name' => 'Invalid', 'key' => 'invalid', 'type' => 'number', ...$invalid,
            ])->assertUnprocessable();
        }
        $this->assertDatabaseCount('project_custom_fields', 0);
        foreach (['number', 'select', 'boolean', 'text'] as $type) {
            $this->field(['key' => $type, 'type' => $type]);
        }
    }

    public function test_future_first_value_marker_locks_semantics_and_preserves_numeric_domain(): void
    {
        $id = $this->field(['unit' => 'kg', 'min' => 0, 'max' => 10, 'step' => 1]);
        ProjectCustomField::findOrFail($id)->forceFill(['first_value_at' => now()])->save();
        foreach ([['type' => 'text'], ['unit' => 'lb'], ['min' => 1], ['max' => 9], ['step' => 2], ['min' => '-0.25', 'step' => '0.5']] as $invalid) {
            $this->patchJson("{$this->base}/custom-fields/{$id}", $invalid)->assertUnprocessable();
        }
        $this->patchJson("{$this->base}/custom-fields/{$id}", [
            'name' => 'Weight', 'description' => 'Still kg', 'min' => -1, 'max' => 20, 'step' => '0.5', 'aggregation' => 'latest',
        ])->assertOk()->assertJsonPath('data.aggregation', 'latest');
        $this->patchJson("{$this->base}/custom-fields/{$id}", ['min' => null, 'max' => null, 'step' => null])->assertOk();
    }

    public function test_select_option_lifecycle_reordering_and_field_type_guard(): void
    {
        $field = $this->field(['type' => 'select']);
        $url = "{$this->base}/custom-fields/{$field}/options";
        $one = $this->postJson($url, ['label' => 'Low'])->assertCreated()->json('data.id');
        $two = $this->postJson($url, ['label' => 'High'])->assertCreated()->json('data.id');
        $this->patchJson("{$url}/{$one}", ['label' => 'Lower'])->assertOk()->assertJsonPath('data.label', 'Lower');
        $this->postJson("{$url}/reorder", ['ids' => [$two, $one]])->assertOk()->assertJsonPath('data.0.id', $two);
        $this->postJson("{$url}/reorder", ['ids' => [$one, $one]])->assertUnprocessable();
        $this->postJson("{$url}/reorder", ['ids' => [$one, 9999]])->assertUnprocessable();
        $this->postJson("{$url}/{$one}/archive")->assertOk();
        $this->getJson($url)->assertOk()->assertJsonCount(2, 'data');
        $this->postJson("{$url}/{$one}/restore")->assertOk()->assertJsonPath('data.archived_at', null);
        $this->patchJson("{$this->base}/custom-fields/{$field}", ['type' => 'text'])->assertUnprocessable();
        $numeric = $this->field(['key' => 'numeric']);
        $this->postJson("{$this->base}/custom-fields/{$numeric}/options", ['label' => 'Invalid'])->assertUnprocessable();
        $this->deleteJson("{$url}/{$one}")->assertMethodNotAllowed();
    }

    public function test_note_type_template_preserves_whitespace_without_changing_legacy_template(): void
    {
        $template = "  Context:\n\nObservații:  \n";
        $id = $this->postJson("{$this->base}/note-types", ['name' => 'General', 'template' => $template])
            ->assertCreated()->assertJsonPath('data.template', $template)->json('data.id');
        $updated = "\n  Follow-up:\nNotes:  \n\n";
        $this->patchJson("{$this->base}/note-types/{$id}", ['template' => $updated])
            ->assertOk()->assertJsonPath('data.template', $updated);
        $this->getJson("{$this->base}/note-types/{$id}")->assertOk()->assertJsonPath('data.template', $updated);
        $this->assertSame($updated, ProjectNoteType::findOrFail($id)->template);
        $this->assertSame('{project} legacy template', $this->project->fresh()->note_template);
    }

    public function test_note_type_default_and_archive_invariants(): void
    {
        $one = $this->type();
        $this->getJson("{$this->base}/note-types/{$one}")->assertOk()->assertJsonPath('data.is_default', true);
        $this->postJson("{$this->base}/note-types/{$one}/archive")->assertConflict();
        $two = $this->type('Therapy');
        $this->postJson("{$this->base}/note-types", ['name' => 'General'])->assertUnprocessable();
        $this->patchJson("{$this->base}/note-types/{$two}", ['name' => 'General'])->assertUnprocessable();
        $this->patchJson("{$this->base}/note-types/{$two}", ['template' => 'Separate template', 'is_default' => true])->assertUnprocessable();
        foreach ([$two, $one, $two] as $id) {
            $this->postJson("{$this->base}/note-types/{$id}/set-default")->assertOk()->assertJsonPath('data.is_default', true);
            $this->assertSame(1, $this->project->noteTypes()->whereNotNull('is_default')->count());
        }
        $this->postJson("{$this->base}/note-types/{$one}/archive")->assertOk();
        $this->postJson("{$this->base}/note-types/{$one}/set-default")->assertConflict();
        $this->postJson("{$this->base}/note-types/{$two}/archive")->assertConflict();
        $this->postJson("{$this->base}/note-types/{$one}/restore")->assertOk()->assertJsonPath('data.is_default', false);
        $this->patchJson("{$this->base}/note-types/{$one}", ['template' => 'New type template'])->assertOk();
        $this->assertSame('{project} legacy template', $this->project->fresh()->note_template);
    }

    public function test_reusable_fields_have_independent_required_order_and_period_history(): void
    {
        $field = $this->field();
        $second = $this->field(['key' => 'second']);
        $one = $this->type();
        $two = $this->type('Other');
        $a = "{$this->base}/note-types/{$one}/fields";
        $b = "{$this->base}/note-types/{$two}/fields";
        $this->postJson($a, ['field_id' => $field, 'required' => true])->assertCreated();
        $this->postJson($a, ['field_id' => $field])->assertConflict();
        $this->postJson($b, ['field_id' => $field, 'required' => false, 'sort_order' => 5])->assertCreated();
        $this->postJson($a, ['field_id' => $second])->assertCreated();
        $this->travel(1)->seconds();
        $this->postJson("{$a}/reorder", ['ids' => [$second, $field]])->assertOk()->assertJsonPath('data.0.field_id', $second);
        $this->patchJson("{$a}/{$field}", ['required' => false])->assertOk()->assertJsonPath('data.required', false);
        $this->getJson($b)->assertOk()->assertJsonPath('data.0.sort_order', 5)->assertJsonPath('data.0.required', false);
        $this->travel(1)->seconds();
        $this->deleteJson("{$a}/{$field}")->assertNoContent();
        $this->getJson($a)->assertOk()->assertJsonCount(1, 'data');
        $this->travel(1)->seconds();
        $this->postJson($a, ['field_id' => $field, 'required' => true])->assertCreated();
        $this->getJson("{$a}?history=1")->assertOk();
        $history = ProjectNoteTypeField::where('note_type_id', $one)->where('field_id', $field)->orderBy('id')->get();
        $this->assertCount(4, $history);
        foreach ($history as $index => $period) {
            if ($index < 3) {
                $this->assertNotNull($period->deactivated_at);
                $this->assertNull($period->active_slot);
                $this->assertTrue($period->deactivated_at <= $history[$index + 1]->activated_at);
            } else {
                $this->assertNull($period->deactivated_at);
            }
        }
        $this->assertTrue($history[0]->required);
    }

    public function test_archiving_keeps_associations_and_history_but_blocks_new_attachments(): void
    {
        $field = $this->field();
        $type = $this->type();
        $other = $this->type('Other');
        $url = "{$this->base}/note-types/{$type}/fields";
        $this->postJson($url, ['field_id' => $field])->assertCreated();
        $this->postJson("{$this->base}/custom-fields/{$field}/archive")->assertOk();
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("{$this->base}/note-types/{$other}/fields", ['field_id' => $field])->assertConflict();
        $this->postJson("{$this->base}/note-types/{$other}/archive")->assertOk();
        $this->postJson("{$this->base}/note-types/{$other}/fields", ['field_id' => $field])->assertConflict();
        $this->assertDatabaseCount('project_note_type_fields', 1);
    }

    public function test_create_and_attach_is_atomic_including_failure_after_field_insert(): void
    {
        $type = $this->type();
        $other = $this->type('Archived');
        $this->postJson("{$this->base}/note-types/{$other}/archive")->assertOk();
        $payload = ['field' => ['name' => 'Text', 'key' => 'text', 'type' => 'text'], 'required' => true];
        $this->postJson("{$this->base}/note-types/{$other}/fields/create", $payload)->assertConflict();
        $this->assertDatabaseCount('project_custom_fields', 0);
        $this->postJson("{$this->base}/note-types/{$type}/fields/create", $payload)->assertCreated()
            ->assertJsonPath('data.field.key', 'text')->assertJsonPath('data.required', true);
        $this->assertDatabaseCount('project_custom_fields', 1);
        $this->assertDatabaseCount('project_note_type_fields', 1);
        $this->postJson("{$this->base}/note-types/{$type}/fields/create", ['field' => ['name' => 'Invalid', 'key' => 'invalid', 'type' => 'nope']])->assertUnprocessable();
        $this->assertDatabaseCount('project_custom_fields', 1);
    }

    public function test_reorder_rejects_incomplete_foreign_and_duplicate_ids_without_changes(): void
    {
        $field = $this->field();
        $type = $this->type();
        $url = "{$this->base}/note-types/{$type}/fields";
        $this->postJson($url, ['field_id' => $field])->assertCreated();
        foreach ([[], [$field, $field], [9999]] as $ids) {
            $this->postJson("{$url}/reorder", ['ids' => $ids])->assertUnprocessable();
        }
        $this->assertDatabaseCount('project_note_type_fields', 1);
    }

    public function test_collaborators_can_read_but_cannot_write_and_strangers_are_denied(): void
    {
        $field = $this->field(['type' => 'select']);
        $type = $this->type();
        $option = $this->postJson("{$this->base}/custom-fields/{$field}/options", ['label' => 'A'])->json('data.id');
        $collaborator = User::create(['username' => 'collaborator', 'pin' => '1234']);
        $this->project->collaborators()->create(['user_id' => $collaborator->id, 'sharing_mode' => 'all_tasks']);
        Sanctum::actingAs($collaborator);
        foreach (['custom-fields', "custom-fields/{$field}", "custom-fields/{$field}/options", 'note-types', "note-types/{$type}", "note-types/{$type}/fields"] as $path) {
            $this->getJson("{$this->base}/{$path}")->assertOk();
        }
        foreach (['custom-fields', "custom-fields/{$field}/archive", "custom-fields/{$field}/restore", "custom-fields/{$field}/options", "custom-fields/{$field}/options/reorder", "custom-fields/{$field}/options/{$option}/archive", "custom-fields/{$field}/options/{$option}/restore", 'note-types', "note-types/{$type}/archive", "note-types/{$type}/restore", "note-types/{$type}/set-default", "note-types/{$type}/fields", "note-types/{$type}/fields/create", "note-types/{$type}/fields/reorder"] as $path) {
            $this->postJson("{$this->base}/{$path}", [])->assertForbidden();
        }
        foreach (["custom-fields/{$field}", "custom-fields/{$field}/options/{$option}", "note-types/{$type}", "note-types/{$type}/fields/{$field}"] as $path) {
            $this->patchJson("{$this->base}/{$path}", [])->assertForbidden();
        }
        $this->deleteJson("{$this->base}/note-types/{$type}/fields/{$field}")->assertForbidden();
        $stranger = User::create(['username' => 'stranger', 'pin' => '1234']);
        Sanctum::actingAs($stranger);
        $this->getJson("{$this->base}/custom-fields")->assertForbidden();
        $this->getJson("{$this->base}/note-types")->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson("{$this->base}/custom-fields")->assertUnauthorized();
    }

    public function test_inactive_project_is_readable_but_read_only_even_with_stale_service_model(): void
    {
        $field = $this->field();
        $type = $this->type();
        foreach ([ProjectStatus::ARCHIVED, ProjectStatus::COMPLETED] as $status) {
            $this->project->forceFill(['status' => $status])->save();
            $this->getJson("{$this->base}/custom-fields")->assertOk();
            $this->postJson("{$this->base}/custom-fields", [])->assertForbidden();
            $this->patchJson("{$this->base}/custom-fields/{$field}", ['name' => 'Denied'])->assertForbidden();
            $this->postJson("{$this->base}/note-types/{$type}/set-default")->assertForbidden();
        }
        $stale = clone $this->project;
        $stale->status = ProjectStatus::ACTIVE;
        try {
            app(ProjectNoteConfigurationService::class)->initialize($this->owner, $stale);
            $this->fail('Must reauthorize under the project lock.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertSame('Energy', ProjectCustomField::findOrFail($field)->name);
        }
    }

    public function test_cross_project_resources_and_associations_are_rejected(): void
    {
        $field = $this->field(['type' => 'select']);
        $type = $this->type();
        $option = $this->postJson("{$this->base}/custom-fields/{$field}/options", ['label' => 'A'])->json('data.id');
        $otherProject = $this->newProject();
        $other = "/api/projects/{$otherProject->id}";
        foreach (["custom-fields/{$field}", "custom-fields/{$field}/options", "note-types/{$type}", "note-types/{$type}/fields"] as $path) {
            $this->getJson("{$other}/{$path}")->assertNotFound();
        }
        $this->patchJson("{$other}/custom-fields/{$field}", ['name' => 'Denied'])->assertNotFound();
        $this->postJson("{$other}/note-types/{$type}/set-default")->assertNotFound();
        $otherType = $this->postJson("{$other}/note-types", ['name' => 'Other'])->json('data.id');
        $this->postJson("{$other}/note-types/{$otherType}/fields", ['field_id' => $field])->assertNotFound();
        $second = $this->field(['key' => 'second', 'type' => 'select']);
        $this->patchJson("{$this->base}/custom-fields/{$second}/options/{$option}", ['label' => 'Denied'])->assertNotFound();
    }

    public function test_database_constraints_protect_default_slots_and_project_consistency(): void
    {
        $one = $this->type();
        $two = $this->type('Second');
        $field = $this->field();
        $otherProject = $this->newProject();
        foreach ([
            fn () => DB::table('project_note_types')->where('id', $two)->update(['is_default' => '1']),
            fn () => DB::table('project_note_type_fields')->insert(['project_id' => $otherProject->id, 'note_type_id' => $one, 'field_id' => $field, 'activated_at' => now(), 'active_slot' => '1']),
        ] as $operation) {
            try {
                DB::transaction($operation);
                $this->fail('A database constraint must reject this write.');
            } catch (\Illuminate\Database\QueryException) {
                $this->assertSame(1, ProjectNoteType::where('project_id', $this->project->id)->whereNotNull('is_default')->count());
            }
        }
    }

    public function test_default_switch_rolls_back_if_setting_new_default_fails(): void
    {
        $one = $this->type();
        $two = $this->type('Second');
        ProjectNoteType::updating(function ($type) use ($two) {
            if ($type->id === $two && $type->is_default) {
                throw new \RuntimeException('Simulated default failure');
            }
        });
        try {
            $service = app(ProjectNoteConfigurationService::class);
            $service->write($this->owner, $this->project,
                fn ($project) => $service->setDefault($project, $project->noteTypes()->findOrFail($two)));
            $this->fail('Expected failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated default failure', $exception->getMessage());
        } finally {
            ProjectNoteType::flushEventListeners();
        }
        $this->assertTrue(ProjectNoteType::findOrFail($one)->is_default);
        $this->assertFalse((bool) ProjectNoteType::findOrFail($two)->is_default);
    }

    public function test_reordering_period_changes_roll_back_together_on_mid_operation_failure(): void
    {
        $one = $this->field();
        $two = $this->field(['key' => 'second']);
        $type = $this->type();
        $url = "{$this->base}/note-types/{$type}/fields";
        $this->postJson($url, ['field_id' => $one])->assertCreated();
        $this->postJson($url, ['field_id' => $two])->assertCreated();
        $writes = 0;
        ProjectNoteTypeField::creating(function () use (&$writes) {
            if (++$writes === 2) {
                throw new \RuntimeException('Simulated reorder failure');
            }
        });
        try {
            $service = app(ProjectNoteConfigurationService::class);
            $service->write($this->owner, $this->project,
                fn ($project) => $service->reorderFields($project->noteTypes()->findOrFail($type), [$two, $one]));
            $this->fail('Expected failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated reorder failure', $exception->getMessage());
        } finally {
            ProjectNoteTypeField::flushEventListeners();
        }
        $this->assertDatabaseCount('project_note_type_fields', 2);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.field_id', $one)->assertJsonPath('data.1.field_id', $two);
    }

    public function test_initialization_preserves_existing_notes_evaluations_project_contract_and_preview(): void
    {
        $task = $this->postJson("{$this->base}/tasks", ['title' => 'Task'])->assertCreated()->json('data.id');
        $note = $this->postJson("{$this->base}/notes", [
            'title' => 'Historical', 'content' => 'Original content', 'noted_at' => '2026-10-01', 'task_ids' => [$task],
        ])->assertCreated()->json('data.id');
        $this->postJson("{$this->base}/tasks/{$task}/evaluations", [
            'score' => 8, 'evaluation_date' => '2026-10-01', 'note_id' => $note,
        ])->assertCreated();
        $notes = DB::table('project_progress_notes')->get()->toArray();
        $evaluations = DB::table('project_task_evaluations')->get()->toArray();
        $projectResponse = $this->getJson($this->base)->assertOk()->json();
        app(ProjectNoteConfigurationService::class)->initialize($this->owner, $this->project);
        $this->assertEquals($notes, DB::table('project_progress_notes')->get()->toArray());
        $this->assertEquals($evaluations, DB::table('project_task_evaluations')->get()->toArray());
        $this->assertSame($projectResponse, $this->getJson($this->base)->assertOk()->json());
        $this->postJson("{$this->base}/note-template/preview", ['date' => '2026-10-01', 'task_ids' => [$task]])
            ->assertOk()->assertJsonPath('data.content', 'Configuration legacy template');
    }

    public function test_small_json_decimal_steps_are_supported_without_rounding_excess_precision(): void
    {
        $id = $this->field(['step' => 0.000001, 'min' => -0.000001, 'max' => 0.000009]);
        $this->getJson("{$this->base}/custom-fields/{$id}")->assertOk()
            ->assertJsonPath('data.step', '0.000001')->assertJsonPath('data.min', '-0.000001');
        $this->patchJson("{$this->base}/custom-fields/{$id}", ['step' => 0.0000001])->assertUnprocessable();
    }

    public function test_first_value_timestamp_is_nullable_serialized_and_read_only(): void
    {
        $id = $this->field();
        $this->getJson("{$this->base}/custom-fields/{$id}")->assertOk()->assertJsonPath('data.first_value_at', null);
        $timestamp = \Carbon\CarbonImmutable::parse('2026-10-30 12:00:00.123456', 'UTC');
        ProjectCustomField::findOrFail($id)->forceFill(['first_value_at' => $timestamp])->save();
        $this->getJson("{$this->base}/custom-fields/{$id}")->assertOk()->assertJsonPath('data.first_value_at', $timestamp->toISOString());
        $this->getJson("{$this->base}/custom-fields")->assertOk()->assertJsonPath('data.0.first_value_at', $timestamp->toISOString());
        $this->patchJson("{$this->base}/custom-fields/{$id}", ['first_value_at' => '2027-01-01'])->assertUnprocessable()->assertJsonValidationErrors('first_value_at');
        $this->postJson("{$this->base}/custom-fields", ['name' => 'Invalid', 'key' => 'invalid', 'type' => 'text', 'first_value_at' => '2027-01-01'])->assertUnprocessable()->assertJsonValidationErrors('first_value_at');
        $this->assertTrue(ProjectCustomField::findOrFail($id)->first_value_at->equalTo($timestamp));
    }
}
