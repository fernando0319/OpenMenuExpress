<?php

namespace App\Services\Admin;

use App\Models\AdminAuditLog;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Interventions on a hosted store. Every one is written to the audit log.
 */
final class StoreModeration
{
    public function suspend(Store $store, User $admin, string $reason, ?string $ip = null): void
    {
        DB::transaction(function () use ($store, $admin, $reason, $ip): void {
            $store->update(['suspended_at' => now(), 'suspension_reason' => $reason]);

            $this->log($store, $admin, AdminAuditLog::ACTION_SUSPEND, ['reason' => $reason], $ip);
        });
    }

    public function reactivate(Store $store, User $admin, ?string $ip = null): void
    {
        DB::transaction(function () use ($store, $admin, $ip): void {
            $previousReason = $store->suspension_reason;

            $store->update(['suspended_at' => null, 'suspension_reason' => null]);

            $this->log($store, $admin, AdminAuditLog::ACTION_REACTIVATE, ['previous_reason' => $previousReason], $ip);
        });
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function log(Store $store, User $admin, string $action, array $details, ?string $ip): void
    {
        AdminAuditLog::query()->create([
            'user_id' => $admin->id,
            'store_id' => $store->id,
            'action' => $action,
            'details' => $details,
            'ip' => $ip,
        ]);
    }
}
