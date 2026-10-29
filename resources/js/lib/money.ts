const brl = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});

export function formatCents(cents: number): string {
    return brl.format(cents / 100);
}

/**
 * Parses an amount typed by a person ("12,50", "1.234,56", "R$ 9", "12.5") into cents,
 * like App\Support\Money::parseToCents. Returns null when it is not a valid amount.
 */
export function parseMoney(text: string): number | null {
    let value = text.replace(/R\$|\s| /g, '');

    if (value.includes(',')) {
        value = value.replace(/\./g, '').replace(',', '.');
    }

    if (!/^\d{1,9}(\.\d{1,2})?$/.test(value)) {
        return null;
    }

    const [reais, centavos = '0'] = value.split('.');

    return Number(reais) * 100 + Number(centavos.padEnd(2, '0'));
}

/** Cents as an input value: 1250 → "12,50". */
export function centsToInput(cents: number): string {
    return (cents / 100).toFixed(2).replace('.', ',');
}
