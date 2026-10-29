<?php

namespace App\Services\CourierNetwork;

use App\Enums\CourierStatus;
use App\Jobs\SendPushNotification;
use App\Models\AdminAuditLog;
use App\Models\NetworkCourier;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Couriers joining the network and the platform owner's review of them. The document photo
 * stays on the private disk: only the platform owner sees it.
 */
final class CourierRegistry
{
    public const DOCUMENTS_DIR = 'courier-documents';

    /**
     * @param  array{cpf: string, phone: string, city: string, state: string, vehicle: string, vehicle_plate: string|null, pix_key_type: string, pix_key: string}  $data
     */
    public function register(User $user, array $data, UploadedFile $document): NetworkCourier
    {
        if ($user->networkCourier()->exists()) {
            throw ValidationException::withMessages(['cpf' => 'Você já tem cadastro na rede de entregadores.']);
        }

        $path = $document->storeAs(self::DOCUMENTS_DIR, Str::random(40).'.'.$document->extension(), 'local');

        if ($path === false) {
            throw ValidationException::withMessages(['document' => 'Não foi possível salvar o documento. Tente de novo.']);
        }

        try {
            return $user->networkCourier()->create([...$data, 'document_path' => $path, 'status' => CourierStatus::Pending]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }
    }

    public function review(NetworkCourier $courier, CourierStatus $status, User $admin, ?string $reason, ?string $ip = null): void
    {
        DB::transaction(function () use ($courier, $status, $admin, $reason, $ip): void {
            $courier->update([
                'status' => $status,
                'status_reason' => $status === CourierStatus::Approved ? null : $reason,
                'reviewed_at' => now(),
                'reviewed_by' => $admin->id,
                // Out of the network: no more offers.
                'is_online' => $status === CourierStatus::Approved && $courier->is_online,
            ]);

            AdminAuditLog::query()->create([
                'user_id' => $admin->id,
                'store_id' => null,
                'action' => 'network.courier_'.$status->value,
                'details' => ['courier' => $courier->id, 'name' => $courier->user->name, 'reason' => $reason],
                'ip' => $ip,
            ]);

            [$title, $body] = match ($status) {
                CourierStatus::Approved => ['Cadastro aprovado!', 'Abra o app e fique online para receber corridas.'],
                CourierStatus::Rejected => ['Cadastro não aprovado', $reason ?? 'Abra o app para ver o motivo.'],
                default => ['Acesso à rede bloqueado', $reason ?? 'Abra o app para ver o motivo.'],
            };

            SendPushNotification::dispatch([$courier->user_id], $title, $body, '/entregador', 'registration');
        });
    }
}
