<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;


   class LeadResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,

            'status' => $this->status?->only(['id','name']),
            'corretor' => $this->corretor?->only(['id','name']),
'histories' => $this->histories->map(function($h){
    return [
        'type' => $h->type,
        'from' => $h->from,
        'to' => $h->to,
        'description' => $h->description,
        'created_at' => $h->created_at,
        'user' => $h->user
            ? [
                'id' => $h->user->id,
                'name' => $h->user->name
              ]
            : null,
    ];
}),
'interactions' => $this->interactions->map(function($i){
    return [
        'id' => $i->id,
        'type' => $i->type,
        'note' => $i->note,
        'created_at' => $i->created_at,

        // 👇 ESSENCIAL
        'appointment_id' => $i->appointment_id,

        // 👇 opcional (se quiser dados completos)
        'appointment' => $i->appointment ? [
            'id' => $i->appointment->id,
            'starts_at' => $i->appointment->starts_at,
        ] : null,

        'user' => $i->user
            ? [
                'id' => $i->user->id,
                'name' => $i->user->name,
              ]
            : null,
    ];
}),
'empreendimento' => $this->empreendimento?->only(['id','name']),
            // 🔥 AQUI ESTAVA FALTANDO
            'empreendimentos' => $this->empreendimentos instanceof \Illuminate\Support\Collection
    ? $this->empreendimentos->first()?->only(['id','name'])
    : $this->empreendimentos?->only(['id','name']),

            'last_interaction' => $this->interactions->first()
    ? [
        'type' => $this->interactions->first()->type,
        'note' => $this->interactions->first()->note,
        'created_at' => $this->interactions->first()->created_at,
    ]
    : null
        ];
    }
}

