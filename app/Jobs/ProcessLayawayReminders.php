<?php

namespace App\Jobs;

use App\Models\Activity;
use App\Models\Layaway;
use App\Models\LayawaySchedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessLayawayReminders implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * Days before due date to send reminders.
     *
     * @var array<int>
     */
    protected array $reminderDays = [7, 3, 1];

    /**
     * Days after due date to send overdue notices.
     *
     * @var array<int>
     */
    protected array $overdueDays = [1, 7, 14];

    public function __construct() {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Processing layaway reminders...');

        $this->processUpcomingPayments();
        $this->processOverduePayments();
        $this->markOverdueSchedules();

        Log::info('Layaway reminders processing complete.');
    }

    /**
     * Process and notify about upcoming payments.
     */
    protected function processUpcomingPayments(): void
    {
        foreach ($this->reminderDays as $days) {
            $this->sendRemindersForDaysOut($days);
        }
    }

    /**
     * Send reminders for payments due in X days.
     */
    protected function sendRemindersForDaysOut(int $days): void
    {
        $targetDate = now()->addDays($days)->toDateString();

        // For flexible payment type layaways, check the due_date on the layaway itself
        $flexibleLayaways = Layaway::with(['customer', 'store'])
            ->where('status', Layaway::STATUS_ACTIVE)
            ->where('payment_type', Layaway::PAYMENT_TYPE_FLEXIBLE)
            ->whereDate('due_date', $targetDate)
            ->where('balance_due', '>', 0)
            ->get();

        foreach ($flexibleLayaways as $layaway) {
            $this->logPaymentDueSoon($layaway, $days);
        }

        // For scheduled payment type, check the schedule due dates
        $scheduledPayments = LayawaySchedule::with(['layaway.customer', 'layaway.store'])
            ->whereHas('layaway', function ($query) {
                $query->where('status', Layaway::STATUS_ACTIVE)
                    ->where('payment_type', Layaway::PAYMENT_TYPE_SCHEDULED);
            })
            ->where('status', LayawaySchedule::STATUS_PENDING)
            ->whereDate('due_date', $targetDate)
            ->get();

        foreach ($scheduledPayments as $schedule) {
            $this->logScheduledPaymentDueSoon($schedule, $days);
        }
    }

    /**
     * Process and notify about overdue payments.
     */
    protected function processOverduePayments(): void
    {
        foreach ($this->overdueDays as $days) {
            $this->sendOverdueNoticesForDaysLate($days);
        }
    }

    /**
     * Send overdue notices for payments that are X days late.
     */
    protected function sendOverdueNoticesForDaysLate(int $days): void
    {
        $targetDate = now()->subDays($days)->toDateString();

        // For flexible payment type layaways
        $overdueLayaways = Layaway::with(['customer', 'store'])
            ->where('status', Layaway::STATUS_ACTIVE)
            ->where('payment_type', Layaway::PAYMENT_TYPE_FLEXIBLE)
            ->whereDate('due_date', $targetDate)
            ->where('balance_due', '>', 0)
            ->get();

        foreach ($overdueLayaways as $layaway) {
            $this->logPaymentOverdue($layaway, $days);
        }

        // For scheduled payment type
        $overdueSchedules = LayawaySchedule::with(['layaway.customer', 'layaway.store'])
            ->whereHas('layaway', function ($query) {
                $query->where('status', Layaway::STATUS_ACTIVE)
                    ->where('payment_type', Layaway::PAYMENT_TYPE_SCHEDULED);
            })
            ->whereIn('status', [LayawaySchedule::STATUS_PENDING, LayawaySchedule::STATUS_OVERDUE])
            ->whereDate('due_date', $targetDate)
            ->where('amount_paid', '<', \DB::raw('amount_due'))
            ->get();

        foreach ($overdueSchedules as $schedule) {
            $this->logScheduledPaymentOverdue($schedule, $days);
        }
    }

    /**
     * Mark pending schedules as overdue if their due date has passed.
     */
    protected function markOverdueSchedules(): void
    {
        $overdueSchedules = LayawaySchedule::whereHas('layaway', function ($query) {
            $query->where('status', Layaway::STATUS_ACTIVE);
        })
            ->where('status', LayawaySchedule::STATUS_PENDING)
            ->whereDate('due_date', '<', now())
            ->where('amount_paid', '<', \DB::raw('amount_due'))
            ->get();

        foreach ($overdueSchedules as $schedule) {
            $schedule->markOverdue();

            Log::info('Marked layaway schedule as overdue', [
                'schedule_id' => $schedule->id,
                'layaway_id' => $schedule->layaway_id,
                'due_date' => $schedule->due_date->toDateString(),
            ]);
        }
    }

    /**
     * Log payment due soon activity for a flexible layaway.
     */
    protected function logPaymentDueSoon(Layaway $layaway, int $daysUntilDue): void
    {
        activity()
            ->causedByAnonymous()
            ->performedOn($layaway)
            ->withProperties([
                'layaway_number' => $layaway->layaway_number,
                'customer_name' => $layaway->customer?->full_name,
                'balance_due' => $layaway->balance_due,
                'due_date' => $layaway->due_date->toDateString(),
                'days_until_due' => $daysUntilDue,
            ])
            ->log(Activity::LAYAWAYS_PAYMENT_DUE_SOON);
    }

    /**
     * Log payment due soon activity for a scheduled payment.
     */
    protected function logScheduledPaymentDueSoon(LayawaySchedule $schedule, int $daysUntilDue): void
    {
        $layaway = $schedule->layaway;

        activity()
            ->causedByAnonymous()
            ->performedOn($layaway)
            ->withProperties([
                'layaway_number' => $layaway->layaway_number,
                'customer_name' => $layaway->customer?->full_name,
                'installment_number' => $schedule->installment_number,
                'amount_due' => $schedule->remaining_amount,
                'due_date' => $schedule->due_date->toDateString(),
                'days_until_due' => $daysUntilDue,
            ])
            ->log(Activity::LAYAWAYS_PAYMENT_DUE_SOON);
    }

    /**
     * Log payment overdue activity for a flexible layaway.
     */
    protected function logPaymentOverdue(Layaway $layaway, int $daysOverdue): void
    {
        activity()
            ->causedByAnonymous()
            ->performedOn($layaway)
            ->withProperties([
                'layaway_number' => $layaway->layaway_number,
                'customer_name' => $layaway->customer?->full_name,
                'balance_due' => $layaway->balance_due,
                'due_date' => $layaway->due_date->toDateString(),
                'days_overdue' => $daysOverdue,
            ])
            ->log(Activity::LAYAWAYS_PAYMENT_OVERDUE);
    }

    /**
     * Log payment overdue activity for a scheduled payment.
     */
    protected function logScheduledPaymentOverdue(LayawaySchedule $schedule, int $daysOverdue): void
    {
        $layaway = $schedule->layaway;

        activity()
            ->causedByAnonymous()
            ->performedOn($layaway)
            ->withProperties([
                'layaway_number' => $layaway->layaway_number,
                'customer_name' => $layaway->customer?->full_name,
                'installment_number' => $schedule->installment_number,
                'amount_due' => $schedule->remaining_amount,
                'due_date' => $schedule->due_date->toDateString(),
                'days_overdue' => $daysOverdue,
            ])
            ->log(Activity::LAYAWAYS_PAYMENT_OVERDUE);
    }
}
