let context: AudioContext | null = null;

function audio(): AudioContext | null {
    try {
        context ??= new AudioContext();

        return context;
    } catch {
        return null;
    }
}

/**
 * Bell-like three-tone chime for orders waiting to be accepted. Generated in the browser, so
 * there is no audio file to ship. Loud enough for a kitchen.
 */
export function playNewOrderChime(): void {
    const ctx = audio();

    if (ctx === null) {
        return;
    }

    [988, 1319, 1568].forEach((frequency, position) => {
        const start = ctx.currentTime + position * 0.16;
        const oscillator = ctx.createOscillator();
        const gain = ctx.createGain();

        oscillator.type = 'triangle';
        oscillator.frequency.value = frequency;
        gain.gain.setValueAtTime(0.0001, start);
        gain.gain.exponentialRampToValueAtTime(0.6, start + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.001, start + 0.6);
        oscillator.connect(gain).connect(ctx.destination);
        oscillator.start(start);
        oscillator.stop(start + 0.6);
    });
}

/**
 * Browsers keep audio blocked until the person interacts with the page.
 */
export function soundBlocked(): boolean {
    return audio()?.state === 'suspended';
}

export async function unlockSound(): Promise<void> {
    await audio()?.resume();
}
