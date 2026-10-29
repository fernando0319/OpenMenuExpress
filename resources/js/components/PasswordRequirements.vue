<script setup lang="ts">
import { Check, Circle } from '@lucide/vue';
import { computed } from 'vue';

/**
 * What the new password needs, ticked off while it is typed. The rules come from the
 * server (Password::defaults()->toPasswordRulesString()), so they always match the check.
 */
const props = defineProps<{
    rules: string;
    password: string;
    confirmation?: string;
}>();

const items = computed(() => {
    const parts = props.rules
        .split(';')
        .map((part) => part.trim())
        .filter(Boolean);
    const min = Number(
        parts.find((part) => part.startsWith('minlength:'))?.split(':')[1] ?? 0,
    );
    const required = new Set(
        parts
            .filter((part) => part.startsWith('required:'))
            .map((part) => part.split(':')[1].trim()),
    );
    const password = props.password;
    const list: { label: string; ok: boolean }[] = [];

    if (min > 0) {
        list.push({
            label: `Pelo menos ${min} caracteres`,
            ok: password.length >= min,
        });
    }

    if (required.has('upper')) {
        list.push({ label: 'Uma letra maiúscula', ok: /[A-Z]/.test(password) });
    }

    if (required.has('lower')) {
        list.push({ label: 'Uma letra minúscula', ok: /[a-z]/.test(password) });
    }

    if (required.has('digit')) {
        list.push({ label: 'Um número', ok: /\d/.test(password) });
    }

    if (required.has('special')) {
        list.push({
            label: 'Um símbolo (ex.: ! @ # $ %)',
            ok: /[^A-Za-z0-9]/.test(password),
        });
    }

    if (props.confirmation !== undefined) {
        list.push({
            label: 'As duas senhas iguais',
            ok: password !== '' && password === props.confirmation,
        });
    }

    return list;
});

const strict = computed(() => props.rules.includes('required:'));
</script>

<template>
    <div class="flex flex-col gap-1.5 rounded-lg bg-muted/50 p-3 text-xs">
        <p class="font-medium text-foreground">A senha precisa ter:</p>
        <ul class="grid gap-1 sm:grid-cols-2">
            <li
                v-for="item in items"
                :key="item.label"
                class="flex items-center gap-1.5"
                :class="
                    item.ok
                        ? 'text-emerald-600 dark:text-emerald-400'
                        : 'text-muted-foreground'
                "
            >
                <Check v-if="item.ok" class="size-3.5 shrink-0" />
                <Circle v-else class="size-3.5 shrink-0" />
                {{ item.label }}
            </li>
        </ul>
        <p v-if="strict" class="text-muted-foreground">
            Senhas conhecidas de vazamentos na internet são recusadas, mesmo
            seguindo as regras.
        </p>
    </div>
</template>
