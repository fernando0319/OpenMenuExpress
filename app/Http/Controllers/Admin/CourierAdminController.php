<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CourierStatus;
use App\Enums\NetworkDeliveryStatus;
use App\Http\Controllers\Controller;
use App\Models\CourierPayout;
use App\Models\NetworkCourier;
use App\Models\NetworkDelivery;
use App\Models\User;
use App\Services\CourierNetwork\CourierRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Network couriers: reviewing registrations, and blocking or bringing them back.
 */
class CourierAdminController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'blocked', 'all'])],
            'search' => ['nullable', 'string', 'max:80'],
        ]);
        $status = $filters['status'] ?? 'pending';
        $search = trim((string) ($filters['search'] ?? ''));

        $couriers = NetworkCourier::query()
            ->with('user:id,name,email')
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', $status))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('city', 'like', "%{$search}%")
                ->orWhere('cpf', preg_replace('/\D/', '', $search) ?: '-')
                ->orWhereHas('user', fn (Builder $query) => $query->where('name', 'like', "%{$search}%"))))
            ->orderByRaw('case when status = ? then 0 else 1 end', [CourierStatus::Pending->value])
            ->latest('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (NetworkCourier $courier) => [
                'id' => $courier->id,
                'name' => $courier->user->name,
                'city' => "{$courier->city}/{$courier->state}",
                'vehicle' => $courier->vehicleLabel(),
                'status' => $courier->status->value,
                'statusLabel' => $courier->status->label(),
                'online' => $courier->isAvailable(),
                'createdAt' => $courier->created_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/network/Couriers', [
            'couriers' => $couriers,
            'filters' => ['status' => $status, 'search' => $search],
            'pendingCount' => NetworkCourier::query()->where('status', CourierStatus::Pending)->count(),
        ]);
    }

    public function show(NetworkCourier $courier): Response
    {
        $courier->load('user:id,name,email');
        $delivered = $courier->deliveries()->where('status', NetworkDeliveryStatus::Delivered);

        return Inertia::render('admin/network/CourierDetail', [
            'courier' => [
                'id' => $courier->id,
                'name' => $courier->user->name,
                'email' => $courier->user->email,
                'cpf' => $courier->cpf,
                'phone' => $courier->phone,
                'city' => $courier->city,
                'state' => $courier->state,
                'vehicle' => $courier->vehicleLabel(),
                'pixKeyType' => $courier->pix_key_type->label(),
                'pixKey' => $courier->pix_key,
                'status' => $courier->status->value,
                'statusLabel' => $courier->status->label(),
                'statusReason' => $courier->status_reason,
                'reviewedAt' => $courier->reviewed_at?->toIso8601String(),
                'online' => $courier->isAvailable(),
                'lastSeenAt' => $courier->located_at?->toIso8601String(),
                'createdAt' => $courier->created_at?->toIso8601String(),
                'hasDocument' => $courier->document_path !== null,
                'documentIsPdf' => str_ends_with((string) $courier->document_path, '.pdf'),
            ],
            'stats' => [
                'runs' => (clone $delivered)->count(),
                'earnedCents' => (int) (clone $delivered)->sum('courier_cents'),
                'cancelledAfterAccept' => $courier->deliveries()->where('status', NetworkDeliveryStatus::Cancelled)->count(),
            ],
            'runs' => $courier->deliveries()
                ->with('store:id,name')
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (NetworkDelivery $delivery) => [
                    'id' => $delivery->id,
                    'storeName' => $delivery->store->name,
                    'statusLabel' => $delivery->status->label(),
                    'courierCents' => $delivery->courier_cents,
                    'at' => ($delivery->delivered_at ?? $delivery->created_at)?->toIso8601String(),
                ]),
            'payouts' => $courier->payouts()->orderByDesc('period_start')->limit(8)->get()->map(fn (CourierPayout $payout) => [
                'id' => $payout->id,
                'period' => $payout->periodLabel(),
                'amountCents' => $payout->amount_cents,
                'status' => $payout->status,
            ]),
        ]);
    }

    public function review(Request $request, NetworkCourier $courier, CourierRegistry $registry): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([CourierStatus::Approved->value, CourierStatus::Rejected->value, CourierStatus::Blocked->value])],
            'reason' => ['nullable', 'required_unless:status,approved', 'string', 'max:255'],
        ], ['reason.required_unless' => 'Explique o motivo: o entregador verá esta mensagem.']);

        $admin = $request->user();
        abort_unless($admin instanceof User, 403);

        $status = CourierStatus::from($data['status']);
        $registry->review($courier, $status, $admin, $data['reason'] ?? null, $request->ip());

        Inertia::flash('toast', ['type' => 'success', 'message' => match ($status) {
            CourierStatus::Approved => "{$courier->user->name} aprovado na rede.",
            CourierStatus::Rejected => "Cadastro de {$courier->user->name} recusado.",
            default => "{$courier->user->name} bloqueado.",
        }]);

        return back();
    }

    /**
     * The document photo, from the private disk. Never cached by the browser.
     */
    public function document(NetworkCourier $courier): StreamedResponse
    {
        $path = $courier->document_path;
        abort_unless($path !== null && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
