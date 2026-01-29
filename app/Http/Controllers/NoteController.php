<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreNoteRequest;
use App\Http\Requests\UpdateNoteRequest;
use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\Note;
use App\Services\StoreContext;
use Illuminate\Http\RedirectResponse;

class NoteController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
    ) {}

    /**
     * Store a newly created note.
     */
    public function store(StoreNoteRequest $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return back()->with('error', 'Please select a store first.');
        }

        $validated = $request->validated();

        // Resolve the notable model
        $notableClass = $validated['notable_type'];
        $notableId = $validated['notable_id'];

        // Map type names to full class names for better UX
        // Handles both simple names (transaction) and escaped class names (App\\Models\\Transaction)
        $typeMap = [
            'transaction' => \App\Models\Transaction::class,
            'order' => \App\Models\Order::class,
            'repair' => \App\Models\Repair::class,
            'memo' => \App\Models\Memo::class,
            'vendor' => \App\Models\Vendor::class,
            'customer' => \App\Models\Customer::class,
            'payment' => \App\Models\Payment::class,
            // Also handle full class names that may come from frontend
            'App\\Models\\Transaction' => \App\Models\Transaction::class,
            'App\\Models\\Order' => \App\Models\Order::class,
            'App\\Models\\Repair' => \App\Models\Repair::class,
            'App\\Models\\Memo' => \App\Models\Memo::class,
            'App\\Models\\Vendor' => \App\Models\Vendor::class,
            'App\\Models\\Customer' => \App\Models\Customer::class,
            'App\\Models\\Payment' => \App\Models\Payment::class,
        ];

        // Normalize the class name
        $lookupKey = strtolower($notableClass);
        if (isset($typeMap[$lookupKey])) {
            $notableClass = $typeMap[$lookupKey];
        } elseif (isset($typeMap[$notableClass])) {
            $notableClass = $typeMap[$notableClass];
        }

        // Verify the class exists
        if (! class_exists($notableClass)) {
            return back()->with('error', 'Invalid resource type.');
        }

        // Find the notable model and verify store ownership
        $notable = $notableClass::find($notableId);

        if (! $notable || $notable->store_id !== $store->id) {
            return back()->with('error', 'Resource not found.');
        }

        $note = Note::create([
            'store_id' => $store->id,
            'user_id' => auth()->id(),
            'notable_type' => $notableClass,
            'notable_id' => $notableId,
            'content' => $validated['content'],
        ]);

        // Log activity on the notable model
        ActivityLog::log(
            Activity::NOTES_CREATE,
            $notable,
            null,
            [
                'note_id' => $note->id,
                'content' => $note->content,
            ],
            'Note added'
        );

        return back()->with('success', 'Note added successfully.');
    }

    /**
     * Update the specified note.
     */
    public function update(UpdateNoteRequest $request, Note $note): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $note->store_id !== $store->id) {
            abort(404);
        }

        $oldContent = $note->content;

        $note->update([
            'content' => $request->validated()['content'],
        ]);

        // Log activity on the notable model
        $notable = $note->notable;
        if ($notable) {
            ActivityLog::log(
                Activity::NOTES_UPDATE,
                $notable,
                null,
                [
                    'note_id' => $note->id,
                    'old_content' => $oldContent,
                    'new_content' => $note->content,
                ],
                'Note updated'
            );
        }

        return back()->with('success', 'Note updated successfully.');
    }

    /**
     * Remove the specified note.
     */
    public function destroy(Note $note): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store || $note->store_id !== $store->id) {
            abort(404);
        }

        // Get notable before deleting the note
        $notable = $note->notable;
        $noteContent = $note->content;

        $note->delete();

        // Log activity on the notable model
        if ($notable) {
            ActivityLog::log(
                Activity::NOTES_DELETE,
                $notable,
                null,
                [
                    'deleted_content' => $noteContent,
                ],
                'Note deleted'
            );
        }

        return back()->with('success', 'Note deleted successfully.');
    }
}
