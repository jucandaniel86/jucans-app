<?php

namespace Tests\Feature\Api;

use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\User;
use App\Services\ProjectNoteConfigurationService;
use App\Services\ProjectService;
use Database\Seeders\ProjectCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShoppingExpenseProjectsApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProjectCategorySeeder::class);
        $this->user = User::create(['username' => 'expense-selector', 'pin' => '1234']);
        Sanctum::actingAs($this->user);
    }

    private function project(bool $shopping = true, ?User $owner = null): Project
    {
        $project = app(ProjectService::class)->create($owner ?? $this->user, [
            'name' => 'Project', 'category_id' => ProjectCategory::firstOrFail()->id,
        ]);
        if ($shopping) {
            $service = app(ProjectNoteConfigurationService::class);
            $type = $service->saveType($project, ['name' => 'Shopping']);
            $amount = $service->saveField($project, ['name' => 'Amount', 'key' => 'amount', 'type' => 'number']);
            $provider = $service->saveField($project, ['name' => 'Provider', 'key' => 'provider', 'type' => 'select']);
            $option = $service->saveOption($provider, ['label' => 'Default']);
            $service->attach($project, $type, $amount, []);
            $service->attach($project, $type, $provider, []);
            $service->saveType($project, ['is_shopping' => true, 'shopping_amount_field_id' => $amount->id,
                'shopping_provider_field_id' => $provider->id, 'shopping_provider_option_id' => $option->id], $type);
        }
        return $project;
    }

    public function test_filters_before_pagination_and_preserves_general_listing(): void
    {
        $first = $this->project();
        $second = $this->project();
        for ($i = 0; $i < 6; $i++) $this->project(false);
        $this->getJson('/api/projects?status=active&per_page=1')->assertOk()->assertJsonPath('meta.total', 8);
        $one = $this->getJson('/api/projects?status=active&shopping_only=1&per_page=1')
            ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2);
        $two = $this->getJson('/api/projects?status=active&shopping_only=1&per_page=1&page=2')
            ->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], [$one->json('data.0.id'), $two->json('data.0.id')]);
    }

    public function test_excludes_inaccessible_inactive_archived_and_invalid_mappings(): void
    {
        $valid = $this->project();
        $other = User::create(['username' => 'other-selector', 'pin' => '1234']);
        $shared = $this->project(true, $other);
        $shared->collaborators()->create(['user_id' => $this->user->id, 'sharing_mode' => 'all_tasks']);
        $this->project(true, $other); // No access.
        $inactive = $this->project();
        $inactive->forceFill(['status' => 'completed'])->save();
        foreach (['type', 'amount', 'provider', 'option', 'attachment', 'wrong_type', 'missing_option', 'missing'] as $invalid) {
            $project = $this->project();
            $type = $project->noteTypes()->firstOrFail();
            match ($invalid) {
                'type' => $type->forceFill(['archived_at' => now()])->save(),
                'amount' => DB::table('project_custom_fields')->where('id', $type->shopping_amount_field_id)->update(['archived_at' => now()]),
                'provider' => DB::table('project_custom_fields')->where('id', $type->shopping_provider_field_id)->update(['archived_at' => now()]),
                'option' => DB::table('project_custom_field_options')->where('id', $type->shopping_provider_option_id)->update(['archived_at' => now()]),
                'attachment' => $type->fields()->update(['active_slot' => null]),
                'wrong_type' => DB::table('project_custom_fields')->where('id', $type->shopping_amount_field_id)->update(['type' => 'text']),
                'missing_option' => $type->forceFill(['shopping_provider_option_id' => null])->save(),
                'missing' => $type->forceFill(['shopping_amount_field_id' => null])->save(),
            };
        }
        $response = $this->getJson('/api/projects?shopping_only=1&status=all')->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertEqualsCanonicalizing([$valid->id, $shared->id], array_column($response->json('data'), 'id'));
    }

    public function test_query_count_is_constant_and_empty_result_is_paginated(): void
    {
        $this->getJson('/api/projects?shopping_only=1')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        for ($i = 0; $i < 6; $i++) $this->project();
        DB::enableQueryLog();
        $counts = [];
        foreach ([1, 6] as $size) {
            DB::flushQueryLog();
            $this->getJson("/api/projects?shopping_only=1&per_page={$size}")->assertOk()->assertJsonCount($size, 'data');
            $queries = DB::getQueryLog();
            $counts[] = count($queries);
            // COUNT + page SELECT, each with correlated EXISTS; never a per-project Note Types read.
            $this->assertCount(2, array_filter($queries, fn ($q) => str_contains($q['query'], 'project_note_types')));
        }
        DB::disableQueryLog();
        $this->assertSame($counts[0], $counts[1]);
    }
}
