<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

/** Committed fixtures, guarded cleanup, no enclosing application transaction. */
trait DisposableDatabaseMigrations
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        IsolatedDatabase::assertSafe($this->app);
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();
        $this->beforeApplicationDestroyed(function () {
            IsolatedDatabase::assertSafe($this->app);
            // Wipe only the verified disposable DB. Unrelated old SQLite migrations
            // cannot all be rolled back individually without rebuilding foreign keys.
            $this->artisan('db:wipe');
            RefreshDatabaseState::$migrated = false;
        });
    }
}
