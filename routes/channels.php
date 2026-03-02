<?php

use App\Models\StorefrontChatSession;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('store.{storeId}.conversations', function ($user, $storeId) {
    return $user->stores()->where('stores.id', $storeId)->exists();
});

Broadcast::channel('conversation.{sessionId}', function ($user, $sessionId) {
    $session = StorefrontChatSession::find($sessionId);

    if (! $session) {
        return false;
    }

    return $user->stores()->where('stores.id', $session->store_id)->exists();
});
