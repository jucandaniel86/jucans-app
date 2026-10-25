<?php

namespace Tests\Feature\Api;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectTask;
use App\Models\User;
use App\Services\ProjectService;
use App\Services\ProjectSharingService;
use App\Services\ProjectTaskService;
use Database\Seeders\ProjectCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectProgressApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-01-01 00:00:00', 'UTC'));
        $this->seed(ProjectCategorySeeder::class);
        $this->owner = User::create(['username' => 'owner', 'pin' => '1234']);
        $this->project = app(ProjectService::class)->create($this->owner, [
            'name' => 'Project', 'category_id' => ProjectCategory::firstOrFail()->id,
        ]);
        Sanctum::actingAs($this->owner);
    }

    private function base(): string
    {
        return '/api/projects/'.$this->project->id;
    }

    private function task(array $attributes = []): ProjectTask
    {
        return app(ProjectTaskService::class)->create($this->owner, $this->project, $attributes + ['title' => 'Task']);
    }

    private function action(ProjectTask $task, string $key, string $action, int $version = 0): void
    {
        $this->postJson($this->base()."/tasks/{$task->id}/occurrences/{$key}/{$action}", [
            'request_id' => "{$task->id}-{$key}-{$action}-{$version}", 'lock_version' => $version,
        ])->assertOk();
    }

    private function score(ProjectTask $task, int $score, string $date = '2026-01-02', ?int $noteId = null): int
    {
        return $this->postJson($this->base()."/tasks/{$task->id}/evaluations", [
            'evaluation_date' => $date, 'score' => $score, 'note_id' => $noteId,
        ])->assertCreated()->json('data.id');
    }

    public function test_daily_counts_recurrence_skip_one_time_and_persistent_without_writes(): void
    {
        $recurring = $this->task(['execution_type' => 'recurring']);
        $this->postJson($this->base()."/tasks/{$recurring->id}/schedule", ['recurrence_type' => 'daily'])->assertCreated();
        $single = $this->task(['scheduled_date' => '2026-01-02']);
        $this->task(['scheduled_date' => '2026-01-01']);
        $persistent = $this->task(['execution_type' => 'persistent']);
        $this->travelTo(Carbon::parse('2026-01-02 12:00:00', 'UTC'));
        $this->action($recurring, '2026-01-02', 'skip');
        $this->action($single, 'single', 'done');
        $this->action($persistent, '2026-01-02', 'done');
        $url = $this->base().'/progress/summary?date=2026-01-02';
        $this->getJson($url)->assertOk()->assertJsonPath('data.daily.scheduled_occurrences', 2)
            ->assertJsonPath('data.daily.completed_scheduled_occurrences', 1)
            ->assertJsonPath('data.daily.skipped_scheduled_occurrences', 1)
            ->assertJsonPath('data.daily.completion_percentage', 50)->assertJsonPath('data.daily.done_occurrences', 2);
        $this->action($single, 'single', 'undo', 1);
        $this->getJson($url)->assertOk()->assertJsonPath('data.daily.completed_scheduled_occurrences', 0)
            ->assertJsonPath('data.daily.done_occurrences', 1);
        $this->assertDatabaseCount('project_task_occurrences', 3);
        $this->getJson($this->base().'/progress/summary?date=2025-12-31')->assertOk()
            ->assertJsonPath('data.daily.completion_percentage', null);
    }

    public function test_scores_exclude_withdrawn_suspended_deleted_and_preserve_missing_days(): void
    {
        $a = $this->task();
        $b = $this->task();
        $this->score($a, 4);
        $this->score($b, 9);
        $withdrawn = $this->score($a, 10, '2026-01-03');
        $this->deleteJson($this->base()."/tasks/{$a->id}/evaluations/{$withdrawn}", ['lock_version' => 0])->assertNoContent();
        $note = $this->postJson($this->base().'/notes', [
            'title' => 'Note', 'content' => 'Text', 'noted_at' => '2026-01-03', 'task_ids' => [$b->id],
        ])->assertCreated()->json('data.id');
        $this->score($b, 8, '2026-01-03', $note);
        $this->deleteJson($this->base().'/notes/'.$note)->assertNoContent();
        $deleted = $this->task();
        $this->score($deleted, 1);
        $this->deleteJson($this->base().'/tasks/'.$deleted->id)->assertNoContent();
        $url = $this->base().'/progress/timeline?range=7&date=2026-01-07';
        $this->getJson($url)->assertOk()->assertJsonCount(7, 'data.series')
            ->assertJsonPath('data.series.1.evaluation_average', 6.5)->assertJsonPath('data.series.1.evaluation_count', 2)
            ->assertJsonPath('data.series.2.evaluation_average', null)->assertJsonPath('data.series.2.evaluation_count', 0);
        $this->getJson($this->base()."/progress/tasks/{$a->id}?range=7&date=2026-01-07")->assertOk()
            ->assertJsonCount(1, 'data.evaluations')->assertJsonPath('data.evaluations.0.score', 4);
        $this->postJson($this->base().'/notes/'.$note.'/restore')->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('data.series.2.evaluation_average', 8);
    }

    public function test_collaborator_excludes_hidden_tasks_and_note_scores_and_loses_removed_access(): void
    {
        $visible = $this->task(['scheduled_date' => '2026-01-02']);
        $hidden = $this->task(['scheduled_date' => '2026-01-02']);
        $note = $this->postJson($this->base().'/notes', [
            'title' => 'Private', 'content' => 'Text', 'noted_at' => '2026-01-02', 'task_ids' => [$visible->id, $hidden->id],
        ])->assertCreated()->json('data.id');
        $this->score($visible, 9, '2026-01-02', $note);
        $this->score($hidden, 10);
        $reader = User::create(['username' => 'reader', 'pin' => '1234']);
        $sharing = app(ProjectSharingService::class);
        $member = $sharing->add($this->owner, $this->project, ['user_id' => $reader->id, 'sharing_mode' => 'selected_tasks', 'task_ids' => [$visible->id]]);
        Sanctum::actingAs($reader);
        $this->getJson($this->base().'/progress/summary?date=2026-01-02')->assertOk()
            ->assertJsonPath('data.completion.total_tasks', 1)->assertJsonPath('data.daily.scheduled_occurrences', 1)
            ->assertJsonPath('data.daily.evaluation_count', 0);
        $this->getJson($this->base().'/progress/timeline?range=7&date=2026-01-07')->assertOk()
            ->assertJsonPath('data.series.1.evaluation_count', 0);
        $this->getJson($this->base().'/progress/summary?task_id='.$hidden->id)->assertNotFound();
        $this->getJson($this->base().'/progress/tasks/'.$hidden->id)->assertForbidden();
        $this->getJson($this->base().'/progress/tasks/'.$visible->id)->assertOk();
        $sharing->remove($this->owner, $this->project, $member);
        foreach (['summary', 'timeline', 'tasks/'.$visible->id] as $suffix) {
            $this->getJson($this->base().'/progress/'.$suffix)->assertForbidden();
        }
    }

    public function test_completion_counts_entities_and_excludes_archived_deleted_workload(): void
    {
        $single = $this->task();
        $this->action($single, 'single', 'done');
        $persistent = $this->task(['execution_type' => 'persistent']);
        $this->action($persistent, '2026-01-01', 'done');
        $this->action($persistent, '2026-01-02', 'done');
        $this->task(['status' => 'archived']);
        $deleted = $this->task();
        $this->deleteJson($this->base().'/tasks/'.$deleted->id)->assertNoContent();
        $this->getJson($this->base().'/progress/summary')->assertOk()
            ->assertJsonPath('data.completion.total_visible_tasks', 3)->assertJsonPath('data.completion.total_tasks', 2)
            ->assertJsonPath('data.completion.completed_tasks', 1)->assertJsonPath('data.completion.remaining_tasks', 1)
            ->assertJsonPath('data.completion.archived_tasks', 1)
            ->assertJsonPath('data.completion.by_execution_type.persistent.completed_tasks', 0);
        $this->getJson($this->base().'/progress/summary?execution_type=persistent')->assertOk()
            ->assertJsonPath('data.completion.total_tasks', 1);
        $this->getJson($this->base().'/progress/tasks/'.$deleted->id)->assertNotFound();
        $this->getJson($this->base().'/progress/summary?range=365')->assertUnprocessable();
        $this->getJson($this->base().'/progress/summary?date=invalid')->assertUnprocessable();
    }

    public function test_history_respects_schedule_versions_lifecycle_and_bounded_all_pages(): void
    {
        $task = $this->task(['execution_type' => 'recurring']);
        $url = $this->base().'/tasks/'.$task->id;
        $this->postJson($url.'/schedule', ['recurrence_type' => 'daily'])->assertCreated();
        $this->action($task, '2026-01-02', 'done');
        $this->travelTo(Carbon::parse('2026-01-03 12:00:00', 'UTC'));
        $this->patchJson($url.'/schedule', ['recurrence_type' => 'every_n_days', 'interval_days' => 2])->assertOk();
        app(ProjectService::class)->changeStatus($this->owner, $this->project, ProjectStatus::ARCHIVED);
        $this->travelTo(Carbon::parse('2026-01-05 12:00:00', 'UTC'));
        app(ProjectService::class)->changeStatus($this->owner, $this->project, ProjectStatus::ACTIVE);
        $this->deleteJson($url)->assertNoContent();
        $this->travelTo(Carbon::parse('2026-01-07 12:00:00', 'UTC'));
        $this->postJson($url.'/restore')->assertOk();
        $this->getJson($this->base().'/progress/timeline?range=7&date=2026-01-07')->assertOk()
            ->assertJsonPath('data.series.1.scheduled_occurrences', 1)->assertJsonPath('data.series.1.completed_scheduled_occurrences', 1)
            ->assertJsonPath('data.series.2.scheduled_occurrences', 1)->assertJsonPath('data.series.3.scheduled_occurrences', 0)
            ->assertJsonPath('data.series.4.scheduled_occurrences', 0)->assertJsonPath('data.series.6.scheduled_occurrences', 0);
        $this->patchJson($url, ['status' => 'archived'])->assertOk();
        $this->getJson($this->base().'/progress/tasks/'.$task->id.'?range=7&date=2026-01-07')->assertOk()
            ->assertJsonCount(1, 'data.executions')->assertJsonPath('data.series.1.completed_scheduled_occurrences', 1)
            ->assertJsonPath('data.series.0.scheduled_occurrences', 0);
        $this->getJson($this->base().'/progress/timeline?range=all&date=2026-07-01')->assertOk()
            ->assertJsonCount(90, 'data.series')->assertJsonPath('data.range.next_page', 2);
        $this->getJson($this->base().'/progress/timeline?range=all&date=2026-07-01&page=3')->assertOk()
            ->assertJsonPath('data.range.from', '2026-01-01')->assertJsonPath('data.range.next_page', null);
        $this->assertDatabaseCount('project_task_occurrences', 1);
        $this->assertDatabaseCount('project_task_completion_events', 1);
    }
}
