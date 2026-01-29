<?php

namespace App\Traits;

use App\Models\Note;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasNotes
{
    /**
     * Get all notes for this model.
     */
    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'notable')->orderByDesc('created_at');
    }

    /**
     * Add a note to this model.
     */
    public function addNote(string $content, ?User $user = null): Note
    {
        $user = $user ?? auth()->user();

        return $this->notes()->create([
            'store_id' => $this->store_id,
            'user_id' => $user?->id,
            'content' => $content,
        ]);
    }
}
