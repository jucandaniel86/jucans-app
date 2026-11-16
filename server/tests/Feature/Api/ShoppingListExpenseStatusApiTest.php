<?php

namespace Tests\Feature\Api;

use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectProgressNote;
use App\Models\ShoppingList;
use App\Models\User;
use App\Services\ProjectService;
use Database\Seeders\ProjectCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShoppingListExpenseStatusApiTest extends TestCase
{
    use RefreshDatabase;

    private function project(User $owner): Project
    {
        $this->seed(ProjectCategorySeeder::class);
        return app(ProjectService::class)->create($owner, ['name' => 'Expenses', 'category_id' => ProjectCategory::firstOrFail()->id]);
    }

    private function expense(Project $project, ShoppingList $list, User $author): ProjectProgressNote
    {
        return $project->notes()->forceCreate(['created_by' => $author->id, 'title' => 'Expense', 'content' => 'Expense',
            'noted_at' => '2026-11-01', 'shopping_list_id' => $list->id, 'shopping_request_hash' => str_repeat('a', 64)]);
    }

    public function test_status_is_per_user_and_deleted_notes_keep_the_registration_reserved(): void
    {
        $owner = User::create(['username' => 'status-owner', 'pin' => '1234']);
        $member = User::create(['username' => 'status-member', 'pin' => '1234']);
        $project = $this->project($owner);
        $list = ShoppingList::create(['created_by' => $owner->id, 'visibility' => 'shared', 'status' => 'closed', 'closed_at' => now()]);
        $list->users()->attach($member->id);
        $this->expense($project, $list, $owner);
        Sanctum::actingAs($owner);
        $this->getJson('/api/shopping-lists')->assertOk()->assertJsonPath('data.0.has_expense', true)
            ->assertJsonPath('data.0.can_register_expense', false)->assertJsonMissingPath('data.0.expense_note_id');
        Sanctum::actingAs($member);
        $this->getJson('/api/shopping-lists')->assertOk()->assertJsonPath('data.0.has_expense', false)
            ->assertJsonPath('data.0.can_register_expense', true);
        $note = $this->expense($project, $list, $member);
        $note->delete();
        $this->getJson('/api/shopping-lists')->assertOk()->assertJsonPath('data.0.has_expense', true)
            ->assertJsonPath('data.0.can_register_expense', false);
        $this->getJson("/api/shopping-lists/{$list->id}")->assertOk()->assertJsonPath('data.has_expense', true);
    }

    public function test_public_visibility_alone_does_not_offer_expense_registration(): void
    {
        $owner = User::create(['username' => 'public-owner', 'pin' => '1234']);
        $viewer = User::create(['username' => 'public-viewer', 'pin' => '1234']);
        $list = ShoppingList::create(['created_by' => $owner->id, 'visibility' => 'public', 'status' => 'closed', 'closed_at' => now()]);
        Sanctum::actingAs($viewer);
        $this->getJson('/api/shopping-lists')->assertOk()->assertJsonPath('data.0.can_register_expense', false);
        $list->users()->attach($viewer->id);
        $this->getJson('/api/shopping-lists')->assertOk()->assertJsonPath('data.0.can_register_expense', true);
        $list->update(['closed_at' => null]);
        $this->getJson('/api/shopping-lists')->assertOk()->assertJsonPath('data.0.can_register_expense', false);
    }

    public function test_expense_status_uses_one_aggregate_query_regardless_of_page_size(): void
    {
        $owner = User::create(['username' => 'batch-owner', 'pin' => '1234']);
        $project = $this->project($owner);
        for ($i = 0; $i < 12; $i++) {
            $list = ShoppingList::create(['created_by' => $owner->id, 'status' => 'closed', 'closed_at' => now()]);
            if ($i % 2 === 0) $this->expense($project, $list, $owner);
        }
        Sanctum::actingAs($owner);
        DB::enableQueryLog();
        $counts = [];
        foreach ([1, 12] as $size) {
            DB::flushQueryLog();
            $this->getJson("/api/shopping-lists?per_page={$size}")->assertOk()->assertJsonCount($size, 'data');
            $queries = DB::getQueryLog();
            $counts[] = count($queries);
            $this->assertSame(1, count(array_filter($queries, fn ($query) => str_contains($query['query'], 'project_progress_notes'))));
        }
        DB::disableQueryLog();
        $this->assertSame($counts[0], $counts[1]);
    }
}
