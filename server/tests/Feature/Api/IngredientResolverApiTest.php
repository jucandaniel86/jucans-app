<?php

namespace Tests\Feature\Api;

use App\Models\Ingredient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IngredientResolverApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authentication_is_required(): void
    {
        $this->postJson('/api/ingredients/resolve', [
            'ingredients' => ['sare'],
        ])->assertUnauthorized();
    }

    public function test_it_resolves_multiple_lines_in_order_without_writing_to_the_database(): void
    {
        $parsley = Ingredient::create([
            'name' => 'Pătrunjel',
            'default_unit' => 'bunch',
        ]);
        $eggs = Ingredient::create([
            'name' => 'Ouă',
            'default_unit' => 'piece',
        ]);
        $ingredientCount = Ingredient::count();

        Sanctum::actingAs($this->createUser());

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $response = $this->postJson('/api/ingredients/resolve', [
            'ingredients' => [
                '  o legătură de pătrunjel  ',
                '',
                '2 OUA',
                '2 linguri gochujang',
            ],
        ]);
        $resolverQueryCount = $queryCount;

        $response
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.raw_text', 'o legătură de pătrunjel')
            ->assertJsonPath('data.0.status', 'matched')
            ->assertJsonPath('data.0.ingredient.id', $parsley->id)
            ->assertJsonPath('data.0.parsed.value', 1)
            ->assertJsonPath('data.0.parsed.unit', 'bunch')
            ->assertJsonPath('data.1.raw_text', '2 OUA')
            ->assertJsonPath('data.1.status', 'matched')
            ->assertJsonPath('data.1.ingredient.id', $eggs->id)
            ->assertJsonPath('data.1.parsed.value', 2)
            ->assertJsonPath('data.1.parsed.unit', null)
            ->assertJsonPath('data.2.raw_text', '2 linguri gochujang')
            ->assertJsonPath('data.2.status', 'unresolved')
            ->assertJsonPath('data.2.ingredient', null)
            ->assertJsonPath('data.2.parsed.unit', 'tablespoon')
            ->assertJsonPath('data.2.parsed.normalized_text', 'gochujang');

        $this->assertSame($ingredientCount, Ingredient::count());
        $this->assertLessThanOrEqual(3, $resolverQueryCount);
    }

    public function test_exact_matching_works_across_romanian_diacritics(): void
    {
        $ingredient = Ingredient::create([
            'name' => 'Pătrunjel',
            'default_unit' => 'bunch',
        ]);

        Sanctum::actingAs($this->createUser());

        $this->postJson('/api/ingredients/resolve', [
            'ingredients' => ['2 PATRUNJEL'],
        ])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'matched')
            ->assertJsonPath('data.0.ingredient.id', $ingredient->id)
            ->assertJsonPath('data.0.ingredient.default_unit', 'bunch')
            ->assertJsonPath('data.0.parsed.value', 2)
            ->assertJsonPath('data.0.parsed.unit', null);
    }

    public function test_partial_matches_are_candidates_and_never_automatic_matches(): void
    {
        $bellPepper = Ingredient::create(['name' => 'Ardei gras', 'default_unit' => 'piece']);
        $hotPepper = Ingredient::create(['name' => 'Ardei iute', 'default_unit' => 'piece']);
        $cayenne = Ingredient::create(['name' => 'Piper cayenne', 'default_unit' => 'gram']);

        Sanctum::actingAs($this->createUser());

        $response = $this->postJson('/api/ingredients/resolve', [
            'ingredients' => ['1 ardei', 'piper'],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.0.status', 'candidates')
            ->assertJsonPath('data.0.ingredient', null)
            ->assertJsonCount(2, 'data.0.candidates')
            ->assertJsonPath('data.1.status', 'candidates')
            ->assertJsonPath('data.1.ingredient', null)
            ->assertJsonCount(1, 'data.1.candidates');

        $pepperCandidateIds = collect($response->json('data.0.candidates'))->pluck('id');

        $this->assertEqualsCanonicalizing([$bellPepper->id, $hotPepper->id], $pepperCandidateIds->all());
        $this->assertSame($cayenne->id, $response->json('data.1.candidates.0.id'));
    }

    public function test_request_limits_and_empty_line_cleanup_are_applied(): void
    {
        Sanctum::actingAs($this->createUser());

        $this->postJson('/api/ingredients/resolve', [
            'ingredients' => ['', '   '],
        ])->assertUnprocessable()->assertJsonValidationErrors('ingredients');

        $this->postJson('/api/ingredients/resolve', [
            'ingredients' => [str_repeat('a', 501)],
        ])->assertUnprocessable()->assertJsonValidationErrors('ingredients.0');

        $this->postJson('/api/ingredients/resolve', [
            'ingredients' => array_fill(0, 101, 'sare'),
        ])->assertUnprocessable()->assertJsonValidationErrors('ingredients');
    }

    private function createUser(): User
    {
        return User::create([
            'username' => 'daniel',
            'pin' => '1234',
        ]);
    }
}
