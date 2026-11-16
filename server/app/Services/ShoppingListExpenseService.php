<?php

namespace App\Services;

use App\Enums\ProjectTaskCompletionAction;
use App\Models\Project;
use App\Models\ProjectProgressNote;
use App\Models\ProjectTask;
use App\Models\ShoppingList;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ShoppingListExpenseService
{
    public function register(User $user, ShoppingList $list, array $input): array
    {
        return DB::transaction(function () use ($user, $list, $input): array {
            // Match existing configuration/Task/Note writers: project first, then list.
            $project = Project::query()->accessibleTo($user)->whereKey($input['project_id'])->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('view', $project);
            $list = ShoppingList::query()->whereKey($list->id)->lockForUpdate()->firstOrFail();
            $owner = (int) $list->created_by === (int) $user->id;
            if (! $owner) {
                // Current reads matter under MySQL REPEATABLE READ after waiting
                // for a close/leave/sharing operation on this list. Do not lock User rows.
                $participant = DB::table('shopping_list_users')->where('shopping_list_id', $list->id)
                    ->where('user_id', $user->id)->lockForUpdate()->first();
                abort_unless($participant !== null && in_array($list->visibility, ['shared', 'public'], true),
                    403, 'Only an explicit participant with current list access may register its expense.');
            }

            [$whole, $fraction] = array_pad(explode('.', $input['amount'], 2), 2, '');
            $amount = (ltrim($whole, '0') ?: '0').'.'.str_pad($fraction, 6, '0');
            $hash = hash('sha256', json_encode([(int) $project->id, $amount, (bool) $input['close_list'], $input['timezone']], JSON_THROW_ON_ERROR));
            // Include deleted Notes: deleting a Note never frees the expense slot.
            $existing = ProjectProgressNote::withTrashed()->where('shopping_list_id', $list->id)
                ->where('created_by', $user->id)->lockForUpdate()->first();
            if ($existing) {
                abort_unless(hash_equals((string) $existing->shopping_request_hash, $hash), 409,
                    'An expense is already registered for this list and user with different parameters. Edit or restore the existing Note.');
                return $this->receipt($list, $existing, false);
            }

            Gate::forUser($user)->authorize('create', [ProjectTask::class, $project]);
            Gate::forUser($user)->authorize('create', [ProjectProgressNote::class, $project]);
            $type = app(ProjectNoteConfigurationService::class)->shoppingType($project);
            if ($input['close_list']) {
                Gate::forUser($user)->authorize('close', $list);
                $list = app(ShoppingListService::class)->closeList($user, $list);
            } else {
                abort_unless($list->status === 'closed', 422, 'The list must be closed, or its owner must choose close_list=true.');
            }
            abort_if($list->closed_at === null, 422, 'This historical list has no closure date.');
            $date = CarbonImmutable::instance($list->closed_at)->setTimezone($input['timezone'])->toDateString();
            $title = 'Cumpărături · '.$date;
            $task = app(ProjectTaskService::class)->create($user, $project, [
                'title' => $title, 'description' => "Lista de cumpărături #{$list->id}",
                'execution_type' => 'one_time', 'review_mode' => 'single',
                'scheduled_date' => $date,
            ]);
            app(ProjectTaskExecutionService::class)->execute($user, $project, $task, 'single', ProjectTaskCompletionAction::DONE, [
                'lock_version' => 0, 'request_id' => (string) Str::uuid(), 'performed_on' => $date,
            ]);
            $note = app(ProjectProgressNoteService::class)->create($user, $project, [
                'title' => $title, 'content' => "Cost pentru lista de cumpărături #{$list->id}.",
                'noted_at' => $date, 'note_type_id' => $type->id,
                'task_ids' => [$task->id], 'review_task_id' => $task->id, 'review_occurrence_key' => 'single',
                'values' => [
                    ['field_id' => $type->shopping_amount_field_id, 'value' => $amount],
                    ['field_id' => $type->shopping_provider_field_id, 'value' => $type->shopping_provider_option_id],
                ],
            ]);
            $note->forceFill(['shopping_list_id' => $list->id, 'shopping_request_hash' => $hash])->save();
            return $this->receipt($list, $note, true);
        });
    }

    private function receipt(ShoppingList $list, ProjectProgressNote $note, bool $created): array
    {
        return ['created' => $created, 'data' => [
            'shopping_list_id' => (int) $list->id, 'project_id' => (int) $note->project_id,
            'task_id' => $note->review?->task_id, 'note_id' => (int) $note->id,
            'noted_at' => $note->noted_at, 'note_deleted' => $note->trashed(),
            'list_status' => $list->status, 'closed_at' => $list->closed_at?->toISOString(),
        ]];
    }
}
