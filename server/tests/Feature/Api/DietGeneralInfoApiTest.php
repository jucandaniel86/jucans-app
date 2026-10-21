<?php

namespace Tests\Feature\Api;

use App\Models\Diet;
use App\Models\Ingredient;
use App\Models\User;
use App\Services\DietImageStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DietGeneralInfoApiTest extends TestCase
{
    use RefreshDatabase;

    private function diet(): Diet
    {
        $user = User::create(['username' => 'daniel', 'pin' => '1234']);
        Sanctum::actingAs($user);
        Storage::fake('recipe_images', ['url' => 'https://api-jucans.codebuilders.ro']);

        return Diet::create(['name' => 'Dieta inițială', 'created_by' => $user->id, 'status' => 'draft']);
    }

    public function test_updates_general_information_without_touching_settings_or_status(): void
    {
        $diet = $this->diet();
        $structureId = DB::table('daily_structures')->insertGetId(['name' => 'Mic dejun', 'slug' => 'mic-dejun']);
        $structure = $diet->dailyStructure()->create(['daily_structure_id' => $structureId, 'position' => 1]);
        $source = $diet->sources()->create(['type' => 'website', 'title' => 'Sursă', 'url' => 'https://example.test']);
        $ingredient = Ingredient::create(['name' => 'Lapte']);
        $diet->ingredients()->attach($ingredient->id, ['status' => 'allowed', 'notes' => 'Păstrează']);

        $this->patchJson("/api/diets/{$diet->id}", [
            'name' => 'Dieta nouă', 'description' => 'Descriere', 'notes' => 'Observații',
            'status' => 'active', 'sources' => [], 'daily_structure' => [], 'ingredients' => [],
        ])->assertOk()->assertJsonPath('data.name', 'Dieta nouă')
            ->assertJsonPath('data.status', 'draft')->assertJsonCount(1, 'data.sources')
            ->assertJsonCount(1, 'data.daily_structure')->assertJsonCount(1, 'data.ingredients');

        $this->assertDatabaseHas('diets', ['id' => $diet->id, 'name' => 'Dieta nouă', 'description' => 'Descriere', 'notes' => 'Observații', 'status' => 'draft']);
        $this->assertDatabaseHas('diet_daily_structure', ['id' => $structure->id, 'position' => 1]);
        $this->assertDatabaseHas('diet_sources', ['id' => $source->id, 'title' => 'Sursă']);
        $this->assertDatabaseHas('diet_ingredients', ['diet_id' => $diet->id, 'ingredient_id' => $ingredient->id, 'notes' => 'Păstrează']);
    }

    public function test_creates_replaces_preserves_and_removes_thumbnail_using_multipart_method_override(): void
    {
        $diet = $this->diet();
        $created = $this->post("/api/diets/{$diet->id}", [
            '_method' => 'PATCH', 'name' => $diet->name,
            'thumbnail' => UploadedFile::fake()->image('diet.png', 900, 600),
        ], ['Accept' => 'application/json'])->assertOk();
        $first = $created->json('data.thumbnail');
        $this->assertMatchesRegularExpression('#^diets/[0-9a-f-]+\.webp$#', $first);
        $this->assertSame('https://api-jucans.codebuilders.ro/'.$first, $created->json('data.thumbnail_url'));
        Storage::disk('recipe_images')->assertExists($first);
        $dimensions = getimagesize(Storage::disk('recipe_images')->path($first));
        $this->assertSame([500, 500], [$dimensions[0], $dimensions[1]]);

        $this->post("/api/diets/{$diet->id}", [
            '_method' => 'PATCH', 'name' => 'Fără fișier nou', 'description' => '', 'notes' => '',
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.thumbnail', $first)
            ->assertJsonPath('data.description', null)->assertJsonPath('data.notes', null);
        Storage::disk('recipe_images')->assertExists($first);

        $replaced = $this->post("/api/diets/{$diet->id}", [
            '_method' => 'PATCH', 'name' => 'Altă imagine',
            'thumbnail' => UploadedFile::fake()->image('diet.jpg', 600, 900),
        ], ['Accept' => 'application/json'])->assertOk();
        $second = $replaced->json('data.thumbnail');
        $this->assertNotSame($first, $second);
        Storage::disk('recipe_images')->assertMissing($first);
        Storage::disk('recipe_images')->assertExists($second);
        $this->assertDatabaseHas('diets', ['id' => $diet->id, 'thumbnail' => $second]);

        $this->patchJson("/api/diets/{$diet->id}", ['name' => $diet->name, 'remove_thumbnail' => true])
            ->assertOk()->assertJsonPath('data.thumbnail', null)->assertJsonPath('data.thumbnail_url', null);
        Storage::disk('recipe_images')->assertMissing($second);
    }

    public function test_validates_name_nullable_text_and_thumbnail_without_losing_the_old_image(): void
    {
        $diet = $this->diet();
        $diet->update(['thumbnail' => 'diets/old.webp']);
        Storage::disk('recipe_images')->put('diets/old.webp', 'old');
        foreach (['', str_repeat('a', 256)] as $name) {
            $this->patchJson("/api/diets/{$diet->id}", ['name' => $name])->assertUnprocessable()->assertJsonValidationErrors('name');
        }
        $this->patchJson("/api/diets/{$diet->id}", ['name' => $diet->name, 'description' => [], 'notes' => []])
            ->assertUnprocessable()->assertJsonValidationErrors(['description', 'notes']);
        foreach ([UploadedFile::fake()->create('bad.txt', 1, 'text/plain'), UploadedFile::fake()->image('big.png')->size(8193)] as $file) {
            $this->post("/api/diets/{$diet->id}", ['_method' => 'PATCH', 'name' => $diet->name, 'thumbnail' => $file], ['Accept' => 'application/json'])
                ->assertUnprocessable()->assertJsonValidationErrors('thumbnail');
        }
        $this->assertDatabaseHas('diets', ['id' => $diet->id, 'thumbnail' => 'diets/old.webp']);
        Storage::disk('recipe_images')->assertExists('diets/old.webp');
    }

    public function test_image_cleanup_never_deletes_recipe_images_or_external_sources(): void
    {
        $this->diet();
        Storage::disk('recipe_images')->put('recipes/keep.webp', 'recipe');
        $images = app(DietImageStorage::class);
        $images->deleteOwned('recipes/keep.webp');
        $images->deleteOwned('diets/../recipes/keep.webp');
        Storage::disk('recipe_images')->assertExists('recipes/keep.webp');
        $this->assertSame('https://example.test/image.jpg', $images->url('https://example.test/image.jpg'));
    }

    public function test_requires_authentication_and_returns_not_found_for_an_unknown_diet(): void
    {
        $this->patchJson('/api/diets/1234', ['name' => 'Dieta'])->assertUnauthorized();
        $this->diet();
        $this->patchJson('/api/diets/1234', ['name' => 'Dieta'])->assertNotFound();
    }
}
