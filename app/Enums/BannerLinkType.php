<?php

namespace App\Enums;

enum BannerLinkType: string
{
    case None = 'none';
    case Product = 'product';
    case Category = 'category';
    case Coupon = 'coupon';
    case Url = 'url';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Só imagem',
            self::Product => 'Abrir um produto',
            self::Category => 'Ir para uma categoria',
            self::Coupon => 'Aplicar um cupom',
            self::Url => 'Abrir um link',
        };
    }
}
