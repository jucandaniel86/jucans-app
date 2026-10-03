<?php

namespace App\Exceptions;

use App\Services\IngredientMergeAnalysis;
use RuntimeException;

class IngredientMergeBlockedException extends RuntimeException
{
    public function __construct(public readonly IngredientMergeAnalysis $analysis)
    {
        parent::__construct('Ingredient merge is blocked by conflicts.');
    }
}
