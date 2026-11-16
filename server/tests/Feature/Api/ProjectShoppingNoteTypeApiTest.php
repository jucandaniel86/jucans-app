<?php

namespace Tests\Feature\Api;

use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectCustomField;
use App\Models\ProjectNoteType;
use App\Models\User;
use App\Services\ProjectNoteConfigurationService;
use App\Services\ProjectService;
use Database\Seeders\ProjectCategorySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectShoppingNoteTypeApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Project $project;
    private ProjectNoteType $type;
    private array $mapping;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProjectCategorySeeder::class);
        $this->owner = User::create(['username' => 'shopping-config-owner', 'pin' => '1234']);
        Sanctum::actingAs($this->owner);
        $this->project = app(ProjectService::class)->create($this->owner, [
            'name' => 'Expenses', 'category_id' => ProjectCategory::firstOrFail()->id,
        ]);
        $service = app(ProjectNoteConfigurationService::class);
        $this->type = $service->write($this->owner, $this->project, function ($project) use ($service) {
            $type = $service->saveType($project, ['name' => 'Shopping']);
            $amount = $service->saveField($project, ['name' => 'Amount', 'key' => 'amount', 'type' => 'number', 'unit' => 'RON']);
            $provider = $service->saveField($project, ['name' => 'Provider', 'key' => 'provider', 'type' => 'select']);
            $option = $service->saveOption($provider, ['label' => 'Shopping']);
            $service->attach($project, $type, $amount, []);
            $service->attach($project, $type, $provider, []);
            $this->mapping = ['is_shopping' => true, 'shopping_amount_field_id' => $amount->id,
                'shopping_provider_field_id' => $provider->id, 'shopping_provider_option_id' => $option->id];
            return $type;
        });
    }

    private function url(?int $id = null): string
    {
        return "/api/projects/{$this->project->id}/note-types/".($id ?? $this->type->id);
    }

    public function test_owner_can_enable_read_patch_and_disable_mapping_without_creating_expenses(): void
    {
        $this->patchJson($this->url(), $this->mapping)->assertOk()->assertJsonPath('data.is_shopping', true)
            ->assertJsonPath('data.shopping_provider_option_id', $this->mapping['shopping_provider_option_id']);
        $this->patchJson($this->url(), ['description' => 'Updated'])->assertOk()
            ->assertJsonPath('data.is_shopping', true)->assertJsonPath('data.shopping_amount_field_id', $this->mapping['shopping_amount_field_id']);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.is_shopping', true);
        $this->patchJson($this->url(), ['is_shopping' => false])->assertOk()
            ->assertJsonPath('data.is_shopping', false)->assertJsonPath('data.shopping_amount_field_id', null)
            ->assertJsonPath('data.shopping_provider_field_id', null)->assertJsonPath('data.shopping_provider_option_id', null);
        $this->assertDatabaseCount('project_tasks', 0);
        $this->assertDatabaseCount('project_progress_notes', 0);
        $this->assertDatabaseCount('shopping_lists', 0);
    }

    public function test_only_one_shopping_type_and_database_unique_slot(): void
    {
        $this->patchJson($this->url(), $this->mapping)->assertOk();
        $other = $this->project->noteTypes()->create(['name' => 'Other']);
        $service = app(ProjectNoteConfigurationService::class);
        foreach (['shopping_amount_field_id', 'shopping_provider_field_id'] as $key) {
            $service->attach($this->project, $other, ProjectCustomField::findOrFail($this->mapping[$key]), []);
        }
        $this->patchJson($this->url($other->id), $this->mapping)->assertUnprocessable()->assertJsonValidationErrors('is_shopping');
        $this->expectException(QueryException::class);
        DB::table('project_note_types')->where('id', $other->id)->update(['is_shopping' => '1']);
    }

    public function test_rejects_wrong_types_detached_archived_and_foreign_fields(): void
    {
        $this->patchJson($this->url(), [...$this->mapping, 'shopping_provider_field_id' => $this->mapping['shopping_amount_field_id']])
            ->assertUnprocessable()->assertJsonValidationErrors('shopping_provider_field_id');
        $this->patchJson($this->url(), [...$this->mapping, 'shopping_amount_field_id' => $this->mapping['shopping_provider_field_id']])
            ->assertUnprocessable()->assertJsonValidationErrors('shopping_amount_field_id');
        DB::table('project_note_type_fields')->where('note_type_id', $this->type->id)
            ->where('field_id', $this->mapping['shopping_amount_field_id'])->update(['active_slot' => null]);
        $this->patchJson($this->url(), $this->mapping)->assertUnprocessable()->assertJsonValidationErrors('shopping_amount_field_id');
        DB::table('project_note_type_fields')->where('note_type_id', $this->type->id)
            ->where('field_id', $this->mapping['shopping_amount_field_id'])->update(['active_slot' => '1']);
        ProjectCustomField::findOrFail($this->mapping['shopping_amount_field_id'])->forceFill(['archived_at' => now()])->save();
        $this->patchJson($this->url(), $this->mapping)->assertUnprocessable()->assertJsonValidationErrors('shopping_amount_field_id');
        $other = app(ProjectService::class)->create($this->owner, ['name' => 'Other', 'category_id' => ProjectCategory::firstOrFail()->id]);
        $foreign = $other->customFields()->create(['name' => 'Foreign', 'key' => 'foreign', 'type' => 'number']);
        $this->patchJson($this->url(), [...$this->mapping, 'shopping_amount_field_id' => $foreign->id])
            ->assertUnprocessable()->assertJsonValidationErrors('shopping_amount_field_id');
    }

    public function test_rejects_missing_archived_and_wrong_provider_options(): void
    {
        $this->patchJson($this->url(), ['is_shopping' => true])->assertUnprocessable();
        $this->patchJson($this->url(), [...$this->mapping, 'shopping_provider_option_id' => 999999])->assertUnprocessable();
        $other = $this->project->customFields()->create(['name' => 'Other', 'key' => 'other', 'type' => 'select']);
        $option = $other->options()->create(['label' => 'Other']);
        $this->patchJson($this->url(), [...$this->mapping, 'shopping_provider_option_id' => $option->id])
            ->assertUnprocessable()->assertJsonValidationErrors('shopping_provider_option_id');
        DB::table('project_custom_field_options')->where('id', $this->mapping['shopping_provider_option_id'])->update(['archived_at' => now()]);
        $this->patchJson($this->url(), $this->mapping)->assertUnprocessable()->assertJsonValidationErrors('shopping_provider_option_id');
    }

    public function test_collaborator_can_read_but_cannot_configure(): void
    {
        $user = User::create(['username' => 'shopping-viewer', 'pin' => '1234']);
        $this->project->collaborators()->create(['user_id' => $user->id, 'sharing_mode' => 'all_tasks']);
        Sanctum::actingAs($user);
        $this->getJson($this->url())->assertOk();
        $this->patchJson($this->url(), $this->mapping)->assertForbidden();
    }

    public function test_archiving_releases_slot_and_restoring_does_not_reenable(): void
    {
        $this->patchJson($this->url(), $this->mapping)->assertOk();
        $other = $this->project->noteTypes()->create(['name' => 'Other']);
        $this->postJson($this->url($other->id).'/set-default')->assertOk();
        $this->postJson($this->url().'/archive')->assertOk()->assertJsonPath('data.is_shopping', false);
        $this->patchJson($this->url(), $this->mapping)->assertUnprocessable();
        $service = app(ProjectNoteConfigurationService::class);
        foreach (['shopping_amount_field_id', 'shopping_provider_field_id'] as $key) {
            $service->attach($this->project, $other, ProjectCustomField::findOrFail($this->mapping[$key]), []);
        }
        $this->patchJson($this->url($other->id), $this->mapping)->assertOk();
        $this->postJson($this->url().'/restore')->assertOk()->assertJsonPath('data.is_shopping', false);
        $this->patchJson($this->url(), $this->mapping)->assertUnprocessable();
        $this->patchJson($this->url($other->id), ['is_shopping' => false])->assertOk();
        $this->patchJson($this->url(), $this->mapping)->assertOk();
    }
    public function test_database_rejects_cross_project_mapping(): void
    {
        $other = app(ProjectService::class)->create($this->owner, ['name' => 'Other', 'category_id' => ProjectCategory::firstOrFail()->id]);
        $field = $other->customFields()->create(['name' => 'Foreign', 'key' => 'foreign', 'type' => 'number']);
        $this->expectException(QueryException::class);
        DB::table('project_note_types')->where('id', $this->type->id)->update(['shopping_amount_field_id' => $field->id]);
    }

    public function test_migration_rolls_back_and_reapplies_without_changing_existing_types(): void
    {
        $migration = require database_path('migrations/2026_11_15_000001_add_shopping_configuration_to_project_note_types.php');
        $migration->down();
        $this->assertSame('Shopping', DB::table('project_note_types')->where('id', $this->type->id)->value('name'));
        $migration->up();
        $this->patchJson($this->url(), $this->mapping)->assertOk();
    }

}
