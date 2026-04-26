<?php

use App\Models\ChatConversation;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('conversation.{id}', function ($user, $id) {
    $conv = ChatConversation::find((int) $id);
    if (!$conv) return false;
    return (int) $conv->user_a_id === (int) $user->id
        || (int) $conv->user_b_id === (int) $user->id;
});

Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
