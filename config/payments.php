<?php

/*
 * Online payments: the money goes straight to the store's own account at each provider.
 * Each store connects its accounts in the panel; only the platform-level settings live here.
 */
return [
    // How long the customer has to pay before the order is cancelled.
    'expiration_minutes' => (int) env('PAYMENTS_EXPIRATION_MINUTES', 30),

    'mercadopago' => [
        // App of the platform at Mercado Pago (Suas integrações). With it, stores connect by OAuth;
        // without it, each store pastes its own production Access Token.
        'client_id' => env('MERCADOPAGO_CLIENT_ID'),
        'client_secret' => env('MERCADOPAGO_CLIENT_SECRET'),
        // Platform fee taken in each payment (marketplace). 0 = none; the store keeps everything.
        'platform_fee_percent' => (float) env('MERCADOPAGO_PLATFORM_FEE_PERCENT', 0),
        // Mercado Pago asks for a payer e-mail; customers order with a phone number only.
        'payer_email_domain' => env('MERCADOPAGO_PAYER_EMAIL_DOMAIN', 'clientes.example.com'),
        'api_url' => 'https://api.mercadopago.com',
        'auth_url' => 'https://auth.mercadopago.com/authorization',
    ],

    'sicoob' => [
        // API Pix (Bacen standard). The sandbox needs its own credentials from the Sicoob developers portal.
        'token_url' => env('SICOOB_TOKEN_URL', 'https://auth.sicoob.com.br/auth/realms/cooperado/protocol/openid-connect/token'),
        'api_url' => env('SICOOB_PIX_URL', 'https://api.sicoob.com.br/pix/api/v2'),
        'scopes' => 'cob.read cob.write pix.read pix.write webhook.read webhook.write',
    ],

    'btcpay' => [
        'timeout' => 15,
    ],

    // Lightning through the store's own wallet (Nostr Wallet Connect, NIP-47).
    'nwc' => [
        // Seconds to wait for the wallet to answer through the relay.
        'timeout' => (int) env('NWC_TIMEOUT', 10),
    ],

    // Price of bitcoin in reais: the median of the public sources that answer, kept for a minute.
    // A charge keeps the rate it was created with, so the customer pays exactly what was shown.
    'quotes' => [
        'ttl' => (int) env('QUOTES_TTL_SECONDS', 60),
        'sources' => array_values(array_filter(explode(',', (string) env('QUOTES_SOURCES', 'coinbase,binance,mercadobitcoin,coingecko')))),
        'timeout' => 5,
    ],
];
