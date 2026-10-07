<?php

namespace Tests\Feature\Api;

use App\Models\FoodTag;
use App\Models\Ingredient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecipeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_recipe_list_is_paginated_and_contains_card_data_without_ingredients(): void
    {
        $user = $this->createUser('daniel');
        $user->update(['avatar' => 'avatar-01']);
        $tag = FoodTag::create(['name' => 'Ciorbă', 'emoji' => '🍲']);
        $ingredient = Ingredient::create(['name' => 'Cartofi']);
        $recipe = $user->recipes()->create([
            'name' => 'Ciorbă de cartofi',
            'description' => 'Descriere',
            'image' => 'recipes/test.webp',
            'url' => 'https://example.test/reteta',
        ]);
        $recipe->tags()->attach($tag);
        $recipe->recipeIngredients()->create([
            'ingredient_id' => $ingredient->id,
            'value' => 2,
            'unit' => 'piece',
            'raw_text' => '2 cartofi',
        ]);
        Sanctum::actingAs($user);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $response = $this->getJson('/api/recipes?per_page=20');

        $response
            ->assertOk()
            ->assertJsonPath('data.0.id', $recipe->id)
            ->assertJsonPath('data.0.creator.avatar', 'avatar-01')
            ->assertJsonPath('data.0.tags.0.emoji', '🍲')
            ->assertJsonPath('data.0.image', 'recipes/test.webp')
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonMissingPath('data.0.ingredients');

        $this->assertLessThanOrEqual(4, $queryCount);
    }

    public function test_recipe_list_supports_search_tag_intersection_and_name_sorting(): void
    {
        $user = $this->createUser('daniel');
        $quick = FoodTag::create(['name' => 'Rapid']);
        $oven = FoodTag::create(['name' => 'Cuptor']);
        $potato = Ingredient::create(['name' => 'Cartofi']);
        $bothTags = $user->recipes()->create(['name' => 'Z Supă']);
        $bothTags->tags()->attach([$quick->id, $oven->id]);
        $bothTags->recipeIngredients()->create([
            'ingredient_id' => $potato->id,
            'unit' => 'piece',
            'raw_text' => 'cartofi',
        ]);
        $quickOnly = $user->recipes()->create(['name' => 'A Salată']);
        $quickOnly->tags()->attach($quick);
        Sanctum::actingAs($user);

        $this->getJson('/api/recipes?search=cartofi')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $bothTags->id);

        $this->getJson("/api/recipes?tags={$quick->id},{$oven->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $bothTags->id);

        $this->getJson("/api/recipes?search=cartofi&tags={$quick->id},{$oven->id}&per_page=5")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $bothTags->id);

        $this->getJson("/api/recipes?search=salată&tags={$quick->id},{$oven->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);

        $this->getJson('/api/recipes?sort=name')
            ->assertOk()
            ->assertJsonPath('data.0.id', $quickOnly->id)
            ->assertJsonPath('data.1.id', $bothTags->id);
    }

    public function test_random_recipe_uses_tag_intersection_without_pagination(): void
    {
        $user = $this->createUser('daniel');
        $quick = FoodTag::create(['name' => 'Rapid']);
        $oven = FoodTag::create(['name' => 'Cuptor']);
        $bothTags = $user->recipes()->create(['name' => 'Ambele']);
        $bothTags->tags()->attach([$quick->id, $oven->id]);
        $quickOnly = $user->recipes()->create(['name' => 'Doar rapid']);
        $quickOnly->tags()->attach($quick);
        Sanctum::actingAs($user);

        $this->getJson('/api/recipes?random=true')
            ->assertOk()
            ->assertJsonStructure(['data' => ['id', 'name']])
            ->assertJsonMissingPath('meta');

        $this->getJson("/api/recipes?random=true&tags={$quick->id}")
            ->assertOk()
            ->assertJsonPath('data.tags.0.id', $quick->id);

        $this->getJson("/api/recipes?random=true&tags={$quick->id},{$oven->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $bothTags->id);
    }

    public function test_random_recipe_can_exclude_the_current_result_and_falls_back_when_alone(): void
    {
        $user = $this->createUser('daniel');
        $first = $user->recipes()->create(['name' => 'Prima']);
        $second = $user->recipes()->create(['name' => 'A doua']);
        Sanctum::actingAs($user);

        $this->getJson("/api/recipes?random=true&exclude={$first->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $second->id);

        $second->delete();

        $this->getJson("/api/recipes?random=true&exclude={$first->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $first->id);
    }

    public function test_random_recipe_returns_null_when_no_recipe_matches(): void
    {
        $tag = FoodTag::create(['name' => 'Fără rețete']);
        Sanctum::actingAs($this->createUser('daniel'));

        $this->getJson("/api/recipes?random=true&tags={$tag->id}")
            ->assertOk()
            ->assertExactJson(['data' => null]);
    }

    public function test_authenticated_user_can_create_a_recipe_with_tags_and_ingredients(): void
    {
        $user = $this->createUser('daniel');
        $otherUser = $this->createUser('other');
        $tag = FoodTag::create(['name' => 'Ciorbă']);
        $ingredient = Ingredient::create([
            'name' => 'Pătrunjel',
            'default_unit' => 'bunch',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/recipes', [
            'name' => 'Ciorbă de perișoare',
            'description' => 'Rețeta familiei',
            'created_by' => $otherUser->id,
            'tags' => [$tag->id],
            'ingredients' => [
                [
                    'ingredient_id' => $ingredient->id,
                    'value' => 2,
                    'unit' => 'bunch',
                    'raw_text' => 'două legături de pătrunjel',
                ],
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.creator.id', $user->id)
            ->assertJsonPath('data.tags.0.id', $tag->id)
            ->assertJsonPath('data.ingredients.0.id', $ingredient->id)
            ->assertJsonPath('data.ingredients.0.unit', 'bunch')
            ->assertJsonPath('data.ingredients.0.raw_text', 'două legături de pătrunjel')
            ->assertJsonPath('data.image', null)
            ->assertJsonPath('data.image_url', null);

        $recipeId = $response->json('data.id');

        $this->assertDatabaseHas('recipes', [
            'id' => $recipeId,
            'created_by' => $user->id,
        ]);
        $this->assertDatabaseHas('food_tag_recipe', [
            'recipe_id' => $recipeId,
            'food_tag_id' => $tag->id,
        ]);
        $this->assertDatabaseHas('recipe_ingredients', [
            'recipe_id' => $recipeId,
            'ingredient_id' => $ingredient->id,
            'unit' => 'bunch',
            'raw_text' => 'două legături de pătrunjel',
        ]);
    }

    public function test_recipe_ingredient_unit_must_be_configured(): void
    {
        $user = $this->createUser('daniel');
        $ingredient = Ingredient::create(['name' => 'Sare']);

        Sanctum::actingAs($user);

        $this->postJson('/api/recipes', [
            'name' => 'Test',
            'ingredients' => [
                [
                    'ingredient_id' => $ingredient->id,
                    'value' => 1,
                    'unit' => 'bucket',
                ],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ingredients.0.unit');

        $this->assertDatabaseCount('recipes', 0);
    }

    public function test_recipe_image_is_cropped_and_stored_as_a_webp(): void
    {
        Storage::fake('recipe_images', [
            'url' => config('filesystems.disks.recipe_images.url'),
        ]);
        Sanctum::actingAs($this->createUser('daniel'));

        $response = $this->post('/api/recipes', [
            'name' => 'Rețetă cu imagine',
            'image' => UploadedFile::fake()->image('reteta.png', 900, 600),
        ], ['Accept' => 'application/json']);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'Rețetă cu imagine');

        $path = $response->json('data.image');

        $this->assertIsString($path);
        $this->assertMatchesRegularExpression('/^recipes\/[0-9a-f-]+\.webp$/', $path);
        $this->assertStringEndsWith('/'.$path, $response->json('data.image_url'));
        $this->assertStringNotContainsString('/storage/', $response->json('data.image_url'));
        Storage::disk('recipe_images')->assertExists($path);

        $dimensions = getimagesize(Storage::disk('recipe_images')->path($path));
        $this->assertSame(500, $dimensions[0]);
        $this->assertSame(500, $dimensions[1]);
        $this->assertSame('image/webp', $dimensions['mime']);
    }

    public function test_recipe_image_can_be_replaced_and_removed(): void
    {
        Storage::fake('recipe_images', [
            'url' => config('filesystems.disks.recipe_images.url'),
        ]);
        $user = $this->createUser('daniel');
        $oldPath = 'recipes/old-image.webp';
        Storage::disk('recipe_images')->put($oldPath, 'old image');
        $recipe = $user->recipes()->create([
            'name' => 'Rețetă',
            'image' => $oldPath,
        ]);
        Sanctum::actingAs($user);

        $replaceResponse = $this->post("/api/recipes/{$recipe->id}", [
            '_method' => 'PATCH',
            'image' => UploadedFile::fake()->image('noua.jpg', 600, 900),
        ], ['Accept' => 'application/json']);

        $replaceResponse->assertOk();
        $newPath = $replaceResponse->json('data.image');
        Storage::disk('recipe_images')->assertMissing($oldPath);
        Storage::disk('recipe_images')->assertExists($newPath);

        $this->patchJson("/api/recipes/{$recipe->id}", ['remove_image' => true])
            ->assertOk()
            ->assertJsonPath('data.image', null)
            ->assertJsonPath('data.image_url', null);

        Storage::disk('recipe_images')->assertMissing($newPath);
        $this->assertNull($recipe->fresh()->image);
    }

    public function test_recipe_image_must_be_a_supported_image(): void
    {
        Storage::fake('recipe_images', [
            'url' => config('filesystems.disks.recipe_images.url'),
        ]);
        Sanctum::actingAs($this->createUser('daniel'));

        $this->post('/api/recipes', [
            'name' => 'Imagine invalidă',
            'image' => UploadedFile::fake()->create('document.txt', 20, 'text/plain'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');

        $this->assertDatabaseCount('recipes', 0);
        Storage::disk('recipe_images')->assertDirectoryEmpty('recipes');
    }

    public function test_recipe_creation_can_create_a_new_ingredient_transactionally(): void
    {
        $user = $this->createUser('daniel');

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/recipes', [
            'name' => 'Tăiței cu gochujang',
            'ingredients' => [
                [
                    'name' => 'Gochujang',
                    'default_unit' => 'tablespoon',
                    'value' => 2,
                    'unit' => 'tablespoon',
                    'raw_text' => '2 linguri gochujang',
                ],
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.ingredients.0.name', 'Gochujang')
            ->assertJsonPath('data.ingredients.0.value', '2.000')
            ->assertJsonPath('data.ingredients.0.unit', 'tablespoon')
            ->assertJsonPath('data.ingredients.0.raw_text', '2 linguri gochujang');

        $ingredientId = $response->json('data.ingredients.0.id');

        $this->assertDatabaseHas('ingredients', [
            'id' => $ingredientId,
            'normalized_name' => 'gochujang',
            'default_unit' => 'tablespoon',
        ]);
        $this->assertDatabaseHas('recipe_ingredients', [
            'ingredient_id' => $ingredientId,
            'value' => 2,
            'unit' => 'tablespoon',
            'raw_text' => '2 linguri gochujang',
        ]);
    }

    public function test_equivalent_new_ingredient_reuses_an_existing_normalized_ingredient(): void
    {
        $user = $this->createUser('daniel');
        $existingIngredient = Ingredient::create([
            'name' => 'Pătrunjel',
            'default_unit' => 'bunch',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/recipes', [
            'name' => 'Supă',
            'ingredients' => [
                [
                    'name' => ' PATRUNJEL ',
                    'default_unit' => 'gram',
                    'value' => 1,
                    'unit' => 'bunch',
                    'raw_text' => 'o legatura patrunjel',
                ],
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.ingredients.0.id', $existingIngredient->id)
            ->assertJsonPath('data.ingredients.0.name', 'Pătrunjel');

        $this->assertDatabaseCount('ingredients', 1);
        $this->assertDatabaseHas('ingredients', [
            'id' => $existingIngredient->id,
            'default_unit' => 'bunch',
        ]);
    }

    public function test_recipe_and_new_ingredients_roll_back_together(): void
    {
        Storage::fake('recipe_images', [
            'url' => config('filesystems.disks.recipe_images.url'),
        ]);
        Sanctum::actingAs($this->createUser('daniel'));

        $this->post('/api/recipes', [
            'name' => 'Duplicat',
            'image' => UploadedFile::fake()->image('reteta.jpg', 700, 500),
            'ingredients' => [
                [
                    'name' => 'Gochujang',
                    'unit' => 'tablespoon',
                ],
                [
                    'name' => ' GOCHUJANG ',
                    'unit' => 'tablespoon',
                ],
            ],
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ingredients.1');

        $this->assertDatabaseCount('recipes', 0);
        $this->assertDatabaseMissing('ingredients', ['normalized_name' => 'gochujang']);
        Storage::disk('recipe_images')->assertDirectoryEmpty('recipes');
    }

    public function test_new_ingredient_default_unit_must_be_configured(): void
    {
        Sanctum::actingAs($this->createUser('daniel'));

        $this->postJson('/api/recipes', [
            'name' => 'Test',
            'ingredients' => [
                [
                    'name' => 'Gochujang',
                    'default_unit' => 'bucket',
                    'unit' => 'tablespoon',
                ],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ingredients.0.default_unit');

        $this->assertDatabaseCount('recipes', 0);
        $this->assertDatabaseCount('ingredients', 0);
    }

    public function test_recipe_update_synchronizes_tags_and_ingredients(): void
    {
        $user = $this->createUser('daniel');
        $oldTag = FoodTag::create(['name' => 'Rapid']);
        $newTag = FoodTag::create(['name' => 'Cuptor']);
        $oldIngredient = Ingredient::create(['name' => 'Sare']);
        $newIngredient = Ingredient::create(['name' => 'Cartofi', 'default_unit' => 'gram']);
        $recipe = $user->recipes()->create([
            'name' => 'Rețetă inițială',
        ]);

        $recipe->tags()->attach($oldTag);
        $recipe->recipeIngredients()->create([
            'ingredient_id' => $oldIngredient->id,
            'value' => 1,
            'unit' => 'none',
            'raw_text' => 'sare',
        ]);

        Sanctum::actingAs($user);

        $this->patchJson("/api/recipes/{$recipe->id}", [
            'name' => 'Rețetă actualizată',
            'tags' => [$newTag->id],
            'ingredients' => [
                [
                    'ingredient_id' => $newIngredient->id,
                    'value' => 500,
                    'unit' => 'gram',
                    'raw_text' => '500 g cartofi',
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Rețetă actualizată')
            ->assertJsonPath('data.tags.0.id', $newTag->id)
            ->assertJsonPath('data.ingredients.0.id', $newIngredient->id);

        $this->assertDatabaseMissing('food_tag_recipe', [
            'recipe_id' => $recipe->id,
            'food_tag_id' => $oldTag->id,
        ]);
        $this->assertDatabaseHas('food_tag_recipe', [
            'recipe_id' => $recipe->id,
            'food_tag_id' => $newTag->id,
        ]);
        $this->assertDatabaseMissing('recipe_ingredients', [
            'recipe_id' => $recipe->id,
            'ingredient_id' => $oldIngredient->id,
        ]);
        $this->assertDatabaseHas('recipe_ingredients', [
            'recipe_id' => $recipe->id,
            'ingredient_id' => $newIngredient->id,
            'value' => 500,
            'unit' => 'gram',
            'raw_text' => '500 g cartofi',
        ]);
    }

    public function test_multipart_update_can_clear_all_tags_and_ingredients(): void
    {
        $user = $this->createUser('daniel');
        $tag = FoodTag::create(['name' => 'Rapid']);
        $ingredient = Ingredient::create(['name' => 'Sare']);
        $recipe = $user->recipes()->create(['name' => 'Test']);
        $recipe->tags()->attach($tag);
        $recipe->recipeIngredients()->create([
            'ingredient_id' => $ingredient->id,
            'unit' => 'none',
        ]);
        Sanctum::actingAs($user);

        $this->post("/api/recipes/{$recipe->id}", [
            '_method' => 'PATCH',
            'tags_present' => '1',
            'ingredients_present' => '1',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonCount(0, 'data.tags')
            ->assertJsonCount(0, 'data.ingredients');

        $this->assertDatabaseMissing('food_tag_recipe', ['recipe_id' => $recipe->id]);
        $this->assertDatabaseMissing('recipe_ingredients', ['recipe_id' => $recipe->id]);
    }

    public function test_recipe_detail_preserves_complete_editable_ingredient_data(): void
    {
        $user = $this->createUser('daniel');
        $ingredient = Ingredient::create(['name' => 'Pătrunjel', 'default_unit' => 'bunch']);
        $recipe = $user->recipes()->create(['name' => 'Supă']);
        $recipe->recipeIngredients()->create([
            'ingredient_id' => $ingredient->id,
            'value' => 1.5,
            'unit' => 'bunch',
            'raw_text' => 'o legătură și jumătate de pătrunjel',
        ]);
        Sanctum::actingAs($user);

        $this->getJson("/api/recipes/{$recipe->id}")
            ->assertOk()
            ->assertJsonPath('data.ingredients.0.id', $ingredient->id)
            ->assertJsonPath('data.ingredients.0.name', 'Pătrunjel')
            ->assertJsonPath('data.ingredients.0.value', '1.500')
            ->assertJsonPath('data.ingredients.0.unit', 'bunch')
            ->assertJsonPath(
                'data.ingredients.0.raw_text',
                'o legătură și jumătate de pătrunjel'
            );
    }

    public function test_recipe_update_reuses_creation_logic_for_new_ingredients_and_duplicates(): void
    {
        $user = $this->createUser('daniel');
        $recipe = $user->recipes()->create(['name' => 'Inițial']);
        Sanctum::actingAs($user);

        $this->patchJson("/api/recipes/{$recipe->id}", [
            'ingredients' => [
                [
                    'name' => 'Gochujang',
                    'default_unit' => 'tablespoon',
                    'value' => 2,
                    'unit' => 'tablespoon',
                    'raw_text' => '2 linguri gochujang',
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.ingredients.0.name', 'Gochujang')
            ->assertJsonPath('data.ingredients.0.raw_text', '2 linguri gochujang');

        $this->patchJson("/api/recipes/{$recipe->id}", [
            'ingredients' => [
                ['name' => 'Sare', 'unit' => 'none'],
                ['name' => ' SARE ', 'unit' => 'none'],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ingredients.1');

        $this->assertDatabaseHas('recipe_ingredients', [
            'recipe_id' => $recipe->id,
            'raw_text' => '2 linguri gochujang',
        ]);
    }

    public function test_recipe_delete_removes_owned_image_and_pivots_but_keeps_canonical_data(): void
    {
        Storage::fake('recipe_images', [
            'url' => config('filesystems.disks.recipe_images.url'),
        ]);
        $user = $this->createUser('daniel');
        $tag = FoodTag::create(['name' => 'Rapid']);
        $ingredient = Ingredient::create(['name' => 'Sare']);
        $path = 'recipes/delete-me.webp';
        Storage::disk('recipe_images')->put($path, 'image');
        $recipe = $user->recipes()->create(['name' => 'Test', 'image' => $path]);
        $recipe->tags()->attach($tag);
        $recipe->recipeIngredients()->create([
            'ingredient_id' => $ingredient->id,
            'unit' => 'none',
        ]);
        Sanctum::actingAs($user);

        $this->deleteJson("/api/recipes/{$recipe->id}")->assertNoContent();

        Storage::disk('recipe_images')->assertMissing($path);
        $this->assertDatabaseMissing('recipes', ['id' => $recipe->id]);
        $this->assertDatabaseHas('food_tags', ['id' => $tag->id]);
        $this->assertDatabaseHas('ingredients', ['id' => $ingredient->id]);
        $this->assertDatabaseMissing('food_tag_recipe', ['recipe_id' => $recipe->id]);
        $this->assertDatabaseMissing('recipe_ingredients', ['recipe_id' => $recipe->id]);
    }

    public function test_authenticated_user_can_read_food_configuration(): void
    {
        Sanctum::actingAs($this->createUser('daniel'));

        $this->getJson('/api/config/food')
            ->assertOk()
            ->assertJsonPath('units.gram.label', 'g')
            ->assertJsonStructure(['units' => ['none', 'piece', 'gram', 'kilogram']]);
    }

    private function createUser(string $username): User
    {
        return User::create([
            'username' => $username,
            'pin' => '1234',
        ]);
    }
}
