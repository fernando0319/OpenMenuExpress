<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Copy, Plus, Trash2 } from '@lucide/vue';
import HoursController from '@/actions/App/Http/Controllers/StoreSettings/HoursController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/store-settings/hours';

type Shift = { opens: string; closes: string };

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Horários', href: edit() }],
    },
});

const props = defineProps<{
    week: { weekday: number; shifts: Shift[] }[];
    scheduling: {
        accepts_scheduling: boolean;
        scheduling_lead_minutes: number;
        scheduling_days_ahead: number;
        scheduling_slot_minutes: number;
        scheduling_slot_capacity: number | null;
    };
    timezone: string;
}>();

const weekdayNames = [
    'Domingo',
    'Segunda',
    'Terça',
    'Quarta',
    'Quinta',
    'Sexta',
    'Sábado',
];

// Monday first, as merchants read their week.
const displayOrder = [1, 2, 3, 4, 5, 6, 0];

const form = useForm({
    week: props.week.map((day) => ({
        weekday: day.weekday,
        shifts: day.shifts.map((shift) => ({ ...shift })),
    })),
    ...props.scheduling,
});

function day(weekday: number) {
    return form.week.find((entry) => entry.weekday === weekday)!;
}

function addShift(weekday: number): void {
    day(weekday).shifts.push({ opens: '18:00', closes: '23:00' });
}

function removeShift(weekday: number, position: number): void {
    day(weekday).shifts.splice(position, 1);
}

function copyToAll(weekday: number): void {
    const source = day(weekday).shifts;

    form.week.forEach((entry) => {
        entry.shifts = source.map((shift) => ({ ...shift }));
    });
}

function crossesMidnight(shift: Shift): boolean {
    return shift.closes <= shift.opens;
}

function shiftError(weekday: number, position: number): string | undefined {
    const index = form.week
        .flatMap((entry) =>
            entry.shifts.map((_, shiftPosition) => [
                entry.weekday,
                shiftPosition,
            ]),
        )
        .findIndex(([w, p]) => w === weekday && p === position);
    const errors = form.errors as Record<string, string | undefined>;

    return errors[`shifts.${index}.opens`] ?? errors[`shifts.${index}.closes`];
}

function save(): void {
    form.transform((data) => ({
        shifts: data.week.flatMap((entry) =>
            entry.shifts.map((shift) => ({ weekday: entry.weekday, ...shift })),
        ),
        accepts_scheduling: data.accepts_scheduling,
        scheduling_lead_minutes: data.scheduling_lead_minutes,
        scheduling_days_ahead: data.scheduling_days_ahead,
        scheduling_slot_minutes: data.scheduling_slot_minutes,
        scheduling_slot_capacity: data.scheduling_slot_capacity || null,
    })).put(HoursController.update.url(), { preserveScroll: true });
}

function errorFor(key: string): string | undefined {
    return (form.errors as Record<string, string | undefined>)[key];
}
</script>

<template>
    <Head title="Horários" />

    <form class="flex max-w-3xl flex-col gap-10 p-4" @submit.prevent="save">
        <section class="flex flex-col gap-4">
            <Heading
                title="Horários de funcionamento"
                :description="`A loja abre e fecha sozinha nesses horários (fuso ${timezone}). Sem nenhum horário, fica sempre aberta. A pausa manual fica em Entrega e funcionamento.`"
            />

            <ul class="flex flex-col divide-y rounded-xl border">
                <li
                    v-for="weekday in displayOrder"
                    :key="weekday"
                    class="flex flex-col gap-2 p-3 sm:flex-row sm:items-start"
                >
                    <span class="w-24 pt-2 text-sm font-medium">{{
                        weekdayNames[weekday]
                    }}</span>

                    <div class="flex flex-1 flex-col gap-2">
                        <p
                            v-if="day(weekday).shifts.length === 0"
                            class="pt-2 text-sm text-muted-foreground"
                        >
                            Fechado
                        </p>
                        <div
                            v-for="(shift, position) in day(weekday).shifts"
                            :key="position"
                            class="flex flex-wrap items-center gap-2"
                        >
                            <Input
                                v-model="shift.opens"
                                type="time"
                                class="w-28"
                                :aria-label="`${weekdayNames[weekday]}: abre`"
                            />
                            <span class="text-sm text-muted-foreground"
                                >às</span
                            >
                            <Input
                                v-model="shift.closes"
                                type="time"
                                class="w-28"
                                :aria-label="`${weekdayNames[weekday]}: fecha`"
                            />
                            <span
                                v-if="crossesMidnight(shift)"
                                class="text-xs text-muted-foreground"
                                >(termina no dia seguinte)</span
                            >
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                aria-label="Remover turno"
                                @click="removeShift(weekday, position)"
                            >
                                <Trash2 />
                            </Button>
                            <InputError
                                class="w-full"
                                :message="shiftError(weekday, position)"
                            />
                        </div>
                    </div>

                    <div class="flex gap-1">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="addShift(weekday)"
                        >
                            <Plus />
                            Turno
                        </Button>
                        <Button
                            v-if="day(weekday).shifts.length > 0"
                            type="button"
                            variant="ghost"
                            size="sm"
                            title="Copiar estes horários para todos os dias"
                            @click="copyToAll(weekday)"
                        >
                            <Copy />
                            Todos
                        </Button>
                    </div>
                </li>
            </ul>
        </section>

        <section class="flex flex-col gap-4">
            <Heading
                variant="small"
                title="Pedidos agendados"
                description="O cliente escolhe um horário dentro do funcionamento. Com a loja fechada, ainda dá para pedir para depois."
            />

            <div class="flex items-center gap-2">
                <Checkbox
                    id="accepts_scheduling"
                    v-model="form.accepts_scheduling"
                />
                <Label for="accepts_scheduling"
                    >Aceitar pedidos agendados</Label
                >
            </div>

            <div
                v-if="form.accepts_scheduling"
                class="grid gap-4 sm:grid-cols-2"
            >
                <div class="grid gap-2">
                    <Label for="lead">Antecedência mínima (minutos)</Label>
                    <Input
                        id="lead"
                        v-model.number="form.scheduling_lead_minutes"
                        type="number"
                        min="0"
                    />
                    <InputError
                        :message="errorFor('scheduling_lead_minutes')"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="days">Até quantos dias à frente</Label>
                    <Input
                        id="days"
                        v-model.number="form.scheduling_days_ahead"
                        type="number"
                        min="0"
                        max="14"
                    />
                    <p class="text-xs text-muted-foreground">
                        0 = só no mesmo dia.
                    </p>
                    <InputError :message="errorFor('scheduling_days_ahead')" />
                </div>
                <div class="grid gap-2">
                    <Label for="slot">Intervalo entre horários</Label>
                    <NativeSelect
                        id="slot"
                        v-model.number="form.scheduling_slot_minutes"
                    >
                        <option :value="15">15 minutos</option>
                        <option :value="20">20 minutos</option>
                        <option :value="30">30 minutos</option>
                        <option :value="45">45 minutos</option>
                        <option :value="60">1 hora</option>
                    </NativeSelect>
                </div>
                <div class="grid gap-2">
                    <Label for="capacity">Pedidos por horário</Label>
                    <Input
                        id="capacity"
                        v-model.number="form.scheduling_slot_capacity"
                        type="number"
                        min="1"
                        placeholder="Sem limite"
                    />
                    <InputError
                        :message="errorFor('scheduling_slot_capacity')"
                    />
                </div>
            </div>
        </section>

        <div>
            <Button type="submit" :disabled="form.processing">Salvar</Button>
        </div>
    </form>
</template>
