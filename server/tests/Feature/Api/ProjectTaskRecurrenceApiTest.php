<?php

namespace Tests\Feature\Api;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectTask;
use App\Models\User;
use App\Services\ProjectService;
use App\Services\ProjectTaskRecurrenceService;
use App\Services\ProjectTaskService;
use Database\Seeders\ProjectCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectTaskRecurrenceApiTest extends TestCase
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

    private function task(): ProjectTask
    {
        return app(ProjectTaskService::class)->create($this->owner, $this->project, ['title' => 'Recurring', 'execution_type' => 'recurring']);
    }

    private function url(ProjectTask $task): string
    {
        return "/api/projects/{$this->project->id}/tasks/{$task->id}";
    }

    private function dates(ProjectTask $task, string $from, string $to): array
    {
        return array_column($this->getJson($this->url($task)."/occurrences?from={$from}&to={$to}&per_page=100")
            ->assertOk()->json('data'), 'scheduled_date');
    }

    public function test_backdated_monthly_start_does_not_backdate_schedule_validity_or_project_activity(): void
    {
        $rule = ['recurrence_type' => 'monthly', 'month_day' => 9,
            'start_date' => '2026-10-08', 'end_date' => '2029-04-25'];

        // A rule actually established before October 9 remains valid after a later edit.
        $this->travelTo(Carbon::parse('2026-10-08 12:00:00', 'UTC'));
        $existing = $this->task();
        $this->postJson($this->url($existing).'/schedule', $rule)->assertCreated()
            ->assertJsonPath('data.effective_from', '2026-10-08');
        $this->travelTo(Carbon::parse('2026-10-25 12:00:00', 'UTC'));
        $this->patchJson($this->url($existing).'/schedule', $rule)->assertOk()
            ->assertJsonPath('data.effective_from', '2026-10-26');
        $this->getJson($this->url($existing).'/occurrences/2026-10-09')->assertOk()
            ->assertJsonPath('data.is_recorded', false);

        // The same bounds entered on October 25 do not create an October 9 occurrence.
        $late = $this->task();
        $this->postJson($this->url($late).'/schedule', $rule)->assertCreated()
            ->assertJsonPath('data.effective_from', '2026-10-25')
            ->assertJsonPath('data.anchor_date', '2026-10-08');
        $this->patchJson($this->url($late).'/schedule', $rule)->assertOk()
            ->assertJsonPath('data.effective_from', '2026-10-26');
        $this->getJson($this->url($late).'/occurrences/2026-10-09')->assertNotFound();
        $this->getJson($this->url($late).'/occurrences/2026-11-09')->assertOk()
            ->assertJsonPath('data.is_recorded', false);
        $late->load(['project.statusPeriods', 'deletionPeriods']);
        $calendar = app(ProjectTaskRecurrenceService::class);
        $this->assertTrue($calendar->eligibleDay($late, \Carbon\CarbonImmutable::parse('2026-10-09', 'UTC')));
        $this->assertCount(0, $late->deletionPeriods); // Rejection above is schedule validity alone.

        // Project creation independently gates days before its first active interval.
        $newProject = app(ProjectService::class)->create($this->owner, [
            'name' => 'New project', 'category_id' => $this->project->category_id,
        ]);
        $newTask = app(ProjectTaskService::class)->create($this->owner, $newProject, ['title' => 'Monthly', 'execution_type' => 'recurring']);
        $newTask->load(['project.statusPeriods', 'deletionPeriods']);
        $this->assertFalse($calendar->eligibleDay($newTask, \Carbon\CarbonImmutable::parse('2026-10-09', 'UTC')));
        $this->assertDatabaseCount('project_task_occurrences', 0);
    }

    public function test_daily_and_selected_weekdays_are_calculated_without_persisting_rows(): void
    {
        $daily = $this->task();
        $this->postJson($this->url($daily).'/schedule', [
            'recurrence_type' => 'daily', 'start_date' => '2026-01-02', 'end_date' => '2026-01-04', 'scheduled_time' => '09:15',
        ])->assertCreated();
        $this->assertSame(['2026-01-02', '2026-01-03', '2026-01-04'], $this->dates($daily, '2026-01-01', '2026-01-07'));
        $this->getJson($this->url($daily).'/occurrences/2026-01-02')->assertOk()
            ->assertJsonPath('data.scheduled_time', '09:15')->assertJsonPath('data.status', 'pending')->assertJsonPath('data.is_recorded', false);

        $weekdays = $this->task();
        $this->postJson($this->url($weekdays).'/schedule', ['recurrence_type' => 'weekdays', 'weekdays' => [1, 3]])->assertCreated();
        $this->assertSame(['2026-01-05', '2026-01-07'], $this->dates($weekdays, '2026-01-01', '2026-01-07'));
        $this->postJson($this->url($weekdays).'/occurrences/2026-01-06/done', ['request_id' => 'invalid', 'lock_version' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('occurrence_key');
        $this->assertDatabaseCount('project_task_occurrences', 0);
    }

    public function test_every_n_days_retains_anchor_across_inactivity_and_deletion_without_backlog(): void
    {
        $task = $this->task();
        $this->postJson($this->url($task).'/schedule', ['recurrence_type' => 'every_n_days', 'interval_days' => 3])->assertCreated();
        $this->travelTo(Carbon::parse('2026-01-03 10:00:00', 'UTC'));
        app(ProjectService::class)->changeStatus($this->owner, $this->project, ProjectStatus::ARCHIVED);
        $this->travelTo(Carbon::parse('2026-01-08 15:00:00', 'UTC'));
        app(ProjectService::class)->changeStatus($this->owner, $this->project, ProjectStatus::ACTIVE);
        $this->assertSame(['2026-01-01', '2026-01-10', '2026-01-13'], $this->dates($task, '2026-01-01', '2026-01-13'));

        $this->travelTo(Carbon::parse('2026-01-11 10:00:00', 'UTC'));
        $this->deleteJson($this->url($task))->assertNoContent();
        $this->travelTo(Carbon::parse('2026-01-14 15:00:00', 'UTC'));
        $this->postJson($this->url($task).'/restore')->assertOk();
        $this->assertSame(['2026-01-01', '2026-01-10', '2026-01-16'], $this->dates($task, '2026-01-01', '2026-01-16'));
        $this->assertDatabaseCount('project_task_deletion_periods', 1);
        $this->getJson($this->url($task).'/schedule')->assertOk()->assertJsonPath('data.anchor_date', '2026-01-01');
    }

    public function test_monthly_day_31_skips_months_without_that_calendar_day(): void
    {
        $task = $this->task();
        $this->postJson($this->url($task).'/schedule', ['recurrence_type' => 'monthly', 'month_day' => 31])->assertCreated();
        $this->assertSame(['2026-01-31', '2026-03-31'], $this->dates($task, '2026-01-01', '2026-04-30'));
        $this->getJson($this->url($task).'/occurrences/2026-01-31')->assertOk()->assertJsonPath('data.scheduled_time', null);
    }

    public function test_schedule_edits_start_tomorrow_preserve_recorded_completions_and_occurrence_identity(): void
    {
        $task = $this->task();
        $url = $this->url($task);
        $version = $this->postJson($url.'/schedule', ['recurrence_type' => 'daily'])->assertCreated()->json('data.id');
        foreach (['2026-01-01', '2026-01-06'] as $date) {
            $this->postJson($url.'/occurrences/'.$date.'/done', ['request_id' => $date, 'lock_version' => 0])->assertOk();
        }
        $this->travelTo(Carbon::parse('2026-01-02 12:00:00', 'UTC'));
        $this->patchJson($url.'/schedule', ['recurrence_type' => 'weekdays', 'weekdays' => [1]])->assertOk()
            ->assertJsonPath('data.version', 2)->assertJsonPath('data.effective_from', '2026-01-03');
        $this->getJson($url.'/schedule')->assertOk()->assertJsonPath('data.id', $version)->assertJsonPath('data.effective_until', '2026-01-03');
        $this->getJson($url.'/schedule-versions')->assertOk()->assertJsonPath('meta.total', 2);
        $rows = $this->getJson($url.'/occurrences?from=2026-01-01&to=2026-01-07')->assertOk()->json('data');
        $this->assertSame(['2026-01-01', '2026-01-02', '2026-01-05', '2026-01-06'], array_column($rows, 'scheduled_date'));
        $this->assertSame('done', $rows[3]['status']);
        $this->assertFalse($rows[3]['is_scheduled']);
        $this->assertSame($version, $rows[3]['schedule_version_id']);
        $this->postJson($url.'/occurrences/2026-01-04/done', ['request_id' => 'invalid', 'lock_version' => 0])->assertUnprocessable();
        $this->postJson($url.'/occurrences/2026-01-06/undo', ['request_id' => 'undo', 'lock_version' => 1])->assertOk();
        $this->assertDatabaseCount('project_task_occurrences', 2);
        $this->assertDatabaseCount('project_task_completion_events', 3);
        $this->assertSame(ProjectStatus::ACTIVE, $task->fresh()->status);
    }

    public function test_project_status_at_local_day_start_controls_eligibility_after_reactivation(): void
    {
        $task = $this->task();
        $url = $this->url($task);
        $this->postJson($url.'/schedule', ['recurrence_type' => 'daily'])->assertCreated();
        $this->travelTo(Carbon::parse('2026-01-02 10:00:00', 'UTC'));
        app(ProjectService::class)->changeStatus($this->owner, $this->project, ProjectStatus::COMPLETED);
        $this->assertSame(['2026-01-01', '2026-01-02'], $this->dates($task, '2026-01-01', '2026-01-06'));
        $this->patchJson($url.'/schedule', ['recurrence_type' => 'daily'])->assertForbidden();
        $this->postJson($url.'/occurrences/2026-01-02/done', ['request_id' => 'inactive', 'lock_version' => 0])->assertForbidden();
        $this->travelTo(Carbon::parse('2026-01-04 15:00:00', 'UTC'));
        app(ProjectService::class)->changeStatus($this->owner, $this->project, ProjectStatus::ACTIVE);
        $this->assertSame(['2026-01-01', '2026-01-02', '2026-01-05', '2026-01-06'], $this->dates($task, '2026-01-01', '2026-01-06'));
        $this->postJson($url.'/occurrences/2026-01-03/done', ['request_id' => 'excluded', 'lock_version' => 0])->assertUnprocessable();
    }
}
