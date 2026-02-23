<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

class EmailSettingsController extends Controller
{
    public function __construct(
        protected StoreContext $storeContext,
    ) {}

    /**
     * Display the email settings page.
     */
    public function index(): Response|RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        return Inertia::render('settings/Email', [
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'email_from_address' => $store->email_from_address,
                'email_from_name' => $store->email_from_name,
                'email_reply_to_address' => $store->email_reply_to_address,
            ],
            'mailProvider' => config('mail.default'),
            'sesConfigured' => $this->isSesConfigured(),
        ]);
    }

    /**
     * Update the email settings.
     */
    public function update(Request $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return redirect()->route('dashboard')
                ->with('error', 'Please select a store first.');
        }

        $validated = $request->validate([
            'email_from_address' => ['nullable', 'email', 'max:255'],
            'email_from_name' => ['nullable', 'string', 'max:255'],
            'email_reply_to_address' => ['nullable', 'email', 'max:255'],
        ]);

        $store->update([
            'email_from_address' => $validated['email_from_address'],
            'email_from_name' => $validated['email_from_name'],
            'email_reply_to_address' => $validated['email_reply_to_address'],
        ]);

        return back()->with('success', 'Email settings updated successfully.');
    }

    /**
     * Send a test email to verify the configuration.
     */
    public function sendTest(Request $request): JsonResponse
    {
        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json([
                'success' => false,
                'message' => 'Please select a store first.',
            ], 422);
        }

        $validated = $request->validate([
            'test_email' => ['required', 'email'],
        ]);

        try {
            $fromAddress = $store->email_from_address ?: config('mail.from.address');
            $fromName = $store->email_from_name ?: config('mail.from.name', $store->name);
            $replyTo = $store->email_reply_to_address;

            Mail::raw('This is a test email from '.$store->name.' to verify your email configuration is working correctly.', function ($message) use ($validated, $fromAddress, $fromName, $replyTo, $store) {
                $message->to($validated['test_email'])
                    ->from($fromAddress, $fromName)
                    ->subject('Test Email from '.$store->name);

                if ($replyTo) {
                    $message->replyTo($replyTo);
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'Test email sent successfully to '.$validated['test_email'],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send test email: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check if Amazon SES is configured.
     */
    protected function isSesConfigured(): bool
    {
        $driver = config('mail.default');

        if ($driver !== 'ses') {
            return false;
        }

        $key = config('services.ses.key');
        $secret = config('services.ses.secret');
        $region = config('services.ses.region');

        return ! empty($key) && ! empty($secret) && ! empty($region);
    }
}
