<?php

namespace App\Enums;

enum FinanceType: string
{
    case Payable = 'payable';
    case Receivable = 'receivable';

    public function label(): string
    {
        return match ($this) {
            self::Payable => 'Conta a pagar',
            self::Receivable => 'Conta a receber',
        };
    }

    public function plural(): string
    {
        return match ($this) {
            self::Payable => 'Contas a pagar',
            self::Receivable => 'Contas a receber',
        };
    }

    /**
     * Default categories created on the store's first visit to the module.
     *
     * @return list<string>
     */
    public function defaultCategories(): array
    {
        return match ($this) {
            self::Payable => [
                'Fornecedores e insumos', 'Aluguel', 'Energia', 'Água', 'Gás', 'Internet e telefone',
                'Salários e encargos', 'Impostos e taxas', 'Embalagens', 'Manutenção', 'Marketing',
                'Taxas de cartão e apps', 'Outras despesas',
            ],
            self::Receivable => ['Vendas a prazo', 'Convênios e empresas', 'Eventos e encomendas', 'Outras receitas'],
        };
    }
}
