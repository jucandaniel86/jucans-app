<?php

namespace Tests\Feature\Api;

use App\Models\ProjectCategory;
use App\Models\User;
use App\Services\ProjectService;
use App\Services\ProjectTaskService;
use Database\Seeders\ProjectCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectTaskSortingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_overdue_sort_is_applied_before_pagination_without_changing_default_sort(): void
    {
        $this->travelTo(Carbon::parse('2026-01-10 12:00:00', 'UTC'));
        $this->seed(ProjectCategorySeeder::class);
        $owner = User::create(['username' => 'owner', 'pin' => '1234']);
        $project = app(ProjectService::class)->create($owner, ['name' => 'Project', 'category_id' => ProjectCategory::firstOrFail()->id]);
        $tasks = app(ProjectTaskService::class);
        $future = $tasks->create($owner, $project, ['title' => 'Future urgent', 'priority' => 'urgent', 'due_date' => '2026-01-11']);
        $low = $tasks->create($owner, $project, ['title' => 'Overdue low', 'priority' => 'low', 'due_date' => '2026-01-09']);
        $high = $tasks->create($owner, $project, ['title' => 'Overdue high', 'priority' => 'high', 'scheduled_date' => '2026-01-10', 'scheduled_time' => '10:00']);
        $tasks->create($owner, $project, ['title' => 'Date only today', 'due_date' => '2026-01-10', 'priority' => 'urgent']);
        Sanctum::actingAs($owner);
        $url = '/api/projects/'.$project->id.'/tasks';
        $this->getJson($url.'?sort=overdue&per_page=1')->assertOk()->assertJsonPath('data.0.id', $high->id)->assertJsonPath('meta.total', 4);
        $this->getJson($url.'?sort=overdue&per_page=1&page=2')->assertOk()->assertJsonPath('data.0.id', $low->id);
        $this->getJson($url.'?sort=priority')->assertOk()->assertJsonPath('data.1.id', $future->id);
        $other = User::create(['username' => 'other', 'pin' => '1234']);
        Sanctum::actingAs($other);
        $this->getJson($url.'?sort=overdue')->assertForbidden();
    }
}
