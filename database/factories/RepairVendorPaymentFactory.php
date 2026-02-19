<?php

namespace Database\Factories;

use App\Models\Repair;
use App\Models\RepairVendorPayment;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RepairVendorPayment>
 */
class RepairVendorPaymentFactory extends Factory
{
    protected $model = RepairVendorPayment::class;

    public function definition(): array
    {
        $amount = fake()->randomFloat(2, 50, 500);
        $invoiceAmount = fake()->optional(0.7)->randomFloat(2, $amount * 0.9, $amount * 1.1);

        return [
            'store_id' => Store::factory(),
            'repair_id' => Repair::factory(),
            'vendor_id' => Vendor::factory(),
            'user_id' => User::factory(),
            'check_number' => fake()->optional(0.6)->numerify('CHK-####'),
            'amount' => $amount,
            'vendor_invoice_amount' => $invoiceAmount,
            'reason' => fake()->optional(0.5)->sentence(),
            'payment_date' => fake()->optional(0.8)->dateTimeBetween('-30 days', 'now'),
            'attachment_path' => null,
            'attachment_name' => null,
        ];
    }

    public function withAttachment(): static
    {
        return $this->state(fn (array $attributes) => [
            'attachment_path' => 'vendor-payments/'.fake()->uuid().'.pdf',
            'attachment_name' => 'invoice-'.fake()->numerify('###').'.pdf',
        ]);
    }

    public function withCheckNumber(string $checkNumber): static
    {
        return $this->state(fn (array $attributes) => [
            'check_number' => $checkNumber,
        ]);
    }

    public function forRepair(Repair $repair): static
    {
        return $this->state(fn (array $attributes) => [
            'store_id' => $repair->store_id,
            'repair_id' => $repair->id,
            'vendor_id' => $repair->vendor_id,
        ]);
    }

    public function forVendor(Vendor $vendor): static
    {
        return $this->state(fn (array $attributes) => [
            'vendor_id' => $vendor->id,
        ]);
    }

    public function recordedBy(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }
}
