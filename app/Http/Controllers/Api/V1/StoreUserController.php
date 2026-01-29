<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserController extends Controller
{
    /**
     * Display a listing of team members for the current store.
     */
    public function index(Request $request): JsonResponse
    {
        $store = $request->user()->currentStore();

        if (! $store) {
            return response()->json(['message' => 'No store selected'], 400);
        }

        $query = StoreUser::where('store_id', $store->id)
            ->with(['user:id,name,email', 'role:id,name,slug']);

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->has('role_id')) {
            $query->where('role_id', $request->input('role_id'));
        }

        $storeUsers = $request->boolean('all')
            ? $query->get()
            : $query->paginate($request->input('per_page', 15));

        return response()->json($storeUsers);
    }

    /**
     * Invite a new user to the store or add existing user.
     */
    public function store(Request $request): JsonResponse
    {
        $store = $request->user()->currentStore();

        if (! $store) {
            return response()->json(['message' => 'No store selected'], 400);
        }

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'role_id' => [
                'required',
                'exists:roles,id',
                Rule::exists('roles', 'id')->where('store_id', $store->id),
            ],
        ]);

        // Check if user already exists in this store
        $existingStoreUser = StoreUser::where('store_id', $store->id)
            ->where('email', $validated['email'])
            ->first();

        if ($existingStoreUser) {
            return response()->json([
                'message' => 'User is already a member of this store',
            ], 422);
        }

        // Check if user exists in the system
        $user = User::where('email', $validated['email'])->first();

        // Generate invitation token
        $invitationToken = Str::random(64);

        // Create store user record (pending invite)
        $storeUser = StoreUser::create([
            'store_id' => $store->id,
            'user_id' => $user?->id,
            'role_id' => $validated['role_id'],
            'is_owner' => false,
            'status' => $user ? 'active' : 'invite sent',
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'] ?? '',
            'email' => $validated['email'],
            'token' => $user ? null : $invitationToken,
            'created_by' => $request->user()->id,
        ]);

        // TODO: Send invitation email to the user
        // Mail::to($validated['email'])->send(new StoreInvitation($storeUser, $store));

        $storeUser->load(['user:id,name,email', 'role:id,name,slug']);

        // Log the activity
        ActivityLog::log(
            Activity::TEAM_INVITE,
            $storeUser,
            null,
            [
                'email' => $validated['email'],
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'] ?? '',
                'role_id' => $validated['role_id'],
                'role_name' => $storeUser->role?->name,
                'user_exists' => (bool) $user,
            ],
            "Invited {$validated['email']} to the team as {$storeUser->role?->name}"
        );

        return response()->json([
            'store_user' => $storeUser,
            'invitation_sent' => ! $user,
        ], 201);
    }

    /**
     * Display the specified team member.
     */
    public function show(Request $request, StoreUser $storeUser): JsonResponse
    {
        $store = $request->user()->currentStore();

        if (! $store || $storeUser->store_id !== $store->id) {
            return response()->json(['message' => 'Store user not found'], 404);
        }

        $storeUser->load(['user:id,name,email', 'role']);

        return response()->json($storeUser);
    }

    /**
     * Update the specified team member's role.
     */
    public function update(Request $request, StoreUser $storeUser): JsonResponse
    {
        $store = $request->user()->currentStore();

        if (! $store || $storeUser->store_id !== $store->id) {
            return response()->json(['message' => 'Store user not found'], 404);
        }

        // Cannot modify store owner
        if ($storeUser->is_owner) {
            return response()->json([
                'message' => 'Cannot modify the store owner',
            ], 403);
        }

        $validated = $request->validate([
            'role_id' => [
                'required',
                'exists:roles,id',
                Rule::exists('roles', 'id')->where('store_id', $store->id),
            ],
        ]);

        $oldRole = $storeUser->role;

        $storeUser->update([
            'role_id' => $validated['role_id'],
        ]);

        $storeUser->load(['user:id,name,email', 'role:id,name,slug']);

        // Log the activity
        ActivityLog::log(
            Activity::TEAM_UPDATE,
            $storeUser,
            null,
            [
                'old_role_id' => $oldRole?->id,
                'old_role_name' => $oldRole?->name,
                'new_role_id' => $storeUser->role?->id,
                'new_role_name' => $storeUser->role?->name,
                'member_email' => $storeUser->email,
                'member_name' => $storeUser->name,
            ],
            "Changed {$storeUser->name}'s role from {$oldRole?->name} to {$storeUser->role?->name}"
        );

        return response()->json($storeUser);
    }

    /**
     * Remove the specified team member from the store.
     */
    public function destroy(Request $request, StoreUser $storeUser): JsonResponse
    {
        $store = $request->user()->currentStore();

        if (! $store || $storeUser->store_id !== $store->id) {
            return response()->json(['message' => 'Store user not found'], 404);
        }

        // Cannot remove store owner
        if ($storeUser->is_owner) {
            return response()->json([
                'message' => 'Cannot remove the store owner',
            ], 403);
        }

        // Cannot remove yourself
        if ($storeUser->user_id === $request->user()->id) {
            return response()->json([
                'message' => 'Cannot remove yourself from the store',
            ], 422);
        }

        // Capture details before deletion for logging
        $memberDetails = [
            'member_id' => $storeUser->id,
            'member_email' => $storeUser->email,
            'member_name' => $storeUser->name,
            'role_id' => $storeUser->role_id,
            'role_name' => $storeUser->role?->name,
        ];

        $storeUser->delete();

        // Log the activity
        ActivityLog::log(
            Activity::TEAM_REMOVE,
            null,
            null,
            $memberDetails,
            "Removed {$memberDetails['member_name']} ({$memberDetails['member_email']}) from the team"
        );

        return response()->json(null, 204);
    }

    /**
     * Get the current user's permissions for this store.
     */
    public function permissions(Request $request): JsonResponse
    {
        $user = $request->user();
        $storeUser = $user->currentStoreUser();

        if (! $storeUser) {
            return response()->json(['message' => 'No store selected'], 400);
        }

        return response()->json([
            'permissions' => $storeUser->getPermissions(),
            'is_owner' => $storeUser->is_owner,
            'role' => $storeUser->role ? [
                'id' => $storeUser->role->id,
                'name' => $storeUser->role->name,
                'slug' => $storeUser->role->slug,
            ] : null,
        ]);
    }

    /**
     * Manually accept a pending invitation and create the user account.
     */
    public function acceptInvitation(Request $request, StoreUser $storeUser): JsonResponse
    {
        $store = $request->user()->currentStore();

        if (! $store || $storeUser->store_id !== $store->id) {
            return response()->json(['message' => 'Store user not found'], 404);
        }

        // Can only accept pending invitations
        if ($storeUser->status !== 'invite sent') {
            return response()->json([
                'message' => 'This invitation has already been accepted or is not pending',
            ], 422);
        }

        // User must not already exist (they would have been auto-activated)
        if ($storeUser->user_id) {
            return response()->json([
                'message' => 'User already exists in the system',
            ], 422);
        }

        $validated = $request->validate([
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ]);

        // Create the user account
        $user = User::create([
            'name' => trim($storeUser->first_name.' '.$storeUser->last_name),
            'email' => $storeUser->email,
            'password' => Hash::make($validated['password']),
        ]);

        // Update the store user record
        $storeUser->update([
            'user_id' => $user->id,
            'status' => 'active',
            'token' => null,
        ]);

        $storeUser->load(['user:id,name,email', 'role:id,name,slug']);

        // Log the activity
        ActivityLog::log(
            Activity::TEAM_ACCEPT_INVITATION,
            $storeUser,
            null,
            [
                'member_email' => $storeUser->email,
                'member_name' => $storeUser->name,
                'role_id' => $storeUser->role_id,
                'role_name' => $storeUser->role?->name,
                'accepted_by' => $request->user()->name,
            ],
            "Manually accepted invitation for {$storeUser->name} ({$storeUser->email})"
        );

        return response()->json([
            'message' => 'Invitation accepted successfully',
            'store_user' => $storeUser,
        ]);
    }

    /**
     * Transfer store ownership to another user.
     */
    public function transferOwnership(Request $request, StoreUser $storeUser): JsonResponse
    {
        $store = $request->user()->currentStore();
        $currentStoreUser = $request->user()->currentStoreUser();

        if (! $store || $storeUser->store_id !== $store->id) {
            return response()->json(['message' => 'Store user not found'], 404);
        }

        // Only owner can transfer ownership
        if (! $currentStoreUser?->is_owner) {
            return response()->json([
                'message' => 'Only the store owner can transfer ownership',
            ], 403);
        }

        // Cannot transfer to yourself
        if ($storeUser->user_id === $request->user()->id) {
            return response()->json([
                'message' => 'You are already the owner',
            ], 422);
        }

        // Get owner role
        $ownerRole = Role::where('store_id', $store->id)
            ->where('slug', 'owner')
            ->first();

        // Capture previous owner details for logging
        $previousOwnerName = $currentStoreUser->name;
        $previousOwnerEmail = $currentStoreUser->email;

        // Transfer ownership
        $currentStoreUser->update(['is_owner' => false]);
        $storeUser->update([
            'is_owner' => true,
            'role_id' => $ownerRole?->id ?? $storeUser->role_id,
        ]);

        $storeUser->load(['user:id,name,email', 'role:id,name,slug']);

        // Log the activity
        ActivityLog::log(
            Activity::TEAM_TRANSFER_OWNERSHIP,
            $storeUser,
            null,
            [
                'previous_owner_id' => $currentStoreUser->id,
                'previous_owner_name' => $previousOwnerName,
                'previous_owner_email' => $previousOwnerEmail,
                'new_owner_id' => $storeUser->id,
                'new_owner_name' => $storeUser->name,
                'new_owner_email' => $storeUser->email,
            ],
            "Transferred store ownership from {$previousOwnerName} to {$storeUser->name}"
        );

        return response()->json([
            'message' => 'Ownership transferred successfully',
            'new_owner' => $storeUser,
        ]);
    }
}
