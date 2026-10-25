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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectTaskExecutionApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProjectCategorySeeder::class);
        $this->owner = User::create(['username' => 'owner', 'pin' => '1234']);
        $this->project = app(ProjectService::class)->create($this->owner, [
            'name' => 'Project', 'category_id' => ProjectCategory::firstOrFail()->id,
        ]);
        Sanctum::actingAs($this->owner);
    }

    private function task(string $type = 'one_time', array $attributes = []): ProjectTask
    {
        return app(ProjectTaskService::class)->create($this->owner, $this->project, [
            'title' => 'Task', 'execution_type' => $type, ...$attributes,
        ]);
    }

    private function url(ProjectTask $task): string
    {
        return "/api/projects/{$this->project->id}/tasks/{$task->id}";
    }

    public function test_one_time_done_and_collaborator_undo_preserve_business_dates_history_and_schedule(): void
    {
        $task = $this->task('one_time', ['scheduled_date' => '2020-01-03', 'due_date' => '2020-01-05']);
        $member = User::create(['username' => 'member', 'pin' => '1234']);
        app(ProjectSharingService::class)->add($this->owner, $this->project, ['user_id' => $member->id, 'sharing_mode' => 'all_tasks']);
        $url = $this->url($task);
        $done = $this->postJson($url.'/occurrences/single/done', [
            'request_id' => 'single-done', 'lock_version' => 0, 'performed_on' => '2020-01-04',
        ])->assertOk()->assertJsonPath('data.status', 'done')->assertJsonPath('data.lock_version', 1)
            ->assertJsonPath('data.scheduled_date', '2020-01-03')->assertJsonPath('data.due_date', '2020-01-05')
            ->assertJsonPath('data.due_time', null);
        $this->assertSame(ProjectStatus::COMPLETED, $task->fresh()->status);
        $this->patchJson($url, ['status' => 'active'])->assertConflict();
        Sanctum::actingAs($member);
        $this->postJson($url.'/occurrences/single/undo', ['request_id' => 'member-undo', 'lock_version' => 1])
            ->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.completed_by', null)
            ->assertJsonPath('data.lock_version', 2);
        $this->assertSame(ProjectStatus::ACTIVE, $task->fresh()->status);
        $this->getJson($url.'/occurrences/single')->assertOk()->assertJsonPath('data.id', $done->json('data.id'));
        $history = $this->getJson($url.'/execution-history?from=2020-01-04&to=2020-01-04')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('data.0.action', 'done')
            ->assertJsonPath('data.1.action', 'undo')->assertJsonPath('data.1.actor_id', $member->id)
            ->assertJsonPath('data.1.performed_on', '2020-01-04');
        $this->assertSame($history->json('data.0.id'), $history->json('data.1.reverses_event_id'));
        $this->assertNotSame('2020-01-04', substr($history->json('data.0.recorded_at'), 0, 10));
        $this->postJson($url.'/occurrences/single/skip', ['request_id' => 'skip', 'lock_version' => 2])
            ->assertOk()->assertJsonPath('data.status', 'skipped');
        $this->assertDatabaseCount('project_task_occurrences', 1);
    }

    public function test_persistent_execution_on_two_dates_leaves_task_active_until_explicit_completion(): void
    {
        $task = $this->task('persistent');
        $url = $this->url($task);
        foreach (['2019-01-01', '2019-01-02'] as $date) {
            $this->postJson($url.'/occurrences/'.$date.'/done', ['request_id' => $date, 'lock_version' => 0])
                ->assertOk()->assertJsonPath('data.scheduled_date', $date)->assertJsonPath('data.status', 'done');
        }
        $this->assertSame(ProjectStatus::ACTIVE, $task->fresh()->status);
        $this->getJson($url.'/occurrences?from=2019-01-01&to=2019-01-02')->assertOk()->assertJsonPath('meta.total', 2);
        $this->postJson($url.'/complete')->assertOk()->assertJsonPath('data.status', 'completed');
        $this->postJson($url.'/occurrences/2019-01-03/done', ['request_id' => 'blocked', 'lock_version' => 0])->assertForbidden();
        $this->assertDatabaseCount('project_task_occurrences', 2);
    }

    public function test_hidden_tasks_and_removed_collaborators_cannot_execute_or_read_history(): void
    {
        $task = $this->task('persistent');
        $member = User::create(['username' => 'member', 'pin' => '1234', 'is_admin' => true]);
        $sharing = app(ProjectSharingService::class);
        $membership = $sharing->add($this->owner, $this->project, ['user_id' => $member->id, 'sharing_mode' => 'selected_tasks']);
        Sanctum::actingAs($member);
        $url = $this->url($task);
        $this->postJson($url.'/occurrences/2020-01-01/done', ['request_id' => 'denied', 'lock_version' => 0])->assertForbidden();
        $this->getJson($url.'/execution-history?from=2020-01-01&to=2020-01-01')->assertForbidden();
        $this->assertDatabaseCount('project_task_occurrences', 0);
        $sharing->setGrants($this->owner, $this->project, $membership, [$task->id]);
        $this->postJson($url.'/occurrences/2020-01-01/done', ['request_id' => 'allowed', 'lock_version' => 0])->assertOk();
        $sharing->remove($this->owner, $this->project, $membership);
        $this->postJson($url.'/occurrences/2020-01-01/undo', ['request_id' => 'removed', 'lock_version' => 1])->assertForbidden();
        $this->assertDatabaseCount('project_task_completion_events', 1);
    }

    public function test_duplicate_done_receipts_and_stale_requests_do_not_duplicate_or_reapply_transitions(): void
    {
        $task = $this->task('persistent');
        $url = $this->url($task).'/occurrences/2020-01-01';
        $input = ['request_id' => 'original', 'lock_version' => 0];
        $this->postJson($url.'/done', $input)->assertOk();
        $this->postJson($url.'/done', $input)->assertOk()->assertJsonPath('data.lock_version', 1);
        $noop = ['request_id' => 'noop', 'lock_version' => 0];
        $this->postJson($url.'/done', $noop)->assertOk();
        $this->assertDatabaseCount('project_task_completion_events', 1);
        $this->postJson($url.'/undo', ['request_id' => 'stale', 'lock_version' => 0])->assertConflict();
        $this->postJson($url.'/undo', ['request_id' => 'undo', 'lock_version' => 1])->assertOk();
        $this->postJson($url.'/done', $noop)->assertOk()->assertJsonPath('data.status', 'pending');
        $this->postJson($url.'/done', $input)->assertOk()->assertJsonPath('data.lock_version', 2);
        $this->postJson($url.'/done', ['request_id' => 'original', 'lock_version' => 2])->assertConflict();
        $this->assertDatabaseCount('project_task_completion_events', 2);
        $this->assertDatabaseCount('project_task_occurrences', 1);
        $this->assertSame(ProjectStatus::ACTIVE, $task->fresh()->status);
    }

    public function test_completed_and_archived_projects_reject_execution_mutations(): void
    {
        foreach ([ProjectStatus::COMPLETED, ProjectStatus::ARCHIVED] as $status) {
            $task = $this->task();
            app(ProjectService::class)->changeStatus($this->owner, $this->project, $status);
            $this->postJson($this->url($task).'/occurrences/single/done', [
                'request_id' => $status->value, 'lock_version' => 0,
            ])->assertForbidden();
            app(ProjectService::class)->changeStatus($this->owner, $this->project, ProjectStatus::ACTIVE);
        }
        $this->assertDatabaseCount('project_task_occurrences', 0);
        $this->assertDatabaseCount('project_task_completion_events', 0);
    }
}
