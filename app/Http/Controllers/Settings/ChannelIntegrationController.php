<?php

namespace App\Http\Controllers\Settings;

use App\Enums\ConversationChannel;
use App\Http\Controllers\Controller;
use App\Models\ChannelConfiguration;
use App\Services\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ChannelIntegrationController extends Controller
{
    public function __construct(protected StoreContext $storeContext) {}

    public function index(): Response
    {
        $store = $this->storeContext->getCurrentStore();

        $configurations = ChannelConfiguration::where('store_id', $store->id)
            ->get()
            ->map(fn (ChannelConfiguration $config) => [
                'id' => $config->id,
                'channel' => $config->channel->value,
                'channel_label' => $config->channel->label(),
                'is_active' => $config->is_active,
                'has_credentials' => ! empty($config->credentials),
                'credentials_summary' => $this->getCredentialsSummary($config),
            ]);

        $availableChannels = collect([ConversationChannel::WhatsApp, ConversationChannel::Slack])
            ->map(fn (ConversationChannel $channel) => [
                'value' => $channel->value,
                'label' => $channel->label(),
            ]);

        return Inertia::render('settings/Integrations', [
            'configurations' => $configurations,
            'availableChannels' => $availableChannels,
            'webhookBaseUrl' => url('/api/webhooks'),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        $channelValues = collect([ConversationChannel::WhatsApp, ConversationChannel::Slack])
            ->map(fn ($c) => $c->value)
            ->all();

        $validated = $request->validate([
            'channel' => ['required', 'string', Rule::in($channelValues)],
        ]);

        $channel = ConversationChannel::from($validated['channel']);

        // Validate credentials based on channel type
        $credentialRules = match ($channel) {
            ConversationChannel::WhatsApp => [
                'credentials.phone_number_id' => ['required', 'string', 'max:255'],
                'credentials.access_token' => ['required', 'string', 'max:1000'],
            ],
            ConversationChannel::Slack => [
                'credentials.bot_token' => ['required', 'string', 'max:1000'],
            ],
            default => [],
        };

        $request->validate($credentialRules);

        $credentials = $request->input('credentials', []);

        ChannelConfiguration::updateOrCreate(
            [
                'store_id' => $store->id,
                'channel' => $channel,
            ],
            [
                'credentials' => $credentials,
            ],
        );

        return back()->with('success', $channel->label().' integration saved successfully.');
    }

    public function toggle(Request $request): RedirectResponse
    {
        $store = $this->storeContext->getCurrentStore();

        $validated = $request->validate([
            'channel' => ['required', 'string'],
            'is_active' => ['required', 'boolean'],
        ]);

        $config = ChannelConfiguration::where('store_id', $store->id)
            ->where('channel', $validated['channel'])
            ->firstOrFail();

        $config->update(['is_active' => $validated['is_active']]);

        $status = $validated['is_active'] ? 'enabled' : 'disabled';

        return back()->with('success', $config->channel->label()." integration {$status}.");
    }

    public function destroy(ChannelConfiguration $channelConfiguration): RedirectResponse
    {
        $channelConfiguration->delete();

        return back()->with('success', 'Integration removed successfully.');
    }

    /**
     * @return array<string, string>
     */
    protected function getCredentialsSummary(ChannelConfiguration $config): array
    {
        $credentials = $config->credentials ?? [];

        return match ($config->channel) {
            ConversationChannel::WhatsApp => [
                'phone_number_id' => $this->maskValue($credentials['phone_number_id'] ?? ''),
            ],
            ConversationChannel::Slack => [
                'bot_token' => $this->maskValue($credentials['bot_token'] ?? ''),
            ],
            default => [],
        };
    }

    protected function maskValue(string $value): string
    {
        if (strlen($value) <= 8) {
            return str_repeat('*', strlen($value));
        }

        return substr($value, 0, 4).'...'.substr($value, -4);
    }
}
