<?php

namespace App\Jobs;

use App\Models\ActivityLog;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\Notifications\NotificationManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class TriggerActivityNotifications implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public ActivityLog $activityLog
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $store = Store::with('owner')->find($this->activityLog->store_id);

        if (! $store) {
            return;
        }

        try {
            $manager = new NotificationManager($store);

            // Build the context data for the notification
            $data = $this->buildContextData();

            // Trigger notifications for this activity
            $manager->trigger(
                $this->activityLog->activity_slug,
                $data,
                $this->activityLog->subject
            );
        } catch (\Exception $e) {
            Log::error('Failed to trigger activity notifications', [
                'activity_log_id' => $this->activityLog->id,
                'activity' => $this->activityLog->activity_slug,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Build the context data for the notification templates.
     */
    protected function buildContextData(): array
    {
        $data = [
            'activity' => $this->activityLog->activity_slug,
            'description' => $this->activityLog->description,
            'properties' => $this->activityLog->properties ?? [],
            'timestamp' => $this->activityLog->created_at,
        ];

        // Add the subject data if available
        if ($subject = $this->activityLog->subject) {
            $subjectName = class_basename($subject);
            $data[strtolower($subjectName)] = $subject->toArray();

            // Map common model types to standard names
            $data = $this->mapSubjectToStandardName($subject, $data);
        }

        // Add the causer (user who performed the action)
        if ($causer = $this->activityLog->causer) {
            $data['user'] = $causer->toArray();
        }

        // Add any properties from the activity log
        if (! empty($this->activityLog->properties)) {
            foreach ($this->activityLog->properties as $key => $value) {
                if (! isset($data[$key])) {
                    $data[$key] = $value;
                }
            }
        }

        return $data;
    }

    /**
     * Map subject model to standard template variable names.
     */
    protected function mapSubjectToStandardName($subject, array $data): array
    {
        $className = class_basename($subject);

        // Map to standard names used in templates
        $mappings = [
            'Order' => 'order',
            'Product' => 'product',
            'ProductVariant' => 'variant',
            'Customer' => 'customer',
            'Inventory' => 'inventory',
            'Category' => 'category',
            'StoreUser' => 'team_member',
            'Role' => 'role',
            'Transaction' => 'transaction',
        ];

        if (isset($mappings[$className])) {
            $standardName = $mappings[$className];
            if (! isset($data[$standardName])) {
                $data[$standardName] = $subject->toArray();
            }
        }

        // For ProductVariant, also include the parent product data
        if ($subject instanceof ProductVariant) {
            $product = $subject->product;
            if ($product && ! isset($data['product'])) {
                $data['product'] = $product->toArray();
            }
        }

        return $data;
    }
}
