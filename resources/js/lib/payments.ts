import { parseMoney } from '@/lib/money';

export type PaymentLine = {
    method: string;
    amount: string;
};

export type PaymentTally = {
    receivedCents: number;
    /** Still missing to reach the total. */
    remainingCents: number;
    changeCents: number;
    /** Change can only come out of cash. */
    changeWithoutCash: boolean;
    hasInvalid: boolean;
};

/**
 * Preview of what the server's Settlement will decide for these lines.
 */
export function tallyPayments(
    lines: PaymentLine[],
    totalCents: number,
): PaymentTally {
    let received = 0;
    let cash = 0;
    let hasInvalid = false;

    for (const line of lines) {
        const cents = parseMoney(line.amount);

        if (cents === null || cents <= 0) {
            hasInvalid = true;
            continue;
        }

        received += cents;

        if (line.method === 'cash') {
            cash += cents;
        }
    }

    const change = Math.max(0, received - totalCents);

    return {
        receivedCents: received,
        remainingCents: Math.max(0, totalCents - received),
        changeCents: change,
        changeWithoutCash: change > cash,
        hasInvalid,
    };
}

export function paymentsReady(tally: PaymentTally): boolean {
    return (
        !tally.hasInvalid &&
        tally.remainingCents === 0 &&
        !tally.changeWithoutCash
    );
}
