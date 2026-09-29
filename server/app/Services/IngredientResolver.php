<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Support\NameNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class IngredientResolver
{
    private const CANDIDATE_LIMIT = 5;

    /** @var array<string, string> */
    private array $unitAliases;

    public function __construct()
    {
        $aliases = [];

        foreach (config('food.units', []) as $unit => $configuration) {
            foreach ($configuration['aliases'] ?? [] as $alias) {
                $normalizedAlias = NameNormalizer::normalize($alias);

                if ($normalizedAlias !== '') {
                    $aliases[$normalizedAlias] = $unit;
                }
            }
        }

        uksort($aliases, fn (string $left, string $right) => mb_strlen($right) <=> mb_strlen($left));

        $this->unitAliases = $aliases;
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, array<string, mixed>>
     */
    public function resolve(array $lines): array
    {
        $parsedLines = array_map(fn (string $line) => $this->parse($line), $lines);
        $normalizedNames = collect($parsedLines)
            ->pluck('normalized_text')
            ->filter()
            ->unique()
            ->values();

        $exactMatches = Ingredient::query()
            ->whereIn('normalized_name', $normalizedNames)
            ->get(['id', 'name', 'normalized_name', 'default_unit'])
            ->keyBy('normalized_name');

        $unmatchedNames = $normalizedNames
            ->reject(fn (string $name) => $exactMatches->has($name))
            ->values();
        $aliasMatches = Ingredient::query()
            ->join('ingredient_aliases', 'ingredient_aliases.ingredient_id', '=', 'ingredients.id')
            ->whereIn('ingredient_aliases.normalized_alias', $unmatchedNames)
            ->get([
                'ingredients.id',
                'ingredients.name',
                'ingredients.normalized_name',
                'ingredients.default_unit',
                'ingredient_aliases.normalized_alias as matched_alias',
            ])
            ->keyBy('matched_alias');
        $candidateNames = $unmatchedNames
            ->reject(fn (string $name) => $aliasMatches->has($name))
            ->values();
        $candidatePool = $this->candidatePool($candidateNames);

        return array_map(
            fn (array $parsed) => $this->resultFor($parsed, $exactMatches, $aliasMatches, $candidatePool),
            $parsedLines
        );
    }

    /** @return array{raw_text: string, value: int|float|null, unit: string|null, ingredient_text: string, normalized_text: string} */
    public function parse(string $line): array
    {
        $rawText = trim($line);
        $normalizedLine = NameNormalizer::normalize($rawText);
        $value = null;
        $unit = null;
        $ingredientText = $normalizedLine;

        if (preg_match('/^(\d+\s*\/\s*\d+|\d+(?:[.,]\d+)?)\s+(.+)$/u', $normalizedLine, $matches)) {
            $parsedValue = $this->parseQuantity($matches[1]);

            if ($parsedValue !== null) {
                $value = $parsedValue;
                [$unit, $ingredientText] = $this->extractUnit($matches[2]);
            }
        } elseif (preg_match('/^(?:o|un)\s+(.+)$/u', $normalizedLine, $matches)) {
            [$detectedUnit, $remainingText] = $this->extractUnit($matches[1]);

            if ($detectedUnit !== null) {
                $value = 1;
                $unit = $detectedUnit;
                $ingredientText = $remainingText;
            }
        }

        $ingredientText = trim($ingredientText);

        return [
            'raw_text' => $rawText,
            'value' => $value,
            'unit' => $unit,
            'ingredient_text' => $ingredientText,
            'normalized_text' => NameNormalizer::normalize($ingredientText),
        ];
    }

    /** @return array{0: string|null, 1: string} */
    private function extractUnit(string $text): array
    {
        foreach ($this->unitAliases as $alias => $unit) {
            if ($text !== $alias && ! str_starts_with($text, $alias.' ')) {
                continue;
            }

            $remainingText = trim(mb_substr($text, mb_strlen($alias)));

            if ($remainingText === 'de') {
                $remainingText = '';
            } elseif (str_starts_with($remainingText, 'de ')) {
                $remainingText = trim(mb_substr($remainingText, 3));
            }

            return [$unit, $remainingText];
        }

        return [null, $text];
    }

    private function parseQuantity(string $quantity): int|float|null
    {
        $quantity = preg_replace('/\s+/', '', $quantity) ?? $quantity;

        if (str_contains($quantity, '/')) {
            [$numerator, $denominator] = array_map('intval', explode('/', $quantity, 2));

            return $denominator === 0 ? null : $numerator / $denominator;
        }

        $quantity = str_replace(',', '.', $quantity);

        return str_contains($quantity, '.') ? (float) $quantity : (int) $quantity;
    }

    /**
     * @param  Collection<int, string>  $names
     * @return Collection<int, Ingredient>
     */
    private function candidatePool(Collection $names): Collection
    {
        if ($names->isEmpty()) {
            return collect();
        }

        $patterns = $names
            ->flatMap(function (string $name): array {
                $firstWord = explode(' ', $name, 2)[0];

                return mb_strlen($firstWord) >= 3 ? [$name, $firstWord] : [$name];
            })
            ->unique()
            ->values();

        return Ingredient::query()
            ->where(function (Builder $query) use ($patterns): void {
                foreach ($patterns as $pattern) {
                    $query->orWhere('normalized_name', 'like', '%'.$this->escapeLike($pattern).'%');
                }
            })
            ->orderBy('normalized_name')
            ->get(['id', 'name', 'normalized_name', 'default_unit']);
    }

    /**
     * @param  Collection<string, Ingredient>  $exactMatches
     * @param  Collection<string, Ingredient>  $aliasMatches
     * @param  Collection<int, Ingredient>  $candidatePool
     * @return array<string, mixed>
     */
    private function resultFor(
        array $parsed,
        Collection $exactMatches,
        Collection $aliasMatches,
        Collection $candidatePool
    ): array {
        $exactMatch = $exactMatches->get($parsed['normalized_text']);

        if ($exactMatch) {
            return $this->formatResult($parsed, 'matched', $exactMatch, collect());
        }

        $aliasMatch = $aliasMatches->get($parsed['normalized_text']);

        if ($aliasMatch) {
            return $this->formatResult($parsed, 'matched', $aliasMatch, collect());
        }

        $candidates = $candidatePool
            ->filter(fn (Ingredient $ingredient) => $this->isConservativeCandidate(
                $parsed['normalized_text'],
                $ingredient->normalized_name
            ))
            ->take(self::CANDIDATE_LIMIT)
            ->values();

        return $this->formatResult(
            $parsed,
            $candidates->isEmpty() ? 'unresolved' : 'candidates',
            null,
            $candidates
        );
    }

    /** @return array<string, mixed> */
    private function formatResult(
        array $parsed,
        string $status,
        ?Ingredient $ingredient,
        Collection $candidates
    ): array {
        return [
            'raw_text' => $parsed['raw_text'],
            'parsed' => [
                'value' => $parsed['value'],
                'unit' => $parsed['unit'],
                'ingredient_text' => $parsed['ingredient_text'],
                'normalized_text' => $parsed['normalized_text'],
            ],
            'status' => $status,
            'ingredient' => $ingredient ? $this->ingredientData($ingredient) : null,
            'candidates' => $candidates->map(fn (Ingredient $candidate) => $this->ingredientData($candidate))->all(),
        ];
    }

    /** @return array{id: int, name: string, default_unit: string|null} */
    private function ingredientData(Ingredient $ingredient): array
    {
        return [
            'id' => $ingredient->id,
            'name' => $ingredient->name,
            'default_unit' => $ingredient->default_unit,
        ];
    }

    private function isConservativeCandidate(string $input, string $candidate): bool
    {
        if ($input === '' || $candidate === '') {
            return false;
        }

        return $this->containsPhrase($candidate, $input)
            || $this->containsPhrase($input, $candidate);
    }

    private function containsPhrase(string $haystack, string $needle): bool
    {
        return preg_match('/(?:^|\s)'.preg_quote($needle, '/').'(?:$|\s)/u', $haystack) === 1;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
