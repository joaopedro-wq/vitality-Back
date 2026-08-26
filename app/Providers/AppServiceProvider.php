<?php

namespace App\Providers;

use App\Models\Dieta;
use App\Models\Group;
use App\Models\Meta_diaria;
use App\Models\NutricaoRecomendada;
use App\Models\Refeicao;
use App\Models\Registro;
use App\Policies\DiaryEntryPolicy;
use App\Policies\DietaPolicy;
use App\Policies\GroupPolicy;
use App\Policies\MealPolicy;
use App\Policies\MetaDiariaPolicy;
use App\Policies\NutricaoRecomendadaPolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        Gate::policy(Registro::class, DiaryEntryPolicy::class);
        Gate::policy(Refeicao::class, MealPolicy::class);
        Gate::policy(Dieta::class, DietaPolicy::class);
        Gate::policy(Meta_diaria::class, MetaDiariaPolicy::class);
        Gate::policy(NutricaoRecomendada::class, NutricaoRecomendadaPolicy::class);
        Gate::policy(Group::class, GroupPolicy::class);

        RateLimiter::for('api-login', fn (Request $request) => Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));
        RateLimiter::for('api-register', fn (Request $request) => Limit::perHour(5)->by($request->ip()));
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perHour(3)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));
        RateLimiter::for('verification-resend', fn (Request $request) => Limit::perHour(3)->by((string) $request->user()?->id.'|'.$request->ip()));
        RateLimiter::for('avatar-upload', fn (Request $request) => Limit::perHour(10)->by((string) $request->user()?->id.'|'.$request->ip()));
        RateLimiter::for('ai-plan', fn (Request $request) => Limit::perMinute(10)->by((string) $request->user()?->id.'|'.$request->ip()));

        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url')."/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });
    }
}
