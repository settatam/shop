<?php

namespace Tests\Browser;

use App\Models\Category;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StoreContext;
use Laravel\Dusk\Browser;

trait DuskSetupTrait
{
    protected User $owner;

    protected Store $store;

    protected Role $ownerRole;

    protected StoreUser $ownerStoreUser;

    protected Warehouse $warehouse;

    protected Category $category;

    /**
     * Set up the common test fixtures for Dusk tests.
     */
    protected function setUpDuskEnvironment(): void
    {
        $this->owner = User::factory()->create([
            'name' => 'Test Owner',
            'email' => 'dusk@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->store = Store::factory()->create([
            'user_id' => $this->owner->id,
            'step' => 2,
        ]);

        $this->ownerRole = Role::factory()->owner()->create([
            'store_id' => $this->store->id,
        ]);

        $this->ownerStoreUser = StoreUser::factory()->owner()->create([
            'user_id' => $this->owner->id,
            'store_id' => $this->store->id,
            'role_id' => $this->ownerRole->id,
            'first_name' => $this->owner->name,
            'last_name' => 'User',
        ]);

        $this->owner->update(['current_store_id' => $this->store->id]);

        $this->warehouse = Warehouse::factory()->default()->create([
            'store_id' => $this->store->id,
        ]);

        $this->category = Category::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Jewelry',
        ]);

        app(StoreContext::class)->setCurrentStore($this->store);
    }

    /**
     * Fill an input by its CSS selector, dispatching the input event for Vue reactivity.
     */
    protected function fillInput(Browser $browser, string $selector, string $value): void
    {
        $escapedValue = addslashes($value);
        $escapedSelector = addslashes($selector);
        $browser->script("
            var el = document.querySelector('{$escapedSelector}');
            if (el) {
                var nativeSetter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
                nativeSetter.call(el, '{$escapedValue}');
                el.dispatchEvent(new Event('input', { bubbles: true }));
                el.dispatchEvent(new Event('change', { bubbles: true }));
            }
        ");
    }

    /**
     * Fill an input by index within a parent selector.
     */
    protected function fillInputByIndex(Browser $browser, string $parentSelector, int $index, string $value): void
    {
        $escapedValue = addslashes($value);
        $escapedParent = addslashes($parentSelector);
        $browser->script("
            var inputs = document.querySelectorAll('{$escapedParent} input[type=\"text\"], {$escapedParent} input[type=\"email\"], {$escapedParent} input[type=\"tel\"], {$escapedParent} input[type=\"number\"]');
            if (inputs[{$index}]) {
                var nativeSetter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
                nativeSetter.call(inputs[{$index}], '{$escapedValue}');
                inputs[{$index}].dispatchEvent(new Event('input', { bubbles: true }));
                inputs[{$index}].dispatchEvent(new Event('change', { bubbles: true }));
            }
        ");
    }
}
