<?php

namespace Tests\Unit;

use App\Support\NameNormalizer;
use PHPUnit\Framework\TestCase;

class NameNormalizerTest extends TestCase
{
    public function test_it_normalizes_romanian_diacritics_case_and_whitespace(): void
    {
        $this->assertSame('patrunjel', NameNormalizer::normalize(' PĂTRUNJEL '));
        $this->assertSame('sare si tarate', NameNormalizer::normalize("  Sare\tși   tărâțe  "));
        $this->assertSame('turtita', NameNormalizer::normalize('Ţurtiţă'));
    }
}
