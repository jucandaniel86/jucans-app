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

class ProjectDailyTasksApiTest extends TestCase
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
            'name' => 'Home tasks', 'category_id' => ProjectCategory::firstOrFail()->id,
        ]);
        Sanctum::actingAs($this->owner);
    }

    private function task(array $attributes = []): ProjectTask
    {
        return app(ProjectTaskService::class)->create($this->owner, $this->project, $attributes + ['title' => 'Task']);
    }

    private function url(ProjectTask $task): string
    {
        return "/api/projects/{$task->project_id}/tasks/{$task->id}";
    }

    public function test_daily_groups_counts_pagination_and_done_undo_without_duplicate_or_historical_rows(): void
    {
        $overdue = $this->task(['scheduled_date' => '2026-01-01', 'priority' => 'urgent']);
        $today = $this->task(['scheduled_date' => '2026-01-02', 'priority' => 'high']);
        $persistent = $this->task(['execution_type' => 'persistent']);
        $scheduledPersistent = $this->task(['execution_type' => 'persistent', 'scheduled_date' => '2026-01-02']);
        $skipped = $this->task(['scheduled_date' => '2026-01-02']);
        $this->task(['scheduled_date' => '2026-01-02', 'show_in_daily_widget' => false]);
        $this->task(['scheduled_date' => '2026-01-02', 'status' => 'archived']);
        $deleted = $this->task(['scheduled_date' => '2026-01-02']);
        app(ProjectTaskService::class)->delete($this->owner, $this->project, $deleted);
        $this->travelTo(Carbon::parse('2026-01-02 12:00:00', 'UTC'));
        $this->postJson($this->url($skipped).'/occurrences/single/skip', ['request_id' => 'skip', 'lock_version' => 0])->assertOk();
        $before = $this->getJson('/api/projects/daily-tasks')->assertOk()
            ->assertJsonPath('data.date', '2026-01-02')->assertJsonPath('data.summary.scheduled', 3)
            ->assertJsonPath('data.summary.completed', 0)->assertJsonPath('data.counts.overdue', 1)
            ->assertJsonPath('data.counts.today', 2)->assertJsonPath('data.counts.persistent', 2);
        $this->assertSame($overdue->id, $before->json('data.rows.0.task.id'));
        $this->getJson('/api/projects/daily-tasks?per_page=2')->assertOk()->assertJsonCount(2, 'data.rows')->assertJsonPath('data.pagination.total', 5);
        $this->assertDatabaseCount('project_task_occurrences', 1); // Reads do not materialize schedules.
        $this->postJson($this->url($today).'/occurrences/single/done', ['request_id' => 'done', 'lock_version' => 0])->assertOk();
        $active = $this->getJson('/api/projects/daily-tasks')->assertOk()->assertJsonPath('data.summary.completed', 1)->assertJsonPath('data.counts.completed', 1);
        $this->assertNotContains($today->id, collect($active->json('data.rows'))->pluck('task.id')->all());
        $this->getJson('/api/projects/daily-tasks?section=completed')->assertOk()->assertJsonPath('data.rows.0.task.id', $today->id);
        $this->postJson($this->url($today).'/occurrences/single/undo', ['request_id' => 'undo', 'lock_version' => 1])->assertOk();
        $this->getJson('/api/projects/daily-tasks')->assertOk()->assertJsonPath('data.summary.completed', 0)->assertJsonPath('data.counts.completed', 0);
        // An existing snapshot does not keep a rescheduled definition overdue.
        $this->postJson($this->url($overdue).'/occurrences/single/skip', ['request_id' => 'old', 'lock_version' => 0])->assertOk();
        $this->patchJson($this->url($overdue), ['scheduled_date' => '2026-01-05'])->assertOk();
        $this->getJson('/api/projects/daily-tasks')->assertOk()->assertJsonPath('data.counts.overdue', 0);
        $this->postJson($this->url($persistent).'/complete')->assertOk();
        $this->getJson('/api/projects/daily-tasks')->assertOk()->assertJsonPath('data.counts.persistent', 1);
    }

    public function test_recurrence_eligibility_and_visibility_respect_versions_lifecycle_and_removed_membership(): void
    {
        $recurring = $this->task(['execution_type' => 'recurring']);
        $this->postJson($this->url($recurring).'/schedule', ['recurrence_type' => 'daily'])->assertCreated();
        $hidden = $this->task(['scheduled_date' => '2026-01-02']);
        $reader = User::create(['username' => 'reader', 'pin' => '1234']);
        $member = app(ProjectSharingService::class)->add($this->owner, $this->project, ['user_id' => $reader->id, 'sharing_mode' => 'selected_tasks', 'task_ids' => [$recurring->id]]);
        $this->travelTo(Carbon::parse('2026-01-02 12:00:00', 'UTC'));
        // Replacement begins tomorrow. Yesterday's missed recurrence is never overdue today.
        $this->patchJson($this->url($recurring).'/schedule', ['recurrence_type' => 'monthly', 'month_day' => 9])->assertOk();
        Sanctum::actingAs($reader);
        $this->getJson('/api/projects/daily-tasks')->assertOk()->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.rows.0.task.id', $recurring->id)->assertJsonPath('data.rows.0.section', 'today')
            ->assertJsonPath('data.counts.overdue', 0)->assertJsonPath('data.summary.scheduled', 1);
        $this->travelTo(Carbon::parse('2026-01-03 12:00:00', 'UTC'));
        $this->getJson('/api/projects/daily-tasks')->assertOk()->assertJsonCount(0, 'data.rows')->assertJsonPath('data.summary.scheduled', 0);
        app(ProjectService::class)->changeStatus($this->owner, $this->project, ProjectStatus::ARCHIVED);
        $this->travelTo(Carbon::parse('2026-01-09 12:00:00', 'UTC'));
        app(ProjectService::class)->changeStatus($this->owner, $this->project, ProjectStatus::ACTIVE);
        // Reactivation after today's beginning-of-day boundary does not invent an occurrence.
        $this->getJson('/api/projects/daily-tasks')->assertOk()->assertJsonCount(0, 'data.rows');
        $this->travelTo(Carbon::parse('2026-02-09 12:00:00', 'UTC'));
        $this->getJson('/api/projects/daily-tasks')->assertOk()->assertJsonCount(1, 'data.rows');
        app(ProjectTaskService::class)->delete($this->owner, $this->project, $recurring);
        app(ProjectTaskService::class)->restore($this->owner, $this->project, $recurring);
        $this->travelTo(Carbon::parse('2026-03-09 12:00:00', 'UTC'));
        $this->getJson('/api/projects/daily-tasks')->assertOk()->assertJsonCount(1, 'data.rows');
        app(ProjectSharingService::class)->remove($this->owner, $this->project, $member);
        $this->getJson('/api/projects/daily-tasks')->assertOk()->assertJsonCount(0, 'data.rows')->assertJsonPath('data.summary.scheduled', 0)->assertJsonCount(0, 'data.projects');
    }
}
