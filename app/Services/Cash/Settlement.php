<?php

namespace App\Services\Cash;

use App\Enums\PaymentMethod;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * What the customer handed over versus what the order costs.
 * Only cash can exceed the total; the excess is the change.
 */
final readonly class Settlement
{
    /**
     * @param  list<Payment>  $payments  Net amounts per method, adding up to the order total.
     */
    private function __construct(
        public array $payments,
        public int $changeCents,
        public ?int $cashTenderedCents,
    ) {}

    /**
     * @param  list<Payment>  $tendered
     */
    public static function of(int $totalCents, array $tendered): self
    {
        $byMethod = [];

        foreach ($tendered as $payment) {
            if ($payment->amountCents <= 0) {
                self::fail('Informe um valor maior que zero em cada forma de pagamento.');
            }

            $byMethod[$payment->method->value] = ($byMethod[$payment->method->value] ?? 0) + $payment->amountCents;
        }

        $sum = array_sum($byMethod);

        if ($sum < $totalCents) {
            self::fail('Faltam R$ '.Money::toInput($totalCents - $sum).' para fechar o pagamento.');
        }

        $cash = $byMethod[PaymentMethod::Cash->value] ?? 0;
        $change = $sum - $totalCents;

        if ($change > $cash) {
            self::fail('Só o pagamento em dinheiro pode ter troco. Ajuste os valores em cartão ou PIX.');
        }

        if ($cash > 0) {
            $byMethod[PaymentMethod::Cash->value] = $cash - $change;
        }

        $payments = [];

        foreach ($byMethod as $method => $amount) {
            if ($amount > 0) {
                $payments[] = new Payment(PaymentMethod::from($method), $amount);
            }
        }

        return new self($payments, $change, $cash > 0 ? $cash : null);
    }

    /**
     * @return never
     */
    private static function fail(string $message): void
    {
        throw ValidationException::withMessages(['payments' => $message]);
    }
}
