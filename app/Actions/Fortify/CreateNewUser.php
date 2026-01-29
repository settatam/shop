<?php

namespace App\Actions\Fortify;

use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'store_name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'password' => $this->passwordRules(),
        ])->validate();

        return DB::transaction(function () use ($input) {
            // Create the user
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            // Create the store with the user as owner
            $store = Store::create([
                'user_id' => $user->id,
                'name' => $input['store_name'],
                'slug' => Str::slug($input['store_name']).'-'.Str::random(6),
                'account_email' => $input['email'],
                'is_active' => true,
            ]);

            // Create default roles for the store
            Role::createDefaultRoles($store->id);

            // Get the owner role
            $ownerRole = Role::where('store_id', $store->id)
                ->where('slug', Role::OWNER)
                ->first();

            // Create the store user record (owner)
            StoreUser::create([
                'user_id' => $user->id,
                'store_id' => $store->id,
                'role_id' => $ownerRole?->id,
                'is_owner' => true,
                'status' => 'active',
                'first_name' => explode(' ', $input['name'])[0] ?? $input['name'],
                'last_name' => explode(' ', $input['name'], 2)[1] ?? '',
                'email' => $input['email'],
            ]);

            // Set the user's current store
            $user->current_store_id = $store->id;
            $user->save();

            return $user;
        });
    }
}
