<?php

namespace Tests\Feature\Api;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectStatusPeriod;
use App\Models\User;
use App\Services\ProjectService;
use Database\Seeders\ProjectCategorySeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class ProjectApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProjectCategorySeeder::class);
        $this->owner = User::create(['username' => 'owner', 'pin' => '1234']);
        Sanctum::actingAs($this->owner);
        $this->travelTo(Carbon::parse('2026-10-25 10:00:00.123456', 'UTC'));
    }

    private function category(string $slug = 'health'): ProjectCategory
    {
        return ProjectCategory::query()->where('slug', $slug)->firstOrFail();
    }

    private function project(array $attributes = [], ?User $owner = null): Project
    {
        return app(ProjectService::class)->create($owner ?? $this->owner, [
            'name' => 'My project',
            'category_id' => $this->category()->id,
            ...$attributes,
        ])->refresh();
    }

    private function otherUser(bool $admin = false): User
    {
        return User::create(['username' => 'other', 'pin' => '1234', 'is_admin' => $admin]);
    }

    public function test_category_seeder_is_idempotent_and_preserves_ids_and_project_references(): void
    {
        $project = $this->project();
        $before = ProjectCategory::query()->orderBy('id')->get(['id', 'slug', 'created_at'])->toArray();
        $this->travel(1)->days();
        $this->seed(ProjectCategorySeeder::class);
        $this->assertSame($before, ProjectCategory::query()->orderBy('id')->get(['id', 'slug', 'created_at'])->toArray());
        $this->assertDatabaseCount('project_categories', 4);
        $this->assertSame($this->category()->id, $project->fresh()->category_id);
        $this->getJson('/api/project-categories')->assertOk()->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.name', 'Health')->assertJsonPath('data.1.name', 'Wellness')
            ->assertJsonPath('data.2.name', 'Financial')->assertJsonPath('data.3.name', 'Hobby');
    }

    public function test_creates_project_with_defaults_owner_and_initial_status_interval(): void
    {
        $response = $this->postJson('/api/projects', ['name' => '  Better health  ', 'category_id' => $this->category()->id])
            ->assertCreated()->assertJsonPath('data.name', 'Better health')
            ->assertJsonPath('data.description', null)->assertJsonPath('data.icon', 'folder')
            ->assertJsonPath('data.progress_type', 'completion')->assertJsonPath('data.default_execution_type', 'one_time')
            ->assertJsonPath('data.note_template', null)->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.created_by', $this->owner->id)->assertJsonPath('data.owner.id', $this->owner->id)
            ->assertJsonPath('data.category.slug', 'health')->assertJsonCount(1, 'data.status_periods')
            ->assertJsonPath('data.status_periods.0.status', 'active')->assertJsonPath('data.status_periods.0.ends_at', null)
            ->assertJsonMissingPath('data.owner.pin')->assertJsonMissingPath('data.owner.is_admin');

        $project = Project::findOrFail($response->json('data.id'));
        $this->assertTrue($this->owner->ownedProjects()->whereKey($project->id)->exists());
        $this->assertTrue($this->category()->projects()->whereKey($project->id)->exists());
        $this->assertSame(now()->toISOString(), $project->last_activity_at->toISOString());
        $period = $project->statusPeriods()->sole();
        $this->assertTrue($period->project->is($project));
        $this->assertTrue($period->changedBy->is($this->owner));
        $this->assertSame(now()->toISOString(), $period->starts_at->toISOString());
    }

    public function test_creates_all_supported_initial_statuses_and_custom_settings(): void
    {
        foreach (ProjectStatus::cases() as $status) {
            $this->postJson('/api/projects', [
                'name' => 'Custom', 'category_id' => $this->category('hobby')->id,
                'description' => 'Description', 'icon' => 'star', 'progress_type' => 'scored',
                'default_execution_type' => 'persistent', 'note_template' => 'How was today?', 'status' => $status->value,
            ])->assertCreated()->assertJsonPath('data.description', 'Description')
                ->assertJsonPath('data.icon', 'star')->assertJsonPath('data.progress_type', 'scored')
                ->assertJsonPath('data.default_execution_type', 'persistent')
                ->assertJsonPath('data.note_template', 'How was today?')->assertJsonPath('data.status', $status->value)
                ->assertJsonPath('data.status_periods.0.status', $status->value);
        }
    }

    public function test_validates_required_fields_and_category_foreign_key(): void
    {
        $this->postJson('/api/projects', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'category_id']);
        foreach ([null, 999999, 'health', []] as $category) {
            $this->postJson('/api/projects', ['name' => 'Invalid', 'category_id' => $category])
                ->assertUnprocessable()->assertJsonValidationErrors('category_id');
        }
        foreach (['', '   ', str_repeat('x', 256)] as $name) {
            $this->postJson('/api/projects', ['name' => $name, 'category_id' => $this->category()->id])
                ->assertUnprocessable()->assertJsonValidationErrors('name');
        }
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('project_status_periods', 0);
    }

    public function test_validates_enum_and_text_fields_and_rejects_client_controlled_ownership_and_activity(): void
    {
        $invalid = [
            'icon' => null, 'progress_type' => 'percentage', 'default_execution_type' => 'weekly',
            'status' => 'draft', 'description' => [], 'note_template' => [],
            'created_by' => $this->otherUser()->id, 'owner_id' => $this->owner->id,
            'last_activity_at' => '2030-01-01', 'created_at' => '2030-01-01', 'updated_at' => '2030-01-01',
        ];
        $this->postJson('/api/projects', ['name' => 'Invalid', 'category_id' => $this->category()->id, ...$invalid])
            ->assertUnprocessable()->assertJsonValidationErrors(array_keys($invalid));
        $this->assertDatabaseCount('projects', 0);
    }

    public function test_owner_can_read_and_update_all_settings_and_clear_nullable_fields(): void
    {
        $project = $this->project();
        $this->getJson("/api/projects/{$project->id}")->assertOk()->assertJsonCount(1, 'data.status_periods');
        $this->travel(1)->hours();
        $this->patchJson("/api/projects/{$project->id}", [
            'name' => 'Updated', 'description' => 'Details', 'category_id' => $this->category('wellness')->id,
            'icon' => 'heart', 'progress_type' => 'daily_completion',
            'default_execution_type' => 'recurring', 'note_template' => 'Template',
        ])->assertOk()->assertJsonPath('data.name', 'Updated')->assertJsonPath('data.description', 'Details')
            ->assertJsonPath('data.category.slug', 'wellness')->assertJsonPath('data.icon', 'heart')
            ->assertJsonPath('data.progress_type', 'daily_completion')->assertJsonPath('data.default_execution_type', 'recurring')
            ->assertJsonPath('data.note_template', 'Template')->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.last_activity_at', now()->toISOString());
        $this->patchJson("/api/projects/{$project->id}", ['description' => null, 'note_template' => ''])
            ->assertOk()->assertJsonPath('data.description', null)->assertJsonPath('data.note_template', null);
        $this->assertDatabaseCount('project_status_periods', 1);
    }

    public function test_invalid_settings_update_is_atomic_and_cannot_change_owner_status_or_activity(): void
    {
        $project = $this->project();
        $this->patchJson("/api/projects/{$project->id}", [
            'name' => 'Must not change', 'category_id' => 999999, 'status' => 'completed',
            'created_by' => $this->otherUser()->id, 'last_activity_at' => '2030-01-01',
        ])->assertUnprocessable()->assertJsonValidationErrors(['category_id', 'status', 'created_by', 'last_activity_at']);
        $this->assertSame('My project', $project->fresh()->name);
        $this->assertSame(ProjectStatus::ACTIVE, $project->fresh()->status);
        $this->assertSame($this->owner->id, $project->fresh()->created_by);
        $this->assertDatabaseCount('project_status_periods', 1);
    }

    public function test_non_owner_and_admin_have_no_project_access_or_write_privileges(): void
    {
        $project = $this->project();
        $other = $this->otherUser();
        foreach ([false, true] as $admin) {
            $other->update(['is_admin' => $admin]);
            Sanctum::actingAs($other);
            $this->getJson('/api/projects?status=all')->assertOk()->assertJsonPath('meta.total', 0);
            $this->getJson("/api/projects/{$project->id}")->assertNotFound();
            $this->patchJson("/api/projects/{$project->id}", ['name' => 'Denied'])->assertForbidden();
            $this->patchJson("/api/projects/{$project->id}/status", ['status' => 'archived'])->assertForbidden();
            $this->assertFalse(Gate::forUser($other)->allows('view', $project));
            $this->assertFalse(Gate::forUser($other)->allows('update', $project));
            $this->assertFalse(Gate::forUser($other)->allows('changeStatus', $project));
        }
        $this->assertSame('My project', $project->fresh()->name);
        $this->assertDatabaseCount('project_status_periods', 1);
    }

    public function test_status_transitions_preserve_contiguous_intervals_with_actor_and_microseconds(): void
    {
        $project = $this->project();
        $starts = [now()->toISOString()];
        foreach (['completed', 'active', 'archived', 'active'] as $status) {
            $this->travel(1)->hours();
            $starts[] = now()->toISOString();
            $this->patchJson("/api/projects/{$project->id}/status", ['status' => $status, 'name' => 'Ignored'])
                ->assertOk()->assertJsonPath('data.status', $status)
                ->assertJsonPath('data.name', 'My project')->assertJsonPath('data.last_activity_at', now()->toISOString());
        }
        $periods = $project->statusPeriods()->get();
        $this->assertSame(['active', 'completed', 'active', 'archived', 'active'], $periods->map(fn ($period) => $period->status->value)->all());
        foreach ($periods as $index => $period) {
            $this->assertSame($starts[$index], $period->starts_at->toISOString());
            $this->assertSame($starts[$index + 1] ?? null, $period->ends_at?->toISOString());
            $this->assertSame($this->owner->id, $period->changed_by);
        }
        $this->assertSame(1, $project->statusPeriods()->whereNull('ends_at')->count());
    }

    public function test_inactive_projects_are_read_only_until_reactivated(): void
    {
        foreach (['completed', 'archived'] as $status) {
            $project = $this->project();
            $this->patchJson("/api/projects/{$project->id}/status", ['status' => $status])->assertOk();
            $before = $project->fresh()->getRawOriginal();
            $this->getJson("/api/projects/{$project->id}")->assertOk();
            $this->patchJson("/api/projects/{$project->id}", ['name' => 'Denied', 'note_template' => 'Denied'])->assertForbidden();
            $this->assertSame($before, $project->fresh()->getRawOriginal());
            $this->patchJson("/api/projects/{$project->id}/status", ['status' => 'active'])->assertOk();
            $this->patchJson("/api/projects/{$project->id}", ['name' => 'Allowed'])->assertOk();
        }
    }

    public function test_inactive_to_other_inactive_status_requires_reactivation_and_is_atomic(): void
    {
        foreach (['completed' => 'archived', 'archived' => 'completed'] as $from => $to) {
            $project = $this->project(['status' => $from]);
            $this->patchJson("/api/projects/{$project->id}/status", ['status' => $to])->assertConflict();
            $this->assertSame($from, $project->fresh()->status->value);
            $this->assertSame(1, $project->statusPeriods()->count());
            $this->assertNull($project->statusPeriods()->sole()->ends_at);
        }
    }

    public function test_no_op_updates_reads_and_repeated_status_requests_preserve_activity_and_history(): void
    {
        $project = $this->project();
        $before = $project->getRawOriginal();
        $this->travel(1)->days();
        $this->getJson('/api/projects')->assertOk();
        $this->getJson("/api/projects/{$project->id}")->assertOk();
        $this->patchJson("/api/projects/{$project->id}", ['name' => $project->name])->assertOk();
        $this->patchJson("/api/projects/{$project->id}", [])->assertOk();
        $this->patchJson("/api/projects/{$project->id}/status", ['status' => 'active'])->assertOk();
        $this->assertSame($before, $project->fresh()->getRawOriginal());
        $this->assertSame(1, $project->statusPeriods()->count());

        $this->patchJson("/api/projects/{$project->id}/status", ['status' => 'completed'])->assertOk();
        $before = $project->fresh()->getRawOriginal();
        $this->travel(1)->days();
        $this->patchJson("/api/projects/{$project->id}/status", ['status' => 'completed'])->assertOk();
        $this->assertSame($before, $project->fresh()->getRawOriginal());
        $this->assertSame(2, $project->statusPeriods()->count());
    }

    public function test_status_request_rejects_missing_null_and_invalid_values(): void
    {
        $project = $this->project();
        foreach ([[], ['status' => null], ['status' => 'draft'], ['status' => []], ['status' => 'ACTIVE']] as $payload) {
            $this->patchJson("/api/projects/{$project->id}/status", $payload)->assertUnprocessable()->assertJsonValidationErrors('status');
        }
        $this->assertSame(ProjectStatus::ACTIVE, $project->fresh()->status);
        $this->assertSame(1, $project->statusPeriods()->count());
    }

    public function test_status_transition_rolls_back_interval_closure_when_new_interval_cannot_be_written(): void
    {
        $project = $this->project();
        $before = $project->getRawOriginal();
        ProjectStatusPeriod::creating(function () {
            throw new RuntimeException('Simulated history write failure');
        });
        try {
            app(ProjectService::class)->changeStatus($this->owner, $project, ProjectStatus::COMPLETED);
            $this->fail('The simulated write should fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated history write failure', $exception->getMessage());
        } finally {
            ProjectStatusPeriod::flushEventListeners();
        }
        $this->assertSame($before, $project->fresh()->getRawOriginal());
        $this->assertSame(1, $project->statusPeriods()->count());
        $this->assertNull($project->statusPeriods()->sole()->ends_at);
    }

    public function test_service_rechecks_read_only_policy_against_fresh_state_inside_transaction(): void
    {
        $staleProject = $this->project();
        $service = app(ProjectService::class);
        $service->changeStatus($this->owner, $staleProject, ProjectStatus::ARCHIVED);
        try {
            $service->update($this->owner, $staleProject, ['name' => 'Denied']);
            $this->fail('The fresh archived state must deny the stale update.');
        } catch (AuthorizationException) {
            $this->assertSame('My project', $staleProject->fresh()->name);
        }
    }

    public function test_listing_defaults_to_active_supports_inactive_and_exact_status_and_category_filters(): void
    {
        $active = $this->project();
        $completed = $this->project(['status' => 'completed']);
        $archived = $this->project(['status' => 'archived', 'category_id' => $this->category('hobby')->id]);
        $this->project([], $this->otherUser());
        $this->getJson('/api/projects')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $active->id);
        $this->getJson('/api/projects?status=all')->assertOk()->assertJsonPath('meta.total', 3);
        $inactive = $this->getJson('/api/projects?status=inactive')->assertOk()->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing([$completed->id, $archived->id], array_column($inactive->json('data'), 'id'));
        foreach (['active' => $active, 'completed' => $completed, 'archived' => $archived] as $status => $project) {
            $this->getJson("/api/projects?status={$status}")->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $project->id);
        }
        $this->getJson('/api/projects?status=inactive&category_id='.$this->category('hobby')->id)
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $archived->id);
    }

    public function test_listing_orders_by_meaningful_activity_within_categories_and_has_stable_ties(): void
    {
        $healthOld = $this->project(['name' => 'Old']);
        $healthTieFirst = $this->project(['name' => 'Tie first']);
        $healthTieLast = $this->project(['name' => 'Tie last']);
        $this->travel(1)->hours();
        $healthRecent = $this->project(['name' => 'Recent']);
        $this->travel(1)->hours();
        $hobby = $this->project(['category_id' => $this->category('hobby')->id]);
        $this->travel(1)->hours();
        $this->patchJson("/api/projects/{$healthOld->id}", ['description' => 'Meaningful change'])->assertOk();
        $response = $this->getJson('/api/projects')->assertOk()->assertJsonMissingPath('data.0.status_periods');
        $this->assertSame([$healthOld->id, $healthRecent->id, $healthTieLast->id, $healthTieFirst->id, $hobby->id], array_column($response->json('data'), 'id'));

        $this->travel(1)->hours();
        $this->patchJson("/api/projects/{$healthTieFirst->id}/status", ['status' => 'completed'])->assertOk();
        $this->patchJson("/api/projects/{$healthTieFirst->id}/status", ['status' => 'active'])->assertOk();
        $this->getJson('/api/projects')->assertOk()->assertJsonPath('data.0.id', $healthTieFirst->id);
    }

    public function test_listing_is_paginated_and_validates_filters(): void
    {
        $this->project();
        $latest = $this->project();
        $this->getJson('/api/projects?per_page=1')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)->assertJsonPath('data.0.id', $latest->id);
        $this->getJson('/api/projects?per_page=1&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.current_page', 2);
        foreach (['status=draft', 'category_id=999999', 'per_page=101', 'per_page=0', 'page=0'] as $query) {
            $this->getJson('/api/projects?'.$query)->assertUnprocessable();
        }
    }

    public function test_unknown_projects_return_not_found(): void
    {
        $this->getJson('/api/projects/999999')->assertNotFound();
        $this->patchJson('/api/projects/999999', ['name' => 'Unknown'])->assertNotFound();
        $this->patchJson('/api/projects/999999/status', ['status' => 'active'])->assertNotFound();
    }

    public function test_project_foreign_keys_prevent_orphaned_category_owner_and_history(): void
    {
        $project = $this->project();
        foreach ([
            ['project_categories', $project->category_id],
            ['users', $this->owner->id],
            ['projects', $project->id],
        ] as [$table, $id]) {
            $blocked = false;
            try {
                DB::transaction(fn () => DB::table($table)->where('id', $id)->delete());
            } catch (\Illuminate\Database\QueryException) {
                $blocked = true;
            }
            $this->assertTrue($blocked, "Deleting {$table} must respect project/history references.");
        }
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('project_status_periods', 1);
    }

    public function test_all_project_endpoints_require_authentication(): void
    {
        $project = $this->project();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/project-categories')->assertUnauthorized();
        $this->getJson('/api/projects')->assertUnauthorized();
        $this->getJson("/api/projects/{$project->id}")->assertUnauthorized();
        $this->postJson('/api/projects', ['name' => 'Denied', 'category_id' => $this->category()->id])->assertUnauthorized();
        $this->patchJson("/api/projects/{$project->id}", ['name' => 'Denied'])->assertUnauthorized();
        $this->patchJson("/api/projects/{$project->id}/status", ['status' => 'completed'])->assertUnauthorized();
    }
}
