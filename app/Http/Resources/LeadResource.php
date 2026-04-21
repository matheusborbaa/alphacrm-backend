<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeadResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'      => $this->id,
            'name'    => $this->name,
            'phone'   => $this->phone,
            'email'   => $this->email,

            // Campos novos da doc
            'temperature'         => $this->temperature,
            'value'               => $this->value,
            'last_interaction_at' => $this->last_interaction_at,
            'status_changed_at'   => $this->status_changed_at,
            'sla_status'          => $this->sla_status,
            'channel'             => $this->channel,
            'campaign'            => $this->campaign,
            'created_at'          => $this->created_at,
            'updated_at'          => $this->updated_at,

            // Relacionamentos
            'status'         => $this->status?->only(['id','name']),
            'substatus'      => $this->whenLoaded('substatus', fn() => $this->substatus?->only(['id','name'])),
            'corretor'       => $this->corretor?->only(['id','name']),
            'source'         => $this->whenLoaded('source', fn() => $this->source?->only(['id','name'])),
            'empreendimento' => $this->empreendimento?->only(['id','name']),

            // Interações
            'histories' => $this->whenLoaded('histories', fn() =>
                $this->histories->map(function ($h) {
                    return [
                        'type'        => $h->type,
                        'from'        => $h->from,
                        'to'          => $h->to,
                        'description' => $h->description,
                        'created_at'  => $h->created_at,
                        'user'        => $h->user ? ['id' => $h->user->id, 'name' => $h->user->name] : null,
                    ];
                })
            ),

            'interactions' => $this->whenLoaded('interactions', fn() =>
                $this->interactions->map(function ($i) {
                    return [
                        'id'             => $i->id,
                        'type'           => $i->type,
                        'note'           => $i->note,
                        'created_at'     => $i->created_at,
                        'appointment_id' => $i->appointment_id,
                        'appointment'    => $i->appointment ? [
                            'id'        => $i->appointment->id,
                            'starts_at' => $i->appointment->starts_at,
                        ] : null,
                        'user' => $i->user ? ['id' => $i->user->id, 'name' => $i->user->name] : null,
                    ];
                })
            ),

            'last_interaction' => $this->whenLoaded('interactions', function () {
                $last = $this->interactions->first();
                return $last ? [
                    'type'       => $last->type,
                    'note'       => $last->note,
                    'created_at' => $last->created_at,
                ] : null;
            }),
        ];
    }
}
