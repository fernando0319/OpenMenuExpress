<?php

namespace App\Services\Quotes;

use App\Payments\PaymentException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The price of one bitcoin in reais, as the median of several public sources asked at once.
 * One source down or off does not move the price; none answering stops the charge (better
 * than charging a wrong amount).
 */
final class BtcQuote
{
    /**
     * Where each source publishes the BTC/BRL price, and how to read it.
     *
     * @var array<string, array{url: string, read: \Closure(array<mixed>): mixed}>
     */
    private array $sources;

    public function __construct()
    {
        $this->sources = [
            'coinbase' => [
                'url' => 'https://api.coinbase.com/v2/prices/BTC-BRL/spot',
                'read' => fn (array $data) => $data['data']['amount'] ?? null,
            ],
            'binance' => [
                'url' => 'https://api.binance.com/api/v3/ticker/price?symbol=BTCBRL',
                'read' => fn (array $data) => $data['price'] ?? null,
            ],
            'mercadobitcoin' => [
                'url' => 'https://api.mercadobitcoin.net/api/v4/tickers?symbols=BTC-BRL',
                'read' => fn (array $data) => $data[0]['last'] ?? null,
            ],
            'coingecko' => [
                'url' => 'https://api.coingecko.com/api/v3/simple/price?ids=bitcoin&vs_currencies=brl',
                'read' => fn (array $data) => $data['bitcoin']['brl'] ?? null,
            ],
        ];
    }

    /**
     * Centavos per bitcoin (R$ 500.000,00 → 50000000).
     *
     * @throws PaymentException
     */
    public function centsPerBtc(): int
    {
        $cached = Cache::get('quotes:btc-brl');

        if (is_int($cached)) {
            return $cached;
        }

        $prices = $this->fetch();

        if ($prices === []) {
            throw new PaymentException('Não foi possível consultar a cotação do bitcoin agora.');
        }

        sort($prices);
        $middle = intdiv(count($prices), 2);
        $median = count($prices) % 2 === 1 ? $prices[$middle] : intdiv($prices[$middle - 1] + $prices[$middle], 2);

        Cache::put('quotes:btc-brl', $median, max(1, (int) config('payments.quotes.ttl')));

        return $median;
    }

    /**
     * Satoshis to charge for an amount in reais, rounded up so the store never gets less.
     */
    public static function satsFor(int $brlCents, int $centsPerBtc): int
    {
        return intdiv($brlCents * 100_000_000 + $centsPerBtc - 1, $centsPerBtc);
    }

    /**
     * @return list<int> centavos per bitcoin from each source that answered sensibly
     */
    private function fetch(): array
    {
        $names = array_values(array_intersect((array) config('payments.quotes.sources'), array_keys($this->sources)));
        $timeout = (int) config('payments.quotes.timeout');

        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn (string $name) => $pool->as($name)->acceptJson()->timeout($timeout)->get($this->sources[$name]['url']),
            $names,
        ));

        $prices = [];

        foreach ($names as $name) {
            $response = $responses[$name] ?? null;
            $data = $response instanceof Response && $response->successful() ? $response->json() : null;
            $value = is_array($data) ? ($this->sources[$name]['read'])($data) : null;
            $cents = is_numeric($value) ? (int) round((float) $value * 100) : 0;

            // A bitcoin under R$ 1.000 or over R$ 100 milhões is a broken answer, not a price.
            if ($cents >= 100_000 && $cents <= 10_000_000_000) {
                $prices[] = $cents;
            } else {
                Log::notice('Quote source ignored', ['source' => $name]);
            }
        }

        return $prices;
    }
}
