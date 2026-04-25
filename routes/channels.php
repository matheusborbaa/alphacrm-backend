<?php

use App\Models\ChatConversation;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Authorization dos canais privados que o frontend subscribe via Laravel
| Echo → Reverb. Toda subscribe de private-* passa por /broadcasting/auth,
| que invoca a closure correspondente. A closure recebe o user autenticado
| e os placeholders da rota; retornar true (ou dados) libera, false bloqueia.
|
| Convencão de nomes usada aqui:
|   - conversation.{id}  → ambos participantes da DM
|   - user.{id}          → o próprio user (canal global pra badge/notif)
|
| Canal legado "App.Models.User.{id}" é mantido por compat com o
| NotificationCreated existente.
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Sprint 4.5 — canal privado por conversa. Só os 2 participantes entram.
// Admin em modo auditoria usa fluxo separado (polling tradicional); não
// ouve o canal em tempo real pra evitar vazamento de msgs novas com regras
// recíprocas mais complicadas.
Broadcast::channel('conversation.{id}', function ($user, $id) {
    $conv = ChatConversation::find((int) $id);
    if (!$conv) return false;
    return (int) $conv->user_a_id === (int) $user->id
        || (int) $conv->user_b_id === (int) $user->id;
});

// Sprint 4.5 — canal privado por user. Usado pro broadcast global de "chegou
// msg nova em alguma conversa sua" — o frontend subscribe globalmente pra
// atualizar badge/sidebar mesmo sem abrir a conversa correspondente.
Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
