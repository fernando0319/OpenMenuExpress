<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import CourierAppController from '@/actions/App/Http/Controllers/Courier/CourierAppController';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

const props = defineProps<{
    courier: {
        name: string;
        email: string | null;
        cpf: string;
        statusLabel: string;
        phone: string;
        city: string;
        state: string;
        vehicleValue: string;
        vehiclePlate: string | null;
        pixKeyType: string;
        pixKey: string;
    };
    vehicles: Option[];
    pixKeyTypes: Option[];
    states: string[];
}>();

const form = useForm({
    phone: props.courier.phone.replace(/^55/, ''),
    city: props.courier.city,
    state: props.courier.state,
    vehicle: props.courier.vehicleValue,
    vehicle_plate: props.courier.vehiclePlate ?? '',
    pix_key_type: props.courier.pixKeyType,
    pix_key: props.courier.pixKey.replace(/^\+55/, ''),
});

const needsPlate = computed(() => form.vehicle !== 'bicycle');

const cpf = computed(() =>
    props.courier.cpf.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4'),
);

function save(): void {
    form.put(CourierAppController.updateProfile.url(), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Perfil" />

    <section class="flex flex-col gap-1 rounded-2xl border bg-background p-5">
        <div class="flex items-center justify-between gap-2">
            <p class="text-lg font-semibold">{{ courier.name }}</p>
            <Badge variant="secondary">{{ courier.statusLabel }}</Badge>
        </div>
        <p class="text-sm text-muted-foreground">{{ courier.email }}</p>
        <p class="text-sm text-muted-foreground">CPF {{ cpf }}</p>
    </section>

    <form
        class="flex flex-col gap-5 rounded-2xl border bg-background p-5"
        @submit.prevent="save"
    >
        <div class="grid gap-2">
            <Label for="phone">Celular (WhatsApp)</Label>
            <Input id="phone" v-model="form.phone" type="tel" inputmode="tel" />
            <InputError :message="form.errors.phone" />
        </div>
        <div class="grid grid-cols-[1fr_6rem] gap-3">
            <div class="grid gap-2">
                <Label for="city">Cidade</Label>
                <Input id="city" v-model="form.city" />
            </div>
            <div class="grid gap-2">
                <Label for="state">UF</Label>
                <NativeSelect id="state" v-model="form.state">
                    <option v-for="uf in states" :key="uf" :value="uf">
                        {{ uf }}
                    </option>
                </NativeSelect>
            </div>
        </div>
        <InputError :message="form.errors.city ?? form.errors.state" />

        <div class="grid gap-2">
            <Label for="vehicle">Veículo</Label>
            <NativeSelect id="vehicle" v-model="form.vehicle">
                <option
                    v-for="vehicle in vehicles"
                    :key="vehicle.value"
                    :value="vehicle.value"
                >
                    {{ vehicle.label }}
                </option>
            </NativeSelect>
        </div>
        <div v-if="needsPlate" class="grid gap-2">
            <Label for="vehicle_plate">Placa</Label>
            <Input
                id="vehicle_plate"
                v-model="form.vehicle_plate"
                class="uppercase"
            />
            <InputError :message="form.errors.vehicle_plate" />
        </div>

        <div class="grid gap-2">
            <Label>Chave PIX para os repasses</Label>
            <div class="grid grid-cols-[8rem_1fr] gap-3">
                <NativeSelect
                    v-model="form.pix_key_type"
                    aria-label="Tipo da chave"
                >
                    <option
                        v-for="type in pixKeyTypes"
                        :key="type.value"
                        :value="type.value"
                    >
                        {{ type.label }}
                    </option>
                </NativeSelect>
                <Input v-model="form.pix_key" aria-label="Chave PIX" />
            </div>
            <p class="text-xs text-muted-foreground">
                Vale a partir do próximo repasse fechado.
            </p>
            <InputError :message="form.errors.pix_key" />
        </div>

        <Button type="submit" :disabled="form.processing">Salvar</Button>
    </form>
</template>
