<?php

namespace App\Messaging\Evolution;

enum ConnectionState: string
{
    case Open = 'open';
    case Connecting = 'connecting';
    case Close = 'close';
    case NotFound = 'not_found';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Conectado',
            self::Connecting => 'Aguardando leitura do QR Code',
            self::Close, self::NotFound => 'Desconectado',
        };
    }
}
