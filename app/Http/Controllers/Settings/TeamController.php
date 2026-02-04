<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\StoreUser;
use App\Services\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    public function __construct(protected StoreContext $storeContext) {}

    /**
     * Display the team management page.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $members = StoreUser::where('store_id', $store->id)
            ->with(['user:id,name,email', 'role:id,name,slug'])
            ->orderByDesc('is_owner')
            ->orderBy('created_at')
            ->get()
            ->map(fn (StoreUser $member) => [
                'id' => $member->id,
                'user_id' => $member->user_id,
                'first_name' => $member->first_name,
                'last_name' => $member->last_name,
                'email' => $member->email,
                'name' => $member->user?->name ?? trim($member->first_name.' '.$member->last_name),
                'role' => $member->role ? [
                    'id' => $member->role->id,
                    'name' => $member->role->name,
                    'slug' => $member->role->slug,
                ] : null,
                'is_owner' => $member->is_owner,
                'status' => $member->status,
                'can_be_assigned' => $member->can_be_assigned,
                'created_at' => $member->created_at->toDateTimeString(),
            ]);

        $roles = Role::where('store_id', $store->id)
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'is_system']);

        $currentUserStoreUser = $request->user()->currentStoreUser();

        return Inertia::render('settings/Team', [
            'members' => $members,
            'roles' => $roles,
            'isOwner' => $currentUserStoreUser?->is_owner ?? false,
            'currentUserId' => $request->user()->id,
        ]);
    }
}
