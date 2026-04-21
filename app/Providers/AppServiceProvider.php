<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Lead;
use App\Observers\LeadObserver;
use App\Policies\LeadPolicy;
use Illuminate\Support\Facades\Gate;


class AppServiceProvider extends ServiceProvider
{


    protected $policies = [
    \App\Models\Lead::class => \App\Policies\LeadPolicy::class,
];




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
            Lead::observe(LeadObserver::class);
            Gate::policy(Lead::class, LeadPolicy::class);

    }
}
