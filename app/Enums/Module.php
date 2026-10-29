<?php

namespace App\Enums;

/**
 * Features a plan can include. The online menu, orders, catalog and settings are always included.
 */
enum Module: string
{
    case Pos = 'pos';
    case Tables = 'tables';
    case Couriers = 'couriers';
    case Reports = 'reports';
    case WhatsApp = 'whatsapp';
    case Marketing = 'marketing';
    case Finance = 'finance';
    case OnlinePayments = 'online_payments';

    public function label(): string
    {
        return match ($this) {
            self::Pos => 'PDV e caixa',
            self::Tables => 'Mesas e app do garçom',
            self::Couriers => 'Entregadores e rastreio',
            self::Reports => 'Relatórios',
            self::WhatsApp => 'Mensagens no WhatsApp',
            self::Marketing => 'Cupons e banners',
            self::Finance => 'Financeiro',
            self::OnlinePayments => 'Pagamento online',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Pos => 'Vendas no balcão, pagamento dividido, abertura e fechamento de caixa.',
            self::Tables => 'Comandas por mesa, pedidos pelo celular do garçom e fechamento de conta.',
            self::Couriers => 'Entregadores na equipe, mapa ao vivo e acompanhamento pelo cliente.',
            self::Reports => 'Faturamento, horários de pico, mais vendidos e exportação para Excel.',
            self::WhatsApp => 'Avisos automáticos ao cliente a cada mudança no pedido.',
            self::Marketing => 'Cupons de desconto e banners no cardápio.',
            self::Finance => 'Fornecedores, contas a pagar e a receber, parcelas com baixa e fluxo do mês.',
            self::OnlinePayments => 'Pix, cartão e Bitcoin pagos no cardápio, direto na conta da loja.',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
