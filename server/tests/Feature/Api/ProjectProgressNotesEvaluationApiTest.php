<?php

namespace Tests\Feature\Api;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectProgressNote;
use App\Models\ProjectTask;
use App\Models\ProjectTaskEvaluation;
use App\Models\User;
use App\Services\ProjectService;
use App\Services\ProjectSharingService;
use App\Services\ProjectTaskService;
use Database\Seeders\ProjectCategorySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectProgressNotesEvaluationApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $author;

    private User $reader;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProjectCategorySeeder::class);
        $this->owner = User::create(['username' => 'owner', 'pin' => '1234']);
        $this->author = User::create(['username' => 'author', 'pin' => '1234']);
        $this->reader = User::create(['username' => 'reader', 'pin' => '1234', 'is_admin' => true]);
        $this->project = app(ProjectService::class)->create($this->owner, [
            'name' => 'Project', 'category_id' => ProjectCategory::firstOrFail()->id,
            'note_template' => '{date}|{project}|{user}|{tasks}|{unknown}',
        ]);
        foreach ([$this->author, $this->reader] as $user) {
            app(ProjectSharingService::class)->add($this->owner, $this->project, ['user_id' => $user->id, 'sharing_mode' => 'all_tasks']);
        }
        Sanctum::actingAs($this->owner);
    }

    private function base(): string
    {
        return '/api/projects/'.$this->project->id;
    }

    private function task(string $title = 'Task'): ProjectTask
    {
        return app(ProjectTaskService::class)->create($this->owner, $this->project, ['title' => $title]);
    }

    private function note(array $taskIds = [], ?User $author = null): int
    {
        Sanctum::actingAs($author ?? $this->owner);

        return $this->postJson($this->base().'/notes', [
            'title' => 'Observation', 'content' => 'Private content', 'noted_at' => '2026-01-02', 'task_ids' => $taskIds,
        ])->assertCreated()->json('data.id');
    }

    private function evaluationUrl(ProjectTask $task): string
    {
        return $this->base().'/tasks/'.$task->id.'/evaluations';
    }

    public function test_general_notes_are_private_to_author_and_owner_and_template_preview_is_literal(): void
    {
        $ownerNote = $this->note();
        $own = $this->note([], $this->author);
        $this->getJson($this->base().'/notes?general_only=1&search=Private')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $own);
        $this->getJson($this->base().'/notes/'.$ownerNote)->assertNotFound();
        $this->patchJson($this->base().'/notes/'.$own, ['content' => 'Edited'])->assertOk();
        $this->postJson($this->base().'/note-template/preview', ['date' => '2026-01-02'])->assertOk()
            ->assertJsonPath('data.content', '2026-01-02|Project|author||{unknown}');
        $this->patchJson($this->base(), ['note_template' => 'Denied'])->assertForbidden();

        Sanctum::actingAs($this->reader);
        $this->getJson($this->base().'/notes')->assertOk()->assertJsonPath('meta.total', 0);
        $this->patchJson($this->base().'/notes/'.$own, ['title' => 'Denied'])->assertForbidden();
        Sanctum::actingAs($this->owner);
        $this->getJson($this->base().'/notes')->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_linked_notes_and_evaluations_require_access_to_every_associated_task(): void
    {
        $a = $this->task('A');
        $b = $this->task('B');
        $note = $this->note([$a->id, $b->id]);
        $evaluation = $this->postJson($this->evaluationUrl($a), ['evaluation_date' => '2026-01-02', 'score' => 7, 'note_id' => $note])
            ->assertCreated()->json('data.id');
        $member = $this->project->collaborators()->where('user_id', $this->reader->id)->firstOrFail();
        $sharing = app(ProjectSharingService::class);
        $sharing->update($this->owner, $this->project, $member, ['sharing_mode' => 'selected_tasks', 'task_ids' => [$a->id]]);
        Sanctum::actingAs($this->reader);
        $this->getJson($this->base().'/notes?search=Private&task_id='.$a->id)->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($this->base().'/notes/'.$note)->assertNotFound();
        $this->getJson($this->evaluationUrl($a))->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($this->evaluationUrl($a).'/'.$evaluation)->assertForbidden();
        $sharing->setGrants($this->owner, $this->project, $member, [$a->id, $b->id]);
        $this->getJson($this->base().'/notes/'.$note)->assertOk()->assertJsonCount(2, 'data.tasks')->assertJsonCount(1, 'data.evaluations');
        $this->patchJson($this->base().'/notes/'.$note, ['content' => 'Denied'])->assertForbidden();
    }

    public function test_evaluation_day_uniqueness_explicit_updates_ownership_and_optimistic_locking(): void
    {
        $task = $this->task();
        $url = $this->evaluationUrl($task);
        Sanctum::actingAs($this->author);
        $id = $this->postJson($url, ['evaluation_date' => '2026-01-02', 'score' => 4])->assertCreated()->json('data.id');
        $this->postJson($url, ['evaluation_date' => '2026-01-02', 'score' => 5])->assertConflict();
        $this->postJson($url, ['evaluation_date' => '2026-01-03', 'score' => 11])->assertUnprocessable();
        Sanctum::actingAs($this->reader);
        $this->patchJson($url.'/'.$id, ['score' => 5, 'lock_version' => 0])->assertForbidden();
        Sanctum::actingAs($this->owner);
        $this->patchJson($url.'/'.$id, ['score' => 6, 'lock_version' => 0])->assertOk()->assertJsonPath('data.lock_version', 1);
        Sanctum::actingAs($this->author);
        $this->patchJson($url.'/'.$id, ['score' => 8, 'lock_version' => 0])->assertConflict();
        $this->patchJson($url.'/'.$id, ['score' => 9, 'lock_version' => 1])->assertOk()->assertJsonPath('data.lock_version', 2);
        $this->getJson($url.'/'.$id.'/revisions')->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.previous_score', 6);
        $this->assertDatabaseCount('project_task_completion_events', 0);
        $this->expectException(QueryException::class);
        DB::transaction(fn () => ProjectTaskEvaluation::create([
            'project_id' => $this->project->id, 'task_id' => $task->id, 'evaluation_date' => '2026-01-02', 'score' => 3, 'created_by' => $this->owner->id,
        ]));
    }

    public function test_note_deletion_suspends_evaluations_and_restore_conflicts_are_atomic_and_resolvable(): void
    {
        $a = $this->task('A');
        $b = $this->task('B');
        $note = $this->note([$a->id, $b->id], $this->author);
        foreach ([$a, $b] as $task) {
            $this->postJson($this->evaluationUrl($task), ['evaluation_date' => '2026-01-02', 'score' => 7, 'note_id' => $note])->assertCreated();
        }
        $this->deleteJson($this->base().'/notes/'.$note)->assertNoContent();
        $this->getJson($this->base().'/notes')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($this->evaluationUrl($a))->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertSame(0, ProjectTaskEvaluation::query()->active()->count());
        $this->getJson($this->base().'/notes/trash')->assertForbidden();
        Sanctum::actingAs($this->owner);
        $this->getJson($this->base().'/notes/trash')->assertOk()->assertJsonPath('meta.total', 1);
        $replacement = $this->postJson($this->evaluationUrl($a), ['evaluation_date' => '2026-01-02', 'score' => 5])->assertCreated()->json('data.id');
        $this->postJson($this->base().'/notes/'.$note.'/restore')->assertConflict();
        $this->assertTrue(ProjectProgressNote::withTrashed()->findOrFail($note)->trashed());
        $this->deleteJson($this->evaluationUrl($a).'/'.$replacement, ['lock_version' => 0])->assertNoContent();
        $this->postJson($this->base().'/notes/'.$note.'/restore')->assertOk()->assertJsonCount(2, 'data.evaluations');
        $this->assertSame(2, ProjectTaskEvaluation::query()->active()->count());
        $this->assertDatabaseCount('project_task_evaluation_revisions', 4);
        app(ProjectService::class)->changeStatus($this->owner, $this->project, ProjectStatus::ARCHIVED);
        $this->patchJson($this->base().'/notes/'.$note, ['content' => 'Denied'])->assertForbidden();
        $this->postJson($this->evaluationUrl($a), ['evaluation_date' => '2026-01-03', 'score' => 4])->assertForbidden();
    }

    public function test_cross_project_and_unassociated_note_task_links_are_rejected_and_constrained(): void
    {
        $local = $this->task();
        $other = app(ProjectService::class)->create($this->owner, ['name' => 'Other', 'category_id' => $this->project->category_id]);
        $foreign = app(ProjectTaskService::class)->create($this->owner, $other, ['title' => 'Foreign']);
        $payload = ['title' => 'Invalid', 'content' => 'Invalid', 'noted_at' => '2026-01-02'];
        $this->postJson($this->base().'/notes', [...$payload, 'task_ids' => [$local->id, $foreign->id]])->assertUnprocessable();
        $this->postJson($this->base().'/notes', [...$payload, 'task_ids' => [$local->id, $local->id]])->assertUnprocessable();
        $this->assertDatabaseCount('project_progress_notes', 0);
        $note = $this->note([$local->id]);
        $unassociated = $this->task();
        $this->postJson($this->evaluationUrl($unassociated), ['evaluation_date' => '2026-01-02', 'score' => 5, 'note_id' => $note])->assertUnprocessable();
        $this->postJson($this->evaluationUrl($local), ['evaluation_date' => '2026-01-02', 'score' => 5, 'note_id' => $note])->assertCreated();
        $this->patchJson($this->base().'/notes/'.$note, ['task_ids' => []])->assertConflict();
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('project_progress_note_tasks')->insert([
            'project_id' => $this->project->id, 'note_id' => $note, 'task_id' => $foreign->id,
        ]));
    }
}
