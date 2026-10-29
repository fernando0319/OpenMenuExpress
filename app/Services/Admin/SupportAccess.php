<?php

namespace App\Services\Admin;

use App\Models\AdminAuditLog;
use App\Models\Store;
use App\Models\User;
use App\Support\SupportSession;
use Illuminate\Http\Request;

/**
 * Starts and ends support mode, writing each step to the audit log
 * (which the store owner can also see).
 */
final class SupportAccess
{
    public function start(Request $request, User $admin, Store $store, string $mode, string $reason): void
    {
        $this->end($request, $admin, 'replaced');

        SupportSession::start($request, $store, $mode, $reason);

        $this->log($request, $admin, $store->id, AdminAuditLog::ACTION_SUPPORT_START, [
            'mode' => $mode,
            'reason' => $reason,
            'minutes' => SupportSession::MINUTES,
        ]);
    }

    /**
     * @param  'manual'|'expired'|'replaced'  $why
     */
    public function end(Request $request, User $admin, string $why = 'manual'): ?int
    {
        $session = SupportSession::raw($request);

        if ($session === null) {
            return null;
        }

        SupportSession::stop($request);

        $this->log($request, $admin, $session['store_id'], AdminAuditLog::ACTION_SUPPORT_STOP, ['why' => $why]);

        return $session['store_id'];
    }

    /**
     * Every change made in full support mode, so the owner knows what was touched.
     */
    public function recordChange(Request $request, User $admin, int $storeId): void
    {
        $this->log($request, $admin, $storeId, AdminAuditLog::ACTION_SUPPORT_CHANGE, [
            'method' => $request->method(),
            'route' => $request->route()?->getName(),
            'path' => '/'.ltrim($request->path(), '/'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function log(Request $request, User $admin, int $storeId, string $action, array $details): void
    {
        AdminAuditLog::query()->create([
            'user_id' => $admin->id,
            'store_id' => $storeId,
            'action' => $action,
            'details' => $details,
            'ip' => $request->ip(),
        ]);
    }
}
