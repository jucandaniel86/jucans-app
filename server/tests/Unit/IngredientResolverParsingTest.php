<?php

namespace Tests\Unit;

use App\Services\IngredientResolver;
use Tests\TestCase;

class IngredientResolverParsingTest extends TestCase
{
    public function test_it_parses_supported_quantities_and_romanian_units_conservatively(): void
    {
        $resolver = app(IngredientResolver::class);

        $cases = [
            '500 g carne tocata' => [500, 'gram', 'carne tocata'],
            '2 oua' => [2, null, 'oua'],
            'o legatura de patrunjel' => [1, 'bunch', 'patrunjel'],
            '3 catei de usturoi' => [3, 'clove', 'usturoi'],
            '1/2 kg cartofi' => [0.5, 'kilogram', 'cartofi'],
            'sare' => [null, null, 'sare'],
            'piper dupa gust' => [null, null, 'piper dupa gust'],
        ];

        foreach ($cases as $line => [$value, $unit, $ingredientText]) {
            $parsed = $resolver->parse($line);

            $this->assertSame($value, $parsed['value'], $line);
            $this->assertSame($unit, $parsed['unit'], $line);
            $this->assertSame($ingredientText, $parsed['ingredient_text'], $line);
            $this->assertSame($ingredientText, $parsed['normalized_text'], $line);
        }
    }

    public function test_unit_parsing_is_derived_from_configuration(): void
    {
        config()->set('food.units.gram.aliases', ['masura-test']);

        $parsed = app(IngredientResolver::class)->parse('2 masura-test cacao');

        $this->assertSame(2, $parsed['value']);
        $this->assertSame('gram', $parsed['unit']);
        $this->assertSame('cacao', $parsed['ingredient_text']);
    }

    public function test_quantity_words_are_only_interpreted_before_a_known_unit(): void
    {
        $parsed = app(IngredientResolver::class)->parse('o ceapa mare');

        $this->assertNull($parsed['value']);
        $this->assertNull($parsed['unit']);
        $this->assertSame('o ceapa mare', $parsed['ingredient_text']);
    }
}
