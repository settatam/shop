<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Services\AI\AIManager;
use App\Services\Notifications\NotificationDataPreparer;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class NotificationTemplateController extends Controller
{
    public function __construct(
        protected NotificationDataPreparer $dataPreparer,
        protected StoreContext $storeContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = NotificationTemplate::query()
            ->withCount('subscriptions');

        if ($request->has('channel')) {
            $query->forChannel($request->input('channel'));
        }

        if ($request->has('category')) {
            $query->forCategory($request->input('category'));
        }

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $templates = $request->boolean('all')
            ? $query->orderBy('name')->get()
            : $query->orderBy('name')->paginate($request->input('per_page', 15));

        return response()->json($templates);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:100',
                'regex:/^[a-z0-9-]+$/',
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'channel' => ['required', 'string', Rule::in(NotificationChannel::TYPES)],
            'subject' => ['nullable', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'available_variables' => ['nullable', 'array'],
            'category' => ['nullable', 'string', 'max:50'],
            'is_enabled' => ['nullable', 'boolean'],
        ]);

        $storeId = $request->user()->currentStore()?->id;

        // Generate slug if not provided
        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        // Check uniqueness of slug for this store and channel
        $exists = NotificationTemplate::where('store_id', $storeId)
            ->where('slug', $validated['slug'])
            ->where('channel', $validated['channel'])
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'A template with this slug already exists for this channel',
            ], 422);
        }

        $template = NotificationTemplate::create([
            ...$validated,
            'store_id' => $storeId,
            'is_enabled' => $validated['is_enabled'] ?? true,
        ]);

        return response()->json($template, 201);
    }

    public function show(NotificationTemplate $notificationTemplate): JsonResponse
    {
        $notificationTemplate->load('subscriptions');

        return response()->json($notificationTemplate);
    }

    public function update(Request $request, NotificationTemplate $notificationTemplate): JsonResponse
    {
        if ($notificationTemplate->is_system && ! $request->user()->isStoreOwner()) {
            return response()->json([
                'message' => 'Cannot modify system templates',
            ], 403);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'subject' => ['nullable', 'string', 'max:255'],
            'content' => ['sometimes', 'string'],
            'available_variables' => ['nullable', 'array'],
            'category' => ['nullable', 'string', 'max:50'],
            'is_enabled' => ['nullable', 'boolean'],
        ]);

        $notificationTemplate->update($validated);

        return response()->json($notificationTemplate);
    }

    public function destroy(NotificationTemplate $notificationTemplate): JsonResponse
    {
        if ($notificationTemplate->is_system) {
            return response()->json([
                'message' => 'Cannot delete system templates',
            ], 403);
        }

        if ($notificationTemplate->subscriptions()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete template with active subscriptions',
            ], 422);
        }

        $notificationTemplate->delete();

        return response()->json(null, 204);
    }

    /**
     * Preview a template with sample data.
     */
    public function preview(Request $request, NotificationTemplate $notificationTemplate): JsonResponse
    {
        $validated = $request->validate([
            'data' => ['nullable', 'array'],
        ]);

        $sampleData = $validated['data'] ?? $this->dataPreparer->getSampleData();

        try {
            $content = $notificationTemplate->render($sampleData);
            $subject = $notificationTemplate->renderSubject($sampleData);

            return response()->json([
                'subject' => $subject,
                'content' => $content,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to render template',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Duplicate a template.
     */
    public function duplicate(Request $request, NotificationTemplate $notificationTemplate): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/'],
        ]);

        $newTemplate = $notificationTemplate->replicate();
        $newTemplate->name = $validated['name'] ?? $notificationTemplate->name.' (Copy)';
        $newTemplate->slug = $validated['slug'] ?? $notificationTemplate->slug.'-copy';
        $newTemplate->is_system = false;
        $newTemplate->save();

        return response()->json($newTemplate, 201);
    }

    /**
     * Get default templates list.
     */
    public function defaults(): JsonResponse
    {
        return response()->json(NotificationTemplate::getDefaultTemplates());
    }

    /**
     * Create default templates for the current store.
     */
    public function createDefaults(Request $request): JsonResponse
    {
        $storeId = $request->user()->currentStore()?->id;

        if (! $storeId) {
            return response()->json(['message' => 'No store selected'], 400);
        }

        NotificationTemplate::createDefaultTemplates($storeId);

        return response()->json([
            'message' => 'Default templates created successfully',
        ]);
    }

    /**
     * Apply AI-powered edits to a template.
     */
    public function aiEdit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'content' => ['required', 'string'],
            'subject' => ['nullable', 'string'],
            'prompt' => ['required', 'string', 'max:500'],
        ]);

        try {
            $ai = app(AIManager::class);

            $systemPrompt = <<<'PROMPT'
You are an email template editor. Modify the given HTML/Twig email template based on the user's request.

Rules:
1. Keep the existing Twig variables ({{ variable }}) intact unless asked to remove them
2. Maintain proper HTML structure
3. Apply the requested design changes
4. Keep inline CSS styles
5. Return ONLY the modified HTML content, no explanations
6. If asked to modify the subject, include a line at the start: SUBJECT: new subject here

Current template:
PROMPT;

            $userPrompt = "Template:\n{$validated['content']}\n\n";
            if ($validated['subject']) {
                $userPrompt .= "Current Subject: {$validated['subject']}\n\n";
            }
            $userPrompt .= "Requested changes: {$validated['prompt']}\n\nReturn the modified template:";

            $response = $ai->chatWithSystem($systemPrompt, $userPrompt, [
                'feature' => 'template_ai_edit',
            ]);

            $result = $response->content;

            // Check if subject was modified
            $newSubject = null;
            if (preg_match('/^SUBJECT:\s*(.+?)$/m', $result, $matches)) {
                $newSubject = trim($matches[1]);
                $result = preg_replace('/^SUBJECT:\s*.+?\n/m', '', $result);
            }

            return response()->json([
                'success' => true,
                'content' => trim($result),
                'subject' => $newSubject,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to process AI edit: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Send a test email with the current template content.
     */
    public function sendTest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'content' => ['required', 'string'],
            'subject' => ['nullable', 'string'],
            'email' => ['required', 'email'],
            'sample_data' => ['nullable', 'array'],
        ]);

        $store = $this->storeContext->getCurrentStore();

        if (! $store) {
            return response()->json([
                'success' => false,
                'error' => 'No store selected',
            ], 400);
        }

        try {
            // Render the template with sample data
            $sampleData = $validated['sample_data'] ?? $this->dataPreparer->getSampleData();

            $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader([
                'content' => $validated['content'],
                'subject' => $validated['subject'] ?? 'Template Test',
            ]), ['autoescape' => false]);

            $renderedContent = $twig->render('content', $sampleData);
            $renderedSubject = $twig->render('subject', $sampleData);

            // Create mailable
            $mailable = new \Illuminate\Mail\Mailable;
            $mailable->subject('[TEST] '.$renderedSubject)
                ->html($renderedContent);

            // Set from address
            $fromAddress = $store->email_from_address ?: config('mail.from.address');
            $fromName = $store->email_from_name ?: config('mail.from.name', $store->name);
            $mailable->from($fromAddress, $fromName);

            if ($store->email_reply_to_address) {
                $mailable->replyTo($store->email_reply_to_address);
            }

            // Send email
            Mail::to($validated['email'])->send($mailable);

            // Log the test email
            NotificationLog::create([
                'store_id' => $store->id,
                'channel' => 'email',
                'recipient' => $validated['email'],
                'subject' => '[TEST] '.$renderedSubject,
                'content' => $renderedContent,
                'status' => NotificationLog::STATUS_SENT,
                'metadata' => [
                    'type' => 'template_test',
                    'sent_by' => $request->user()->id,
                ],
            ]);

            return response()->json([
                'success' => true,
                'message' => "Test email sent to {$validated['email']}",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to send test email: '.$e->getMessage(),
            ], 500);
        }
    }
}
