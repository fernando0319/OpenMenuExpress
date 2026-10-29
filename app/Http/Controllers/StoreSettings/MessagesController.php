<?php

namespace App\Http\Controllers\StoreSettings;

use App\Contracts\MessagingProvider;
use App\Enums\MessageEvent;
use App\Http\Controllers\Concerns\InteractsWithCurrentStore;
use App\Http\Controllers\Controller;
use App\Messaging\Evolution\StoreWhatsAppConnection;
use App\Messaging\OrderMessageComposer;
use App\Models\MessageLog;
use App\Models\MessageTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Automatic WhatsApp messages: turn them on, edit each text, see what was sent.
 */
class MessagesController extends Controller
{
    use InteractsWithCurrentStore;

    public function edit(Request $request, MessagingProvider $provider, StoreWhatsAppConnection $connection): Response
    {
        $store = $this->currentStore();
        $custom = $store->messageTemplates()->get()->keyBy(fn (MessageTemplate $template) => $template->event->value);

        return Inertia::render('store-settings/Messages', [
            'settings' => [
                'whatsapp_enabled' => $store->whatsapp_enabled,
            ],
            'driver' => config('messaging.driver'),
            // Lazy so the page can poll just these two; the status check runs first because
            // it records the instance when the phone has just linked.
            'connection' => $status = fn () => once(fn () => $connection->status($store, $request->session())),
            'providerReady' => function () use ($status, $provider, $store): bool {
                $status();

                return $provider->isConfiguredFor($store->refresh());
            },
            'templates' => array_map(function (MessageEvent $event) use ($custom): array {
                $template = $custom->get($event->value);

                return [
                    'event' => $event->value,
                    'label' => $event->label(),
                    'body' => $template instanceof MessageTemplate ? $template->body : $event->defaultBody(),
                    'defaultBody' => $event->defaultBody(),
                    'isEnabled' => $template instanceof MessageTemplate ? $template->is_enabled : $event->enabledByDefault(),
                    'isCustom' => $template instanceof MessageTemplate,
                ];
            }, MessageEvent::cases()),
            'variables' => OrderMessageComposer::variables(),
            'recentLogs' => $store->messageLogs()
                ->with('order:id,number')
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (MessageLog $log) => [
                    'id' => $log->id,
                    'event' => $log->event->label(),
                    'orderNumber' => $log->order?->number,
                    'recipient' => $log->recipient,
                    'status' => $log->status,
                    'error' => $log->error,
                    'at' => $log->created_at->toIso8601String(),
                ]),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        // The instance name is set by the connection flow, never typed: a free field
        // would let a store send through another store's instance.
        $data = $request->validate([
            'whatsapp_enabled' => ['boolean'],
        ]);

        $this->currentStore()->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Configuração do WhatsApp salva.']);

        return to_route('store-settings.messages.edit');
    }

    public function updateTemplate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'event' => ['required', Rule::enum(MessageEvent::class)],
            'body' => ['required', 'string', 'max:1000'],
            'is_enabled' => ['boolean'],
        ], [
            'body.required' => 'A mensagem não pode ficar vazia. Para não enviar, desligue-a.',
        ]);

        $this->currentStore()->messageTemplates()->updateOrCreate(
            ['event' => $data['event']],
            ['body' => $data['body'], 'is_enabled' => $data['is_enabled'] ?? true],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Mensagem salva.']);

        return to_route('store-settings.messages.edit');
    }

    public function resetTemplate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'event' => ['required', Rule::enum(MessageEvent::class)],
        ]);

        $this->currentStore()->messageTemplates()->where('event', $data['event'])->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Mensagem voltou ao texto padrão.']);

        return to_route('store-settings.messages.edit');
    }
}
