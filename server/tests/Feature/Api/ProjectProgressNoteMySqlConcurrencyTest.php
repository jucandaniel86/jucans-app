<?php

namespace Tests\Feature\Api;

use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectCustomField;
use App\Models\ProjectProgressNote;
use App\Models\User;
use App\Services\ProjectNoteConfigurationService;
use App\Services\ProjectProgressNoteService;
use App\Services\ProjectService;
use Database\Seeders\ProjectCategorySeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\DisposableDatabaseMigrations;
use Tests\Support\IsolatedDatabase;
use Tests\Support\MySqlWorker;
use Tests\TestCase;

class ProjectProgressNoteMySqlConcurrencyTest extends TestCase
{
    use DisposableDatabaseMigrations;

    private Project $project;

    private User $owner;

    private int $type;

    private array $fields = [];

    private array $workers = [];

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Real multi-process locking requires the guarded MySQL configuration.');
        }
        IsolatedDatabase::assertSafe($this->app);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, DB::transactionLevel(), 'Concurrency fixtures must be committed, not wrapped by RefreshDatabase.');
        $this->seed(ProjectCategorySeeder::class);
        $this->owner = User::create(['username' => 'mysql-concurrency-owner', 'pin' => '1234']);
        $this->project = app(ProjectService::class)->create($this->owner, ['name' => 'Concurrency', 'category_id' => ProjectCategory::firstOrFail()->id]);
        $service = app(ProjectNoteConfigurationService::class);
        $service->write($this->owner, $this->project, function ($project) use ($service) {
            $this->type = $service->saveType($project, ['name' => 'General', 'template' => 'Old template'])->id;
            foreach (['text', 'number', 'boolean'] as $kind) {
                $extra = $kind === 'number' ? ['unit' => 'points', 'min' => '0', 'max' => '10', 'step' => '1'] : [];
                $field = $service->saveField($project, ['name' => $kind, 'key' => $kind, 'type' => $kind, ...$extra]);
                $this->fields[$kind] = $field->id;
                $service->attach($project, $project->noteTypes()->find($this->type), $field, []);
            }
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            $worker->close();
        }
        if (isset($this->app) && $this->app && config('database.default') === 'mysql') {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        parent::tearDown();
    }

    private function payload(array $values = []): array
    {
        return ['title' => 'Original', 'content' => 'Original', 'noted_at' => '2020-01-01', 'note_type_id' => $this->type, 'values' => $this->valuesInput($values)];
    }

    private function valuesInput(array $values): array
    {
        return collect($values)->map(fn ($value, $kind) => ['field_id' => $this->fields[$kind], 'value' => $value])->values()->all();
    }

    private function note(array $values = []): ProjectProgressNote
    {
        return app(ProjectProgressNoteService::class)->create($this->owner, $this->project, $this->payload($values));
    }

    private function update(ProjectProgressNote $note, array $values): void
    {
        app(ProjectProgressNoteService::class)->update($this->owner, $this->project, $note, ['values' => $this->valuesInput($values)]);
    }

    private function holdProject(): void
    {
        DB::beginTransaction();
        DB::table('projects')->where('id', $this->project->id)->lockForUpdate()->first();
    }

    private function blockedWorker(array $operation): MySqlWorker
    {
        $worker = new MySqlWorker(['user_id' => $this->owner->id, 'project_id' => $this->project->id, ...$operation]);
        $this->workers[] = $worker;
        $attempt = $worker->waitFor('project_lock_attempt');
        $this->assertNotSame((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, $attempt['connection_id']);
        foreach ($worker->collect(0.25) as $event) {
            $this->assertNotSame('done', $event['event'], 'Worker must block while the project lock is held.');
            $this->assertNotSame('configuration_read', $event['event'], 'No relevant configuration may be read before acquiring the project lock.');
        }

        return $worker;
    }

    private function finish(MySqlWorker $worker, int $status = 200): array
    {
        $result = $worker->waitFor('done');
        $this->assertSame($status, $result['status'], json_encode($result));
        foreach ($worker->events as $event) {
            if ($event['event'] === 'configuration_read') {
                $this->assertTrue($event['after_lock_attempt']);
            }
        }

        return $result;
    }

    private function stored(ProjectProgressNote $note): array
    {
        return $note->fieldValues()->get()->mapWithKeys(fn ($row) => [$row->field_id => $row->typedValue()])->all();
    }

    public function test_configuration_commit_precedes_capture_and_produces_one_coherent_snapshot(): void
    {
        $this->holdProject();
        $service = app(ProjectNoteConfigurationService::class);
        $service->write($this->owner, $this->project, function ($project) use ($service) {
            $type = $project->noteTypes()->find($this->type);
            $service->saveType($project, ['template' => 'New template'], $type);
            $service->saveField($project, ['name' => 'New name'], $project->customFields()->find($this->fields['text']));
            $service->changeAssociation($type, $project->customFields()->find($this->fields['text']), ['required' => true, 'sort_order' => 5]);
        });
        $worker = $this->blockedWorker(['action' => 'create_note', 'payload' => $this->payload(['text' => 'Supplied', 'number' => 0, 'boolean' => false])]);
        DB::commit();
        $result = $this->finish($worker);
        $snapshot = ProjectProgressNote::find($result['id'])->configuration_snapshot;
        $this->assertSame('New template', $snapshot['note_type']['template']);
        $text = collect($snapshot['fields'])->firstWhere('field_id', $this->fields['text']);
        $this->assertSame('New name', $text['definition']['name']);
        $this->assertTrue($text['required']);
        $this->assertSame(5, $text['sort_order']);
    }

    public function test_note_capture_precedes_waiting_configuration_change_without_rebinding(): void
    {
        $this->holdProject();
        $note = $this->note(['text' => 'Original']);
        $worker = $this->blockedWorker(['action' => 'config', 'field_id' => $this->fields['text'], 'payload' => ['name' => 'Later name']]);
        DB::commit();
        $this->finish($worker);
        $this->assertSame('text', $note->fresh()->configuration_snapshot['fields'][0]['definition']['name']);
        $this->assertSame('Later name', ProjectCustomField::find($this->fields['text'])->name);
    }

    public function test_archive_type_and_unit_changes_are_seen_by_waiting_value_writes(): void
    {
        foreach (['archive', 'type', 'unit'] as $change) {
            $note = $this->note();
            $this->holdProject();
            $service = app(ProjectNoteConfigurationService::class);
            $kind = $change === 'unit' ? 'number' : 'text';
            $service->write($this->owner, $this->project, function ($project) use ($service, $change, $kind) {
                $field = $project->customFields()->find($this->fields[$kind]);
                if ($change === 'archive') {
                    $service->archiveField($field, true);
                } else {
                    $service->saveField($project, $change === 'type' ? ['type' => 'boolean'] : ['unit' => 'other'], $field);
                }
            });
            $worker = $this->blockedWorker(['action' => 'update_note', 'note_id' => $note->id, 'payload' => ['title' => 'Must roll back', 'values' => $this->valuesInput([$kind => $kind === 'number' ? 1 : 'New value'])]]);
            DB::commit();
            $this->finish($worker, 409);
            $this->assertSame('Original', $note->fresh()->title);
            $this->assertNull($this->stored($note)[$this->fields[$kind]]);
            // Restore the definition for the next independent scenario, while no values exist.
            $service->write($this->owner, $this->project, function ($project) use ($service, $kind) {
                $field = $project->customFields()->find($this->fields[$kind]);
                $service->archiveField($field, false);
                $service->saveField($project, $kind === 'number' ? ['unit' => 'points'] : ['type' => 'text'], $field);
            });
        }
    }

    public function test_value_commit_prevents_a_waiting_incompatible_unit_change(): void
    {
        $note = $this->note();
        $this->holdProject();
        $this->update($note, ['number' => 1]);
        $worker = $this->blockedWorker(['action' => 'config', 'field_id' => $this->fields['number'], 'payload' => ['unit' => 'other']]);
        DB::commit();
        $this->finish($worker, 422);
        $this->assertSame('points', ProjectCustomField::find($this->fields['number'])->unit);
        $this->assertSame('1.000000', $this->stored($note)[$this->fields['number']]);
    }

    public function test_concurrent_partial_updates_preserve_both_fields(): void
    {
        $note = $this->note();
        $this->holdProject();
        $this->update($note, ['text' => 'First']);
        $worker = $this->blockedWorker(['action' => 'update_note', 'note_id' => $note->id, 'payload' => ['values' => $this->valuesInput(['boolean' => false])]]);
        DB::commit();
        $this->finish($worker);
        $stored = $this->stored($note);
        $this->assertSame('First', $stored[$this->fields['text']]);
        $this->assertFalse($stored[$this->fields['boolean']]);
    }

    public function test_same_field_last_committed_write_wins(): void
    {
        $note = $this->note(['text' => 'Initial']);
        $this->holdProject();
        $this->update($note, ['text' => 'First']);
        $worker = $this->blockedWorker(['action' => 'update_note', 'note_id' => $note->id, 'payload' => ['values' => $this->valuesInput(['text' => 'Last'])]]);
        DB::commit();
        $this->finish($worker);
        $this->assertSame('Last', $this->stored($note)[$this->fields['text']]);
    }

    public function test_concurrent_first_value_initialization_keeps_the_first_successful_marker(): void
    {
        $one = $this->note();
        $two = $this->note();
        $this->travelTo(now('UTC')->setDate(2026, 10, 31)->setTime(13, 0, 0, 123456));
        $this->holdProject();
        $this->update($one, ['number' => 0]);
        $marker = ProjectCustomField::find($this->fields['number'])->first_value_at->toISOString();
        $worker = $this->blockedWorker(['action' => 'update_note', 'note_id' => $two->id, 'clock' => '2026-10-31T13:01:00.654321Z', 'payload' => ['values' => $this->valuesInput(['number' => 2])]]);
        DB::commit();
        $this->finish($worker);
        $this->assertSame($marker, ProjectCustomField::find($this->fields['number'])->first_value_at->toISOString());
        $this->assertSame('0.000000', $this->stored($one)[$this->fields['number']]);
        $this->assertSame('2.000000', $this->stored($two)[$this->fields['number']]);
    }

    public function test_first_value_rollback_allows_the_waiting_successful_write_to_initialize_marker(): void
    {
        $one = $this->note();
        $two = $this->note();
        $this->holdProject();
        $this->update($one, ['number' => 0]);
        $worker = $this->blockedWorker(['action' => 'update_note', 'note_id' => $two->id, 'clock' => '2026-10-31T13:01:00.654321Z', 'payload' => ['values' => $this->valuesInput(['number' => 2])]]);
        DB::rollBack();
        $this->finish($worker);
        $this->assertNull($this->stored($one)[$this->fields['number']]);
        $this->assertSame('2026-10-31T13:01:00.654321Z', ProjectCustomField::find($this->fields['number'])->first_value_at->toISOString());
        $this->assertSame('2.000000', $this->stored($two)[$this->fields['number']]);
    }
}
