<?php

namespace Tests\Feature\Api;

use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\User;
use App\Services\ProjectService;
use App\Services\ProjectTaskService;
use Database\Seeders\ProjectCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectCollaborationTaskApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProjectCategorySeeder::class);
        $this->owner = User::create(['username' => 'owner', 'pin' => '1234']);
        $this->member = User::create(['username' => 'member', 'pin' => '1234', 'is_admin' => true]);
        $this->project = app(ProjectService::class)->create($this->owner, [
            'name' => 'Project', 'category_id' => ProjectCategory::firstOrFail()->id,
            'default_execution_type' => 'recurring',
        ]);
        Sanctum::actingAs($this->owner);
    }

    private function url(): string
    {
        return '/api/projects/'.$this->project->id;
    }

    private function share(string $mode, array $taskIds = []): int
    {
        Sanctum::actingAs($this->owner);
        $payload = ['user_id' => $this->member->id, 'sharing_mode' => $mode];
        if ($mode === 'selected_tasks') {
            $payload['task_ids'] = $taskIds;
        }

        return $this->postJson($this->url().'/collaborators', $payload)->assertCreated()->json('data.id');
    }

    public function test_owner_can_create_manage_soft_delete_and_restore_tasks(): void
    {
        $created = $this->postJson($this->url().'/tasks', [
            'title' => 'Plan', 'scheduled_date' => '2026-10-26', 'due_date' => '2026-10-28',
        ])->assertCreated()->assertJsonPath('data.execution_type', 'recurring')
            ->assertJsonPath('data.priority', 'medium')->assertJsonPath('data.scheduled_date', '2026-10-26')
            ->assertJsonPath('data.scheduled_time', null)->assertJsonPath('data.due_date', '2026-10-28');
        $id = $created->json('data.id');
        $url = $this->url().'/tasks/'.$id;
        $this->travel(1)->minutes();
        $this->patchJson($url, ['title' => 'Updated', 'status' => 'archived', 'due_time' => '14:30'])
            ->assertOk()->assertJsonPath('data.status', 'archived')->assertJsonPath('data.due_time', '14:30');
        $activity = $this->project->fresh()->last_activity_at->toISOString();
        $this->travel(1)->minutes();
        $this->getJson($url)->assertOk();
        $this->assertSame($activity, $this->project->fresh()->last_activity_at->toISOString());
        $this->patchJson($url, ['due_date' => null])->assertUnprocessable();
        $this->deleteJson($url)->assertNoContent();
        $this->getJson($this->url().'/tasks')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($this->url().'/tasks/deleted')->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson($url.'/restore')->assertOk()->assertJsonPath('data.deleted_at', null);
        $this->assertDatabaseHas('project_tasks', ['id' => $id, 'title' => 'Updated', 'due_date' => '2026-10-28', 'deleted_at' => null]);
    }

    public function test_selected_collaborator_sees_only_granted_and_own_tasks_and_grants_stay_in_project(): void
    {
        $tasks = app(ProjectTaskService::class);
        $shared = $tasks->create($this->owner, $this->project, ['title' => 'Shared']);
        $hidden = $tasks->create($this->owner, $this->project, ['title' => 'Hidden']);
        $membership = $this->share('selected_tasks', [$shared->id]);
        $this->postJson($this->url().'/collaborators', ['user_id' => $this->member->id, 'sharing_mode' => 'all_tasks'])->assertUnprocessable();
        $this->postJson($this->url().'/collaborators', ['user_id' => $this->owner->id, 'sharing_mode' => 'all_tasks'])->assertUnprocessable();

        Sanctum::actingAs($this->member);
        $this->getJson($this->url().'/tasks')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $shared->id);
        $this->getJson($this->url().'/tasks/'.$hidden->id)->assertNotFound();
        $own = $this->postJson($this->url().'/tasks', ['title' => 'Own'])->assertCreated()->json('data.id');

        Sanctum::actingAs($this->owner);
        $otherProject = app(ProjectService::class)->create($this->owner, ['name' => 'Other', 'category_id' => $this->project->category_id]);
        $foreignTask = $tasks->create($this->owner, $otherProject, ['title' => 'Foreign']);
        $this->putJson($this->url().'/collaborators/'.$membership.'/tasks', ['task_ids' => [$foreignTask->id]])->assertUnprocessable();
        $this->assertDatabaseHas('project_task_grants', ['collaborator_id' => $membership, 'task_id' => $shared->id]);
        $this->putJson($this->url().'/collaborators/'.$membership.'/tasks', ['task_ids' => []])->assertOk();

        Sanctum::actingAs($this->member);
        $this->getJson($this->url().'/tasks')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $own);
        $this->getJson($this->url().'/tasks/'.$shared->id)->assertNotFound();
        $this->patchJson($this->url().'/tasks/'.$own, ['title' => 'Still accessible'])->assertOk();
    }

    public function test_collaborator_cannot_edit_other_tasks_settings_or_sharing_and_inactive_project_blocks_writes(): void
    {
        $membership = $this->share('all_tasks');
        $otherTask = app(ProjectTaskService::class)->create($this->owner, $this->project, ['title' => 'Owner task']);
        Sanctum::actingAs($this->member);
        $this->getJson($this->url().'/tasks/'.$otherTask->id)->assertOk();
        $this->patchJson($this->url().'/tasks/'.$otherTask->id, ['title' => 'Denied'])->assertForbidden();
        $this->deleteJson($this->url().'/tasks/'.$otherTask->id)->assertForbidden();
        $this->patchJson($this->url(), ['name' => 'Denied'])->assertForbidden();
        $this->patchJson($this->url().'/collaborators/'.$membership, ['sharing_mode' => 'selected_tasks'])->assertForbidden();
        $own = $this->postJson($this->url().'/tasks', ['title' => 'My task'])->assertCreated()->json('data.id');
        $this->deleteJson($this->url().'/tasks/'.$own)->assertNoContent();
        $this->postJson($this->url().'/tasks/'.$own.'/restore')->assertOk();

        Sanctum::actingAs($this->owner);
        $this->patchJson($this->url().'/status', ['status' => 'completed'])->assertOk();
        Sanctum::actingAs($this->member);
        $this->patchJson($this->url().'/tasks/'.$own, ['status' => 'completed'])->assertForbidden();
        $this->postJson($this->url().'/tasks', ['title' => 'Denied'])->assertForbidden();
    }

    public function test_removal_revokes_project_and_task_access_even_to_authored_tasks_and_admins(): void
    {
        $membership = $this->share('selected_tasks');
        Sanctum::actingAs($this->member);
        $task = $this->postJson($this->url().'/tasks', ['title' => 'Authored task'])->assertCreated()->json('data.id');
        $this->getJson('/api/projects')->assertOk()->assertJsonPath('meta.total', 1);

        Sanctum::actingAs($this->owner);
        $this->deleteJson($this->url().'/collaborators/'.$membership)->assertNoContent();
        Sanctum::actingAs($this->member);
        $this->getJson('/api/projects')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($this->url())->assertNotFound();
        $this->getJson($this->url().'/tasks')->assertForbidden();
        $this->getJson($this->url().'/tasks/'.$task)->assertNotFound();
        $this->patchJson($this->url().'/tasks/'.$task, ['title' => 'Denied'])->assertForbidden();
        $this->assertDatabaseHas('project_tasks', ['id' => $task, 'created_by' => $this->member->id, 'deleted_at' => null]);
        $this->assertDatabaseMissing('project_collaborators', ['id' => $membership]);
    }
}
