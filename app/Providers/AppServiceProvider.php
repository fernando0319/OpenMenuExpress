<?php

namespace App\Providers;

use App\Contracts\MessagingProvider;
use App\Enums\StoreRole;
use App\Messaging\Evolution\EvolutionClient;
use App\Messaging\EvolutionMessagingProvider;
use App\Messaging\LogMessagingProvider;
use App\Models\User;
use App\Payments\Nwc\NwcClient;
use App\Payments\Nwc\RelayNwcClient;
use App\Services\Push\PushTransport;
use App\Services\Push\WebPushTransport;
use App\Support\CurrentStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentStore::class);

        $this->app->singleton(PushTransport::class, WebPushTransport::class);

        $this->app->singleton(NwcClient::class, RelayNwcClient::class);

        $this->app->singleton(EvolutionClient::class, fn (): EvolutionClient => new EvolutionClient(
            (string) config('messaging.evolution.url'),
            (string) config('messaging.evolution.key'),
            (string) config('messaging.evolution.instance_prefix'),
        ));

        $this->app->singleton(MessagingProvider::class, fn (): MessagingProvider => match (config('messaging.driver')) {
            'evolution' => new EvolutionMessagingProvider($this->app->make(EvolutionClient::class)),
            default => new LogMessagingProvider,
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureGates();
    }

    /**
     * Permissions inside the current store, based on the user's role there.
     */
    protected function configureGates(): void
    {
        $isOwnerOrManager = fn (User $user): bool => in_array(
            app(CurrentStore::class)->role(),
            [StoreRole::Owner, StoreRole::Manager],
            true,
        );

        Gate::define('manage-catalog', $isOwnerOrManager);
        Gate::define('manage-store', $isOwnerOrManager);
        Gate::define('manage-finance', $isOwnerOrManager);

        // The platform owner, for the private admin panel. Granted only by the app:super-admin command.
        Gate::define('super-admin', fn (User $user): bool => $user->is_super_admin === true);

        $role = fn (): ?StoreRole => app(CurrentStore::class)->role();

        // Any member except couriers, who only see their own deliveries.
        Gate::define('serve-tables', fn (User $user): bool => ! in_array($role(), [null, StoreRole::Courier], true));
        Gate::define('view-orders', fn (User $user): bool => ! in_array($role(), [null, StoreRole::Courier], true));

        // Anyone in the store may be given a delivery (in small shops the owner delivers too).
        Gate::define('deliver', fn (User $user): bool => $role() !== null);

        // Counter work: sales and the cash register. Waiters get their own app later.
        Gate::define('operate-pos', fn (User $user): bool => in_array(
            app(CurrentStore::class)->role(),
            [StoreRole::Owner, StoreRole::Manager, StoreRole::Attendant],
            true,
        ));
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
