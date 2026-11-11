<?php

namespace Tests\Feature\Api;

use App\Enums\ProjectTaskCompletionAction;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectTask;
use App\Models\User;
use App\Services\ProjectProgressNoteService;
use App\Services\ProjectService;
use App\Services\ProjectSharingService;
use App\Services\ProjectTaskExecutionService;
use App\Services\ProjectTaskScheduleService;
use App\Services\ProjectTaskService;
use Database\Seeders\ProjectCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectTaskListContextApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutExceptionHandling();
        $this->travelTo(Carbon::parse('2026-11-01 00:00:00', 'UTC'));
        $this->seed(ProjectCategorySeeder::class);
        $this->owner = User::create(['username' => 'list-owner', 'pin' => '1234']);
        $this->project = app(ProjectService::class)->create($this->owner, [
            'name' => 'List', 'category_id' => ProjectCategory::firstOrFail()->id,
        ]);
        $this->travelTo(Carbon::parse('2026-11-11 12:00:00', 'UTC'));
        Sanctum::actingAs($this->owner);
    }

    private function url(): string
    {
        return "/api/projects/{$this->project->id}/tasks";
    }

    private function task(string $type = 'one_time'): ProjectTask
    {
        return app(ProjectTaskService::class)->create($this->owner, $this->project, [
            'title' => 'Visible task', 'execution_type' => $type,
        ]);
    }

    private function review(ProjectTask $task): void
    {
        app(ProjectTaskExecutionService::class)->execute($this->owner, $this->project, $task,
            'single', ProjectTaskCompletionAction::DONE, ['request_id' => 'list-'.$task->id, 'lock_version' => 0]);
        app(ProjectProgressNoteService::class)->create($this->owner, $this->project, [
            'title' => 'Review '.$task->id, 'content' => 'Saved content', 'noted_at' => '2026-11-11',
            'task_ids' => [$task->id], 'review_task_id' => $task->id, 'review_occurrence_key' => 'single',
        ]);
    }

    public function test_context_is_opt_in_and_does_not_change_filter_counts_or_pagination(): void
    {
        $this->task();
        $this->task();
        $this->task('recurring');
        $query = '?execution_type=one_time&status=active&priority=medium&per_page=1';
        $legacy = $this->getJson($this->url().$query)->assertOk()->assertJsonCount(1, 'data');
        $this->assertArrayNotHasKey('contexts', $legacy->json());
        $batched = $this->getJson($this->url().$query.'&include_context=1&occurrence_date=2026-11-11')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonCount(1, 'contexts')
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2);
        $this->assertSame($legacy->json('data'), $batched->json('data'));
        $id = $batched->json('data.0.id');
        $batched->assertJsonPath("contexts.{$id}.occurrences.0.is_recorded", false)
            ->assertJsonPath("contexts.{$id}.occurrences.0.reviews.review_context.can_create", false);
        $this->assertDatabaseCount('project_task_occurrences', 0);
    }

    public function test_recurring_virtual_and_ineligible_dates_reuse_existing_projection_without_writes(): void
    {
        $task = $this->task('recurring');
        app(ProjectTaskScheduleService::class)->save($this->owner, $this->project, $task, [
            'recurrence_type' => 'monthly', 'month_day' => 5, 'start_date' => '2026-11-01',
        ], true);
        $query = '?execution_type=recurring&include_context=1&occurrence_date=';
        $this->getJson($this->url().$query.'2026-11-05')->assertOk()
            ->assertJsonPath("contexts.{$task->id}.occurrences.0.is_recorded", false)
            ->assertJsonPath("contexts.{$task->id}.occurrences.0.lock_version", 0)
            ->assertJsonPath("contexts.{$task->id}.occurrence_eligible", true);
        $this->getJson($this->url().$query.'2026-11-06')->assertOk()
            ->assertJsonPath("contexts.{$task->id}.occurrence_eligible", false);
        $this->assertDatabaseCount('project_task_occurrences', 0);
    }

    public function test_collaborator_context_contains_only_visible_tasks(): void
    {
        $visible = $this->task();
        $hidden = $this->task();
        $collaborator = User::create(['username' => 'list-member', 'pin' => '1234']);
        app(ProjectSharingService::class)->add($this->owner, $this->project, [
            'user_id' => $collaborator->id, 'sharing_mode' => 'selected_tasks', 'task_ids' => [$visible->id],
        ]);
        Sanctum::actingAs($collaborator);
        $response = $this->getJson($this->url().'?include_context=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonCount(1, 'contexts');
        $this->assertSame($visible->id, $response->json('data.0.id'));
        $this->assertArrayNotHasKey($hidden->id, $response->json('contexts'));
    }

    public function test_review_queries_are_batched_for_a_full_page(): void
    {
        for ($index = 0; $index < 10; $index++) {
            $this->review($this->task());
        }
        $url = $this->url().'?status=completed&include_context=1&per_page=';
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson($url.'1')->assertOk()->assertJsonCount(1, 'contexts');
        $one = count(DB::getQueryLog());
        DB::flushQueryLog();
        $response = $this->getJson($url.'10')->assertOk()->assertJsonCount(10, 'contexts');
        $ten = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($one + 1, $ten, "Queries grew from {$one} to {$ten} for ten reviewed tasks.");
        foreach ($response->json('contexts') as $context) {
            $this->assertCount(1, $context['occurrences'][0]['reviews']['data']);
            $this->assertFalse($context['occurrences'][0]['reviews']['review_context']['can_create']);
        }
    }

    public function test_single_deleted_review_releases_eligibility_without_changing_execution(): void
    {
        $task = $this->task();
        $this->review($task);
        $note = $task->progressNotes()->firstOrFail();
        app(ProjectProgressNoteService::class)->delete($this->owner, $this->project, $note);
        $response = $this->getJson($this->url().'?include_context=1')->assertOk();
        $context = $response->json("contexts.{$task->id}");
        $this->assertSame('completed', $context['task']['status']);
        $this->assertSame('done', $context['occurrences'][0]['status']);
        $this->assertSame(1, $context['occurrences'][0]['lock_version']);
        $this->assertTrue($context['occurrences'][0]['reviews']['review_context']['can_create']);
        $this->assertNotNull($context['occurrences'][0]['reviews']['data'][0]['deleted_at']);
    }

    public function test_multiple_deleted_review_keeps_author_reservation_and_visibility(): void
    {
        $task = app(ProjectTaskService::class)->create($this->owner, $this->project, [
            'title' => 'Multiple', 'execution_type' => 'one_time', 'review_mode' => 'multiple',
        ]);
        $this->review($task);
        app(ProjectProgressNoteService::class)->delete($this->owner, $this->project, $task->progressNotes()->firstOrFail());
        $url = $this->url().'?include_context=1';
        $this->getJson($url)->assertOk()
            ->assertJsonPath("contexts.{$task->id}.occurrences.0.reviews.review_context.can_create", false);
        $other = User::create(['username' => 'another-reviewer', 'pin' => '1234']);
        app(ProjectSharingService::class)->add($this->owner, $this->project, [
            'user_id' => $other->id, 'sharing_mode' => 'all_tasks',
        ]);
        Sanctum::actingAs($other);
        $this->getJson($url)->assertOk()
            ->assertJsonPath("contexts.{$task->id}.occurrences.0.reviews.review_context.can_create", true)
            ->assertJsonCount(0, "contexts.{$task->id}.occurrences.0.reviews.data");
    }

    public function test_existing_laravel_limit_remains_enforced(): void
    {
        $this->withExceptionHandling();
        for ($request = 0; $request < 60; $request++) {
            $this->getJson($this->url())->assertOk();
        }
        $response = $this->getJson($this->url())->assertStatus(429)->assertHeader('X-RateLimit-Limit', '60');
        $this->assertNotNull($response->headers->get('Retry-After'));
    }
}
