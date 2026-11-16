<?php

namespace Tests\Feature\Api;

use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectProgressNote;
use App\Models\ProjectTask;
use App\Models\ShoppingList;
use App\Models\User;
use App\Services\ProjectNoteConfigurationService;
use App\Services\ProjectProgressNoteService;
use App\Services\ProjectService;
use Carbon\CarbonImmutable;
use Database\Seeders\ProjectCategorySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

class ShoppingListExpenseApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $participant;
    private Project $project;
    private ShoppingList $list;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00', 'UTC'));
        $this->seed(ProjectCategorySeeder::class);
        $this->owner = User::create(['username' => 'expense-owner', 'pin' => '1234']);
        $this->participant = User::create(['username' => 'expense-participant', 'pin' => '1234']);
        $this->project = $this->configuredProject($this->owner);
        $this->list = ShoppingList::create(['created_by' => $this->owner->id, 'visibility' => 'shared',
            'status' => 'closed', 'closed_at' => '2026-11-05 22:30:00']);
        $this->list->users()->attach($this->participant->id);
        Sanctum::actingAs($this->owner);
    }

    private function configuredProject(User $owner): Project
    {
        $project = app(ProjectService::class)->create($owner, ['name' => 'Expenses', 'category_id' => ProjectCategory::firstOrFail()->id]);
        $service = app(ProjectNoteConfigurationService::class);
        $service->write($owner, $project, function ($project) use ($service) {
            $type = $service->saveType($project, ['name' => 'Shopping']);
            $amount = $service->saveField($project, ['name' => 'Amount', 'key' => 'amount', 'type' => 'number', 'unit' => 'RON']);
            $provider = $service->saveField($project, ['name' => 'Provider', 'key' => 'provider', 'type' => 'select']);
            $option = $service->saveOption($provider, ['label' => 'Shopping']);
            $service->attach($project, $type, $amount, ['required' => true]);
            $service->attach($project, $type, $provider, ['required' => true]);
            $service->saveType($project, ['is_shopping' => true, 'shopping_amount_field_id' => $amount->id,
                'shopping_provider_field_id' => $provider->id, 'shopping_provider_option_id' => $option->id], $type);
        });
        return $project;
    }

    private function url(): string
    {
        return "/api/shopping-lists/{$this->list->id}/expense";
    }

    private function payload(array $changes = []): array
    {
        return [...['project_id' => $this->project->id, 'amount' => '41.15', 'close_list' => false, 'timezone' => 'Europe/Bucharest'], ...$changes];
    }

    public function test_historical_registration_uses_closure_day_and_preserves_audit_dates_and_list(): void
    {
        $before = $this->list->fresh()->getRawOriginal();
        $result = $this->postJson($this->url(), $this->payload())->assertCreated()
            ->assertJsonPath('already_registered', false)->assertJsonPath('data.noted_at', '2026-11-06');
        $note = ProjectProgressNote::findOrFail($result->json('data.note_id'));
        $task = ProjectTask::findOrFail($result->json('data.task_id'));
        $this->assertSame('2026-11-06', $task->scheduled_date);
        $this->assertSame('completed', $task->status->value);
        $this->assertSame('one_time', $task->execution_type->value);
        $this->assertSame('2026-11-15', $note->created_at->toDateString());
        $this->assertSame('2026-11-15', $task->created_at->toDateString());
        $this->assertSame('2026-11-15', $note->configuration_captured_at->toDateString());
        $occurrence = $task->occurrences()->firstOrFail();
        $this->assertSame('done', $occurrence->status->value);
        $this->assertSame('2026-11-15', $occurrence->completed_at->toDateString());
        $this->assertSame('2026-11-06', $occurrence->events()->firstOrFail()->performed_on);
        $this->assertSame($note->id, $note->review->note_id);
        $this->assertSame('41.150000', $note->fieldValues()->where('value_type', 'number')->firstOrFail()->typedValue());
        $this->assertSame($this->project->noteTypes()->firstOrFail()->shopping_provider_option_id,
            $note->fieldValues()->where('value_type', 'select')->firstOrFail()->typedValue());
        $this->assertSame($before, $this->list->fresh()->getRawOriginal());
    }

    public function test_owner_can_close_and_register_atomically(): void
    {
        $this->list->update(['status' => 'open', 'closed_at' => null]);
        $this->postJson($this->url(), $this->payload(['close_list' => true]))->assertCreated()
            ->assertJsonPath('data.list_status', 'closed')->assertJsonPath('data.noted_at', '2026-11-15');
        $this->assertSame('closed', $this->list->fresh()->status);
        $this->assertDatabaseCount('project_tasks', 1);
        $this->assertDatabaseCount('project_progress_notes', 1);
    }

    public function test_retries_normalize_decimals_and_do_not_reclose_or_rewrite_notes(): void
    {
        $this->list->update(['status' => 'open', 'closed_at' => null]);
        $input = $this->payload(['close_list' => true]);
        $first = $this->postJson($this->url(), $input)->assertCreated();
        $closed = $this->list->fresh()->closed_at->toISOString();
        ProjectProgressNote::findOrFail($first->json('data.note_id'))->update(['content' => 'Edited later']);
        $this->postJson($this->url(), [...$input, 'amount' => '41.150000'])->assertOk()
            ->assertJsonPath('already_registered', true)->assertJsonPath('data.note_id', $first->json('data.note_id'));
        $this->assertSame($closed, $this->list->fresh()->closed_at->toISOString());
        $this->assertDatabaseCount('project_tasks', 1);
        $this->assertDatabaseCount('project_progress_notes', 1);
        $this->assertSame('Edited later', ProjectProgressNote::firstOrFail()->content);
    }

    public function test_different_amount_project_or_timezone_conflicts_globally_for_the_user(): void
    {
        $this->postJson($this->url(), $this->payload())->assertCreated();
        $other = $this->configuredProject($this->owner);
        foreach ([['amount' => '42'], ['project_id' => $other->id], ['timezone' => 'UTC'], ['close_list' => true]] as $change) {
            $this->postJson($this->url(), $this->payload($change))->assertConflict();
        }
        $this->assertDatabaseCount('project_tasks', 1);
        $this->assertDatabaseCount('project_progress_notes', 1);
    }

    public function test_shared_list_registrations_are_independent_and_participant_can_use_shared_project(): void
    {
        $this->postJson($this->url(), $this->payload())->assertCreated();
        $other = $this->configuredProject($this->participant);
        Sanctum::actingAs($this->participant);
        $this->postJson($this->url(), $this->payload(['project_id' => $other->id]))->assertCreated();
        $this->assertDatabaseCount('project_tasks', 2);
        $this->assertDatabaseCount('project_progress_notes', 2);
        $this->assertDatabaseHas('project_progress_notes', ['project_id' => $other->id, 'created_by' => $this->participant->id]);
        $this->assertDatabaseHas('project_progress_notes', ['project_id' => $this->project->id, 'created_by' => $this->owner->id]);
    }

    public function test_participant_can_register_in_accessible_shared_project_but_cannot_close_list(): void
    {
        $this->project->collaborators()->create(['user_id' => $this->participant->id, 'sharing_mode' => 'all_tasks']);
        Sanctum::actingAs($this->participant);
        $this->list->update(['status' => 'open', 'closed_at' => null]);
        $this->postJson($this->url(), $this->payload(['close_list' => true]))->assertForbidden();
        $this->assertSame('open', $this->list->fresh()->status);
        $this->assertDatabaseCount('project_tasks', 0);
        $this->list->update(['status' => 'closed', 'closed_at' => now()]);
        $this->postJson($this->url(), $this->payload())->assertCreated();
        $this->project->collaborators()->delete();
        $this->postJson($this->url(), $this->payload())->assertNotFound();
        $this->assertDatabaseCount('project_tasks', 1);
    }

    public function test_public_viewer_and_former_participant_cannot_register(): void
    {
        $this->project->collaborators()->create(['user_id' => $this->participant->id, 'sharing_mode' => 'all_tasks']);
        $this->list->users()->detach($this->participant->id);
        Sanctum::actingAs($this->participant);
        $this->postJson($this->url(), $this->payload())->assertForbidden();
        $this->list->update(['visibility' => 'public']);
        $this->postJson($this->url(), $this->payload())->assertForbidden();
        $this->list->users()->attach($this->participant->id);
        $this->list->update(['visibility' => 'private']);
        $this->postJson($this->url(), $this->payload())->assertForbidden();
        $this->assertDatabaseCount('project_tasks', 0);
    }

    public function test_inaccessible_inactive_and_unconfigured_projects_are_rejected(): void
    {
        Sanctum::actingAs($this->participant);
        $this->postJson($this->url(), $this->payload())->assertNotFound();
        Sanctum::actingAs($this->owner);
        $this->project->forceFill(['status' => 'completed'])->save();
        $this->postJson($this->url(), $this->payload())->assertForbidden();
        $this->project->forceFill(['status' => 'active'])->save();
        $this->project->noteTypes()->update(['is_shopping' => null]);
        $this->postJson($this->url(), $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('project_tasks', 0);
    }

    public function test_current_configuration_is_revalidated_but_committed_replays_remain_idempotent(): void
    {
        $this->postJson($this->url(), $this->payload())->assertCreated();
        $type = $this->project->noteTypes()->firstOrFail();
        DB::table('project_custom_field_options')->where('id', $type->shopping_provider_option_id)->update(['archived_at' => now()]);
        $this->postJson($this->url(), $this->payload())->assertOk();
        $newList = $this->list->replicate();
        $newList->save();
        $this->postJson("/api/shopping-lists/{$newList->id}/expense", $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('project_tasks', 1);
    }

    public function test_failure_after_task_done_and_review_rolls_back_everything_including_closure(): void
    {
        $this->list->update(['status' => 'open', 'closed_at' => null]);
        $real = app(ProjectProgressNoteService::class);
        $this->mock(ProjectProgressNoteService::class, function (MockInterface $mock) use ($real) {
            $mock->shouldReceive('create')->once()->andReturnUsing(function (...$args) use ($real) {
                $real->create(...$args);
                throw ValidationException::withMessages(['amount' => 'Injected post-review failure.']);
            });
        });
        $this->postJson($this->url(), $this->payload(['close_list' => true]))->assertUnprocessable();
        foreach (['project_tasks', 'project_progress_notes', 'project_task_occurrences', 'project_task_completion_events',
            'project_task_execution_requests', 'project_task_reviews', 'project_progress_note_values'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame('open', $this->list->fresh()->status);
        $this->assertNull($this->list->fresh()->closed_at);
        $this->assertSame(0, $this->project->customFields()->whereNotNull('first_value_at')->count());
    }

    public function test_missing_additional_required_field_rolls_back_closure_and_created_task(): void
    {
        $service = app(ProjectNoteConfigurationService::class);
        $service->write($this->owner, $this->project, function ($project) use ($service) {
            $field = $service->saveField($project, ['name' => 'Required text', 'key' => 'required_text', 'type' => 'text']);
            $service->attach($project, $project->noteTypes()->firstOrFail(), $field, ['required' => true]);
        });
        $this->list->update(['status' => 'open', 'closed_at' => null]);
        $this->postJson($this->url(), $this->payload(['close_list' => true]))->assertUnprocessable();
        $this->assertSame('open', $this->list->fresh()->status);
        $this->assertDatabaseCount('project_tasks', 0);
        $this->assertDatabaseCount('project_progress_notes', 0);
    }

    public function test_soft_deletion_keeps_the_slot_and_database_constraint_prevents_duplicate_writes(): void
    {
        $this->postJson($this->url(), $this->payload())->assertCreated();
        $note = ProjectProgressNote::firstOrFail();
        app(ProjectProgressNoteService::class)->delete($this->owner, $this->project, $note);
        $this->postJson($this->url(), $this->payload())->assertOk()->assertJsonPath('data.note_deleted', true);
        $this->assertDatabaseCount('project_tasks', 1);
        $this->expectException(QueryException::class);
        DB::table('project_progress_notes')->insert(['project_id' => $this->project->id, 'created_by' => $this->owner->id,
            'title' => 'Duplicate', 'content' => 'Duplicate', 'noted_at' => '2026-11-06',
            'shopping_list_id' => $this->list->id, 'shopping_request_hash' => str_repeat('a', 64)]);
    }

    public function test_input_validation_zero_decimal_precision_and_no_missing_date_fallback(): void
    {
        foreach ([['timezone' => '+02:00'], ['timezone' => 'Invalid/Zone'], ['amount' => '41.1234567'],
            ['amount' => '-1'], ['user_id' => $this->participant->id]] as $change) {
            $this->postJson($this->url(), $this->payload($change))->assertUnprocessable();
        }
        $this->list->update(['closed_at' => null]);
        $this->postJson($this->url(), $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('project_tasks', 0);
        $this->list->update(['closed_at' => '2026-11-05 22:30:00']);
        $this->postJson($this->url(), $this->payload(['amount' => 0]))->assertCreated();
        $this->assertDatabaseHas('project_progress_note_values', ['number_value' => '0.000000']);
    }
    public function test_generic_notes_keep_null_origins_and_client_cannot_assign_expense_metadata(): void
    {
        for ($index = 0; $index < 2; $index++) {
            $this->postJson("/api/projects/{$this->project->id}/notes", [
                'title' => 'Generic Note', 'content' => 'Unchanged behavior', 'noted_at' => '2026-11-15',
                'shopping_list_id' => $this->list->id, 'shopping_request_hash' => str_repeat('b', 64),
            ])->assertCreated();
        }
        $this->assertSame(2, ProjectProgressNote::whereNull('shopping_list_id')->count());
        $this->assertDatabaseCount('project_tasks', 0);
        $this->postJson($this->url(), $this->payload())->assertCreated();
        $this->assertDatabaseCount('project_progress_notes', 3);
    }

    public function test_origin_migration_rolls_back_and_reapplies_without_changing_generic_notes(): void
    {
        $this->project->notes()->forceCreate(['created_by' => $this->owner->id,
            'title' => 'Existing Note', 'content' => 'Unchanged', 'noted_at' => '2026-11-01']);
        $migration = require database_path('migrations/2026_11_15_000002_add_shopping_origin_to_project_progress_notes.php');
        $migration->down();
        $this->assertDatabaseCount('project_progress_notes', 1);
        $migration->up();
        $this->postJson($this->url(), $this->payload())->assertCreated();
        $this->assertDatabaseCount('project_progress_notes', 2);
    }

}
