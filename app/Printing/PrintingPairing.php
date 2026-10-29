<?php

namespace App\Printing;

use App\Models\Store;
use Illuminate\Support\Facades\Cache;

/**
 * Short-lived 6-digit codes that link a computer's print agent to a store.
 */
final class PrintingPairing
{
    public const TTL_MINUTES = 10;

    public function issue(Store $store): string
    {
        do {
            $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        } while (Cache::has($this->key($code)));

        Cache::put($this->key($code), $store->id, now()->addMinutes(self::TTL_MINUTES));

        return $code;
    }

    /**
     * The store the code belongs to; the code stops working after this call.
     */
    public function consume(string $code): ?int
    {
        $storeId = Cache::pull($this->key($code));

        return is_int($storeId) ? $storeId : null;
    }

    private function key(string $code): string
    {
        return "print-pairing:{$code}";
    }
}
