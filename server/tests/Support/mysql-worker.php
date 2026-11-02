<?php

// Receives only test operation data on stdin. Credentials stay in the ignored file.
set_exception_handler(function ($failure) {
    echo json_encode(['event' => 'fatal', 'class' => get_class($failure), 'code' => (string) $failure->getCode()])."\n";
    exit(1);
});
require dirname(__DIR__).'/mysql-bootstrap.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Tests\Support\IsolatedDatabase::assertSafe($app);
$input = json_decode(fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (isset($input['clock'])) {
    Illuminate\Support\Carbon::setTestNow($input['clock']);
    Carbon\CarbonImmutable::setTestNow($input['clock']);
}
$db = Illuminate\Support\Facades\DB::connection();
$db->statement('SET SESSION innodb_lock_wait_timeout = 8');
$connection = (int) $db->selectOne('SELECT CONNECTION_ID() AS id')->id;
$emit = function (array $event) use ($connection) {
    echo json_encode(['connection_id' => $connection, ...$event])."\n";
    flush();
};
$attempted = false;
$db->beforeExecuting(function ($query) use (&$attempted, $emit) {
    if (preg_match('/^select .* from `projects` .*for update/i', $query)) {
        $attempted = true;
        $emit(['event' => 'project_lock_attempt']);
    } elseif (preg_match('/^select .* from `project_(note_types|note_type_fields|custom_fields|custom_field_options)`/i', $query)) {
        $emit(['event' => 'configuration_read', 'after_lock_attempt' => $attempted]);
    }
});
$user = App\Models\User::findOrFail($input['user_id']);
$project = App\Models\Project::findOrFail($input['project_id']);
try {
    if ($input['action'] === 'create_note') {
        $result = app(App\Services\ProjectProgressNoteService::class)->create($user, $project, $input['payload']);
    } elseif ($input['action'] === 'update_note') {
        $result = app(App\Services\ProjectProgressNoteService::class)->update($user, $project, App\Models\ProjectProgressNote::findOrFail($input['note_id']), $input['payload']);
    } elseif ($input['action'] === 'config') {
        $service = app(App\Services\ProjectNoteConfigurationService::class);
        $result = $service->write($user, $project, function ($locked) use ($service, $input) {
            return $service->saveField($locked, $input['payload'], $locked->customFields()->findOrFail($input['field_id']));
        });
    } else {
        throw new RuntimeException('Unknown worker operation');
    }
    $emit(['event' => 'done', 'status' => 200, 'id' => $result->id]);
} catch (Illuminate\Validation\ValidationException $failure) {
    $emit(['event' => 'done', 'status' => 422]);
} catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $failure) {
    $emit(['event' => 'done', 'status' => $failure->getStatusCode()]);
} catch (Throwable $failure) {
    $emit(['event' => 'done', 'status' => 500, 'class' => get_class($failure), 'code' => (string) $failure->getCode()]);
}
