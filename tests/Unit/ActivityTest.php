<?php

namespace Tests\Unit;

use App\Models\Activity;
use PHPUnit\Framework\TestCase;

class ActivityTest extends TestCase
{
    public function test_get_permission_groups_returns_expected_groups(): void
    {
        $groups = Activity::getPermissionGroups();

        $this->assertArrayHasKey('sales', $groups);
        $this->assertArrayHasKey('inventory', $groups);
        $this->assertArrayHasKey('purchasing', $groups);
        $this->assertArrayHasKey('reports', $groups);
        $this->assertArrayHasKey('administration', $groups);
    }

    public function test_permission_groups_have_required_structure(): void
    {
        $groups = Activity::getPermissionGroups();

        foreach ($groups as $key => $group) {
            $this->assertArrayHasKey('name', $group, "Group '{$key}' should have a name");
            $this->assertArrayHasKey('description', $group, "Group '{$key}' should have a description");
            $this->assertArrayHasKey('categories', $group, "Group '{$key}' should have categories");
            $this->assertIsArray($group['categories'], "Group '{$key}' categories should be an array");
            $this->assertNotEmpty($group['categories'], "Group '{$key}' should have at least one category");
        }
    }

    public function test_sales_group_contains_expected_categories(): void
    {
        $groups = Activity::getPermissionGroups();

        $this->assertContains(Activity::CATEGORY_ORDERS, $groups['sales']['categories']);
        $this->assertContains(Activity::CATEGORY_LAYAWAYS, $groups['sales']['categories']);
        $this->assertContains(Activity::CATEGORY_REPAIRS, $groups['sales']['categories']);
        $this->assertContains(Activity::CATEGORY_CUSTOMERS, $groups['sales']['categories']);
    }

    public function test_inventory_group_contains_expected_categories(): void
    {
        $groups = Activity::getPermissionGroups();

        $this->assertContains(Activity::CATEGORY_PRODUCTS, $groups['inventory']['categories']);
        $this->assertContains(Activity::CATEGORY_INVENTORY, $groups['inventory']['categories']);
    }

    public function test_purchasing_group_contains_expected_categories(): void
    {
        $groups = Activity::getPermissionGroups();

        $this->assertContains(Activity::CATEGORY_TRANSACTIONS, $groups['purchasing']['categories']);
        $this->assertContains(Activity::CATEGORY_VENDORS, $groups['purchasing']['categories']);
        $this->assertContains(Activity::CATEGORY_MEMOS, $groups['purchasing']['categories']);
    }

    public function test_reports_group_contains_expected_categories(): void
    {
        $groups = Activity::getPermissionGroups();

        $this->assertContains(Activity::CATEGORY_REPORTS, $groups['reports']['categories']);
    }

    public function test_administration_group_contains_expected_categories(): void
    {
        $groups = Activity::getPermissionGroups();

        $this->assertContains(Activity::CATEGORY_TEAM, $groups['administration']['categories']);
        $this->assertContains(Activity::CATEGORY_STORE, $groups['administration']['categories']);
        $this->assertContains(Activity::CATEGORY_INTEGRATIONS, $groups['administration']['categories']);
    }

    public function test_get_category_display_names_returns_all_categories(): void
    {
        $displayNames = Activity::getCategoryDisplayNames();

        $this->assertArrayHasKey(Activity::CATEGORY_PRODUCTS, $displayNames);
        $this->assertArrayHasKey(Activity::CATEGORY_ORDERS, $displayNames);
        $this->assertArrayHasKey(Activity::CATEGORY_TRANSACTIONS, $displayNames);
        $this->assertArrayHasKey(Activity::CATEGORY_INVENTORY, $displayNames);
        $this->assertArrayHasKey(Activity::CATEGORY_CUSTOMERS, $displayNames);
        $this->assertArrayHasKey(Activity::CATEGORY_INTEGRATIONS, $displayNames);
        $this->assertArrayHasKey(Activity::CATEGORY_STORE, $displayNames);
        $this->assertArrayHasKey(Activity::CATEGORY_TEAM, $displayNames);
        $this->assertArrayHasKey(Activity::CATEGORY_REPORTS, $displayNames);
        $this->assertArrayHasKey(Activity::CATEGORY_MEMOS, $displayNames);
        $this->assertArrayHasKey(Activity::CATEGORY_REPAIRS, $displayNames);
        $this->assertArrayHasKey(Activity::CATEGORY_VENDORS, $displayNames);
        $this->assertArrayHasKey(Activity::CATEGORY_BUCKETS, $displayNames);
        $this->assertArrayHasKey(Activity::CATEGORY_LAYAWAYS, $displayNames);
    }

    public function test_category_display_names_are_readable_strings(): void
    {
        $displayNames = Activity::getCategoryDisplayNames();

        foreach ($displayNames as $key => $name) {
            $this->assertIsString($name, "Display name for '{$key}' should be a string");
            $this->assertNotEmpty($name, "Display name for '{$key}' should not be empty");
        }
    }

    public function test_all_group_categories_have_display_names(): void
    {
        $groups = Activity::getPermissionGroups();
        $displayNames = Activity::getCategoryDisplayNames();

        foreach ($groups as $groupKey => $group) {
            foreach ($group['categories'] as $category) {
                $this->assertArrayHasKey(
                    $category,
                    $displayNames,
                    "Category '{$category}' in group '{$groupKey}' should have a display name"
                );
            }
        }
    }
}
