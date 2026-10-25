<?php

namespace App\Providers;

// use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        \App\Models\Project::class => \App\Policies\ProjectPolicy::class,
        \App\Models\ProjectTask::class => \App\Policies\ProjectTaskPolicy::class,
        \App\Models\ProjectProgressNote::class => \App\Policies\ProjectProgressNotePolicy::class,
        \App\Models\ProjectTaskEvaluation::class => \App\Policies\ProjectTaskEvaluationPolicy::class,
        \App\Models\ShoppingList::class => \App\Policies\ShoppingListPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        //
    }
}
