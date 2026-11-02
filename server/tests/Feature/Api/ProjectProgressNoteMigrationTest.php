<?php

namespace Tests\Feature\Api;

use App\Models\ProjectCategory;
use App\Models\User;
use App\Services\ProjectService;
use Database\Seeders\ProjectCategorySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\DisposableDatabaseMigrations;
use Tests\Support\IsolatedDatabase;
use Tests\TestCase;

/** DDL must never run inside RefreshDatabase's MySQL transaction. */
class ProjectProgressNoteMigrationTest extends TestCase
{
    use DisposableDatabaseMigrations;

    public function test_up_down_reapply_preserves_existing_legacy_notes_without_backfill(): void
    {
        IsolatedDatabase::assertSafe($this->app);
        $this->assertSame(0, DB::transactionLevel());
        $this->seed(ProjectCategorySeeder::class);
        $owner = User::create(['username' => 'migration-owner', 'pin' => '1234']);
        $project = app(ProjectService::class)->create($owner, ['name' => 'Legacy', 'category_id' => ProjectCategory::firstOrFail()->id]);
        $migration = require database_path('migrations/2026_10_31_000001_add_progress_note_values.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('project_progress_notes', 'note_type_id'));
        $id = DB::table('project_progress_notes')->insertGetId(['project_id' => $project->id, 'created_by' => $owner->id, 'title' => 'Legacy', 'content' => 'Original', 'noted_at' => '2020-01-01']);
        foreach ([1, 2] as $pass) {
            $migration->up();
            $note = DB::table('project_progress_notes')->find($id);
            $this->assertNull($note->note_type_id);
            $this->assertNull($note->configuration_snapshot);
            $this->assertNull($note->configuration_captured_at);
            $this->assertSame('Original', $note->content);
            $this->assertSame(0, DB::table('project_progress_note_values')->count());
            if ($pass === 1) {
                $migration->down();
            }
        }
        IsolatedDatabase::assertSafe($this->app);
    }
}
