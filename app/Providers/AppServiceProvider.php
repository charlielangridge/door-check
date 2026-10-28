<?php

namespace App\Providers;

use App\Models\Adult;
use App\Models\Child;
use App\Models\Site;
use App\Models\User;
use App\Services\CollectionAudit;
use App\Services\CollectionRecordChanges;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Events\PermissionAttachedEvent;
use Spatie\Permission\Events\PermissionDetachedEvent;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        foreach ([Adult::class, Child::class, Site::class] as $modelClass) {
            $modelClass::updated(function (Model $model): void {
                DB::afterCommit(fn () => resolve(CollectionRecordChanges::class)->changed($model));
            });
        }

        Auth::viaRequest('collection-browser', function (Request $request): ?GenericUser {
            $key = $request->cookie('collection_browser');

            return is_string($key) && strlen($key) === 64 ? new GenericUser(['id' => hash('sha256', $key)]) : null;
        });

        Event::listen(Login::class, function (Login $event): void {
            if (! $event->user instanceof User) {
                return;
            }
            if (($event->user->can('access staff') || $event->user->can('process collections at any site')) && ! Auth::guard($event->guard)->viaRemember()) {
                request()->session()->put('staff_signed_in_at', now()->timestamp);
                Cookie::queue(cookie('staff_kiosk', '1', (int) config('staff-collections.session_minutes'), httpOnly: true, sameSite: 'lax'));
            }
            resolve(CollectionAudit::class)->record('login', $event->user, [], $event->user);
        });

        Event::listen(Logout::class, function (Logout $event): void {
            Cookie::queue(Cookie::forget('staff_kiosk'));
            if ($event->user instanceof User) {
                resolve(CollectionAudit::class)->record('logout', $event->user, [], $event->user);
            }
        });

        Event::listen(Failed::class, function (Failed $event): void {
            $subject = $event->user instanceof User ? $event->user : new User;
            if (! $subject->exists) {
                $subject->id = 0;
            }
            resolve(CollectionAudit::class)->record('login_failed', $subject, ['guard' => $event->guard]);
        });

        foreach ([RoleAttachedEvent::class, RoleDetachedEvent::class, PermissionAttachedEvent::class, PermissionDetachedEvent::class] as $eventClass) {
            Event::listen($eventClass, function (RoleAttachedEvent|RoleDetachedEvent|PermissionAttachedEvent|PermissionDetachedEvent $event): void {
                $assignments = $event instanceof RoleAttachedEvent || $event instanceof RoleDetachedEvent ? $event->rolesOrIds : $event->permissionsOrIds;
                resolve(CollectionAudit::class)->record(Str::snake(class_basename($event)), $event->model, ['assignments' => $assignments]);
            });
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
