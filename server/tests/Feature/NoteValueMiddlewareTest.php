<?php

namespace Tests\Feature;

use App\Http\Middleware\ConvertEmptyStringsToNull;
use App\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

class NoteValueMiddlewareTest extends TestCase
{
    private function transform(string $method, string $path): array
    {
        $input = ['title' => '  Title  ', 'blank' => '', 'values' => [
            ['field_id' => 1, 'value' => "  Text\n"],
            ['field_id' => 2, 'value' => ''],
            ['field_id' => 3, 'value' => false],
            ['field_id' => 4, 'value' => 0],
        ]];
        $request = Request::create($path, $method, [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($input));
        $this->app->instance('request', $request);
        (new TrimStrings)->handle($request, fn ($request) => (new ConvertEmptyStringsToNull)->handle($request, fn () => new Response));

        return $request->all();
    }

    public function test_only_note_create_and_update_preserve_value_text_without_weakening_other_keys(): void
    {
        foreach ([['POST', '/api/projects/1/notes'], ['PATCH', '/api/projects/1/notes/2']] as [$method, $path]) {
            $result = $this->transform($method, $path);
            $this->assertSame("  Text\n", $result['values'][0]['value']);
            $this->assertSame('', $result['values'][1]['value']);
            $this->assertFalse($result['values'][2]['value']);
            $this->assertSame(0, $result['values'][3]['value']);
            $this->assertSame('Title', $result['title']);
            $this->assertNull($result['blank']);
        }
    }

    public function test_unrelated_routes_methods_and_note_lifecycle_paths_keep_normal_transformations(): void
    {
        foreach ([['POST', '/api/recipes'], ['POST', '/api/projects/1/notes/2/restore'], ['GET', '/api/projects/1/notes'], ['POST', '/api/projects/1/notes/2'], ['PATCH', '/api/projects/1/notes'], ['POST', '/api/projects/1/notebooks']] as [$method, $path]) {
            $result = $this->transform($method, $path);
            $this->assertSame('Text', $result['values'][0]['value']);
            $this->assertNull($result['values'][1]['value']);
            $this->assertFalse($result['values'][2]['value']);
            $this->assertSame(0, $result['values'][3]['value']);
        }
    }
}
