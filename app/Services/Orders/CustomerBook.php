<?php

namespace App\Services\Orders;

use App\Models\Customer;
use App\Models\Store;

/**
 * Customers are identified by WhatsApp number inside each store; no account needed.
 *
 * @phpstan-type AddressInput array{zip?: string|null, street?: string|null, number?: string|null, district?: string|null, complement?: string|null, reference?: string|null}
 */
final class CustomerBook
{
    /**
     * @param  AddressInput|null  $address  Saved to the customer's address book when it has a street.
     */
    public function remember(Store $store, string $name, string $phone, ?array $address = null): Customer
    {
        $customer = $store->customers()->firstOrNew(['phone' => $phone]);
        $customer->name = $name;
        $customer->save();

        if ($address !== null && ($address['street'] ?? null) !== null) {
            $customer->addresses()->firstOrCreate(
                [
                    'street' => $address['street'],
                    'number' => $address['number'] ?? null,
                    'complement' => $address['complement'] ?? null,
                ],
                [
                    'zip' => $address['zip'] ?? null,
                    'district' => $address['district'] ?? null,
                    'reference' => $address['reference'] ?? null,
                ],
            );
        }

        return $customer;
    }
}
