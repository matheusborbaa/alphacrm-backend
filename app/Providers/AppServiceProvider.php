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

            // Sprint Biblioteca — mantém pasta-espelho na biblioteca de mídia
            // sincronizada com o cadastro de empreendimentos. Ao criar um
            // empreendimento, aparece automaticamente uma pasta dele dentro
            // da raiz "EMPREENDIMENTOS", visível só pra quem tem acesso.
            Empreendimento::observe(EmpreendimentoObserver::class);
    }
}
