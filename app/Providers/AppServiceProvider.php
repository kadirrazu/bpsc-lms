<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\Designation;
use App\Models\Unit;



use App\Models\User;
use App\Observers\LibraryObserver;
use App\Services\Library\Audit;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Paginator::defaultView('shared.pagination');
        Gate::define('member-portal', fn(User $u) => $u->is_active && $u->role === UserRole::Member);
        Gate::define('manage-system', fn (User $u) => $u->is_active && $u->role === UserRole::Admin);
        Gate::define('manage-library', fn (User $u) => $u->is_active && $u->role !== UserRole::Member);
        Gate::define('delete-library', fn (User $u) => $u->is_active && in_array($u->role, [UserRole::Admin, UserRole::Librarian], true));
        Gate::define('view-logs', fn (User $u) => $u->is_active && in_array($u->role, [UserRole::Admin, UserRole::Librarian], true));
        Gate::define('manage-user', function (User $actor, User $target) {
            if (!$actor->is_active) return false;
            if ($actor->role === UserRole::Admin) return true;
            return in_array($target->role->value, array_column(UserRole::assignable($actor->role), 'value'), true);
        });
        foreach ([User::class, Designation::class, Unit::class] as $model) {
            $model::observe(LibraryObserver::class);
        }
        Event::listen(Login::class, function (Login $e) {
            $e->user->forceFill(['last_login_at' => now()])->save();
            request()->session()->put('auth_version', $e->user->auth_version);
            app(Audit::class)->record('auth.login', actor: $e->user);
        });
        Event::listen(Logout::class, fn (Logout $e) => app(Audit::class)->record('auth.logout', actor: $e->user));
        Event::listen(Failed::class, fn (Failed $e) => app(Audit::class)->record('auth.failed', actor: $e->user));
    }
}
