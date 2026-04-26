<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Empreendimento;
use App\Models\Lead;
use App\Observers\EmpreendimentoObserver;
use App\Observers\LeadObserver;
use App\Policies\LeadPolicy;
use Illuminate\Support\Facades\Gate;

class AppServiceProvider extends ServiceProvider
{

    protected $policies = [
    \App\Models\Lead::class => \App\Policies\LeadPolicy::class,
];

    public function register(): void
    {

    }

    public function boot(): void
    {
            Lead::observe(LeadObserver::class);
            Gate::policy(Lead::class, LeadPolicy::class);

            Empreendimento::observe(EmpreendimentoObserver::class);
    }
}
