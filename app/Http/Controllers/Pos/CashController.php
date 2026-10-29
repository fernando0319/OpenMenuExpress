<?php

namespace App\Http\Controllers\Pos;

use App\Enums\CashMovementType;
use App\Http\Controllers\Concerns\InteractsWithCurrentStore;
use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\User;
use App\Printing\PrintQueue;
use App\Rules\MoneyAmount;
use App\Services\Cash\CashRegister;
use App\Services\Cash\CashSummary;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Opening, supplies and withdrawals, closing, and the history of past sessions.
 */
class CashController extends Controller
{
    use InteractsWithCurrentStore;

    public function __construct(private readonly CashRegister $register) {}

    public function index(): Response
    {
        $store = $this->currentStore();
        $session = $this->register->current($store);

        return Inertia::render('pos/Cash', [
            'session' => $session === null ? null : $this->sessionPayload($session),
            'history' => $store->cashSessions()
                ->whereNotNull('closed_at')
                ->with(['openedBy:id,name', 'closedBy:id,name'])
                ->latest('closed_at')
                ->limit(15)
                ->get()
                ->map(fn (CashSession $closed) => [
                    'id' => $closed->id,
                    'openedAt' => $closed->opened_at->toIso8601String(),
                    'closedAt' => $closed->closed_at?->toIso8601String(),
                    'closedBy' => $closed->closedBy?->name,
                    'expectedCashCents' => $closed->expected_cash_cents,
                    'countedCashCents' => $closed->counted_cash_cents,
                    'differenceCents' => $closed->differenceCents(),
                ]),
        ]);
    }

    public function show(CashSession $session): Response
    {
        $this->ensureBelongsToCurrentStore($session);

        return Inertia::render('pos/CashSession', [
            'session' => $this->sessionPayload($session),
        ]);
    }

    public function open(Request $request): RedirectResponse
    {
        $request->validate(['opening' => ['nullable', new MoneyAmount]]);
        $opening = Money::parseToCents($request->input('opening') ?: '0') ?? 0;

        $this->register->open($this->currentStore(), $this->user($request), $opening);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Caixa aberto. Boas vendas!']);

        return back();
    }

    public function move(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in([CashMovementType::Supply->value, CashMovementType::Withdrawal->value])],
            'amount' => ['required', new MoneyAmount(minCents: 1)],
            'description' => ['nullable', 'string', 'max:120'],
        ], ['amount.required' => 'Informe o valor.']);

        $type = CashMovementType::from($data['type']);
        $amount = Money::parseToCents($data['amount']) ?? 0;

        $this->register->move($this->openSession(), $type, $amount, $data['description'] ?? null, $this->user($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$type->label()} de R$ ".Money::toInput($amount).' registrada.']);

        return back();
    }

    public function close(Request $request, PrintQueue $queue): RedirectResponse
    {
        $data = $request->validate([
            'counted' => ['required', new MoneyAmount],
            'notes' => ['nullable', 'string', 'max:500'],
            'print' => ['boolean'],
        ], ['counted.required' => 'Informe quanto há em dinheiro na gaveta.']);

        $session = $this->register->close($this->openSession(), Money::parseToCents($data['counted']) ?? 0, $data['notes'] ?? null, $this->user($request));

        if ($request->boolean('print')) {
            $queue->queueCashReport($session);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Caixa fechado.']);

        return to_route('cash.show', $session);
    }

    public function print(CashSession $session, PrintQueue $queue): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($session);

        $count = $queue->queueCashReport($session);

        Inertia::flash('toast', $count > 0
            ? ['type' => 'success', 'message' => 'Relatório do caixa enviado para impressão.']
            : ['type' => 'warning', 'message' => 'Nenhuma impressora ativa. Configure em Impressão.']);

        return back();
    }

    private function openSession(): CashSession
    {
        $session = $this->register->current($this->currentStore());

        if ($session === null) {
            throw ValidationException::withMessages(['cash' => 'O caixa está fechado.']);
        }

        return $session;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionPayload(CashSession $session): array
    {
        $session->loadMissing(['openedBy:id,name', 'closedBy:id,name']);

        return [
            'id' => $session->id,
            'isOpen' => $session->isOpen(),
            'openedAt' => $session->opened_at->toIso8601String(),
            'openedBy' => $session->openedBy?->name,
            'closedAt' => $session->closed_at?->toIso8601String(),
            'closedBy' => $session->closedBy?->name,
            'expectedCashCents' => $session->expected_cash_cents,
            'countedCashCents' => $session->counted_cash_cents,
            'differenceCents' => $session->differenceCents(),
            'notes' => $session->notes,
            'summary' => CashSummary::of($session)->toArray(),
            'movements' => $session->movements()
                ->with(['user:id,name', 'order:id,number'])
                ->latest('id')
                ->get()
                ->map(fn (CashMovement $movement) => [
                    'id' => $movement->id,
                    'type' => $movement->type->value,
                    'typeLabel' => $movement->type->label(),
                    'method' => $movement->payment_method?->label(),
                    'signedCents' => $movement->signedCents(),
                    'description' => $movement->description,
                    'orderId' => $movement->order_id,
                    'by' => $movement->user?->name,
                    'at' => $movement->created_at?->toIso8601String(),
                ]),
        ];
    }
}
