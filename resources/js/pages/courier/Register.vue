<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { BadgeCheck, Bike, CalendarClock, HandCoins } from '@lucide/vue';
import { computed } from 'vue';
import CourierRegistrationController from '@/actions/App/Http/Controllers/Courier/CourierRegistrationController';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import PasswordRequirements from '@/components/PasswordRequirements.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { login } from '@/routes';

type Option = { value: string; label: string };

const props = defineProps<{
    signedIn: { name: string; email: string | null } | null;
    platformFeePercent: number;
    passwordRules: string;
    vehicles: Option[];
    pixKeyTypes: Option[];
    states: string[];
}>();

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    cpf: '',
    phone: '',
    city: '',
    state: '',
    vehicle: 'motorcycle',
    vehicle_plate: '',
    pix_key_type: 'cpf',
    pix_key: '',
    document: null as File | null,
    terms: false,
});

const needsPlate = computed(() => form.vehicle !== 'bicycle');

const pixPlaceholder = computed(
    () =>
        ({
            cpf: '000.000.000-00',
            phone: '(00) 00000-0000',
            email: 'voce@email.com',
            random: '123e4567-e89b-12d3-a456-426614174000',
        })[form.pix_key_type] ?? '',
);

function pickDocument(event: Event): void {
    form.document = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function submit(): void {
    form.post(CourierRegistrationController.store.url(), {
        forceFormData: true,
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}

const fee = computed(() =>
    props.platformFeePercent.toLocaleString('pt-BR', {
        maximumFractionDigits: 2,
    }),
);
</script>

<template>
    <Head title="Seja entregador" />

    <section
        class="flex flex-col gap-4 rounded-2xl bg-gradient-to-br from-[#7b5cff] via-[#f45ff0] to-[#ff7a3d] p-5 text-white"
    >
        <h1 class="text-2xl font-bold">Entregue com o OpenMenuExpress</h1>
        <p class="text-sm text-white/90">
            Receba corridas das lojas da sua cidade direto no celular. Você
            escolhe quando fica online e quais corridas aceita.
        </p>
        <ul class="flex flex-col gap-2 text-sm">
            <li class="flex gap-2">
                <Bike class="size-5 shrink-0" /> A loja define o valor da
                corrida e você vê quanto recebe antes de aceitar.
            </li>
            <li class="flex gap-2">
                <CalendarClock class="size-5 shrink-0" /> Repasse toda semana
                por PIX, já descontada a taxa da plataforma ({{ fee }}%).
            </li>
            <li class="flex gap-2">
                <HandCoins class="size-5 shrink-0" /> Pedido pago na entrega:
                você cobra o cliente e acerta o valor com a loja.
            </li>
            <li class="flex gap-2">
                <BadgeCheck class="size-5 shrink-0" /> Cadastro aprovado pela
                equipe do OpenMenuExpress, para a segurança de todos.
            </li>
        </ul>
    </section>

    <form
        class="flex flex-col gap-6 rounded-2xl border bg-background p-5"
        @submit.prevent="submit"
    >
        <fieldset v-if="!signedIn" class="flex flex-col gap-4">
            <legend class="mb-2 font-semibold">Sua conta</legend>
            <div class="grid gap-2">
                <Label for="name">Nome completo</Label>
                <Input
                    id="name"
                    v-model="form.name"
                    autocomplete="name"
                    required
                />
                <InputError :message="form.errors.name" />
            </div>
            <div class="grid gap-2">
                <Label for="email">E-mail</Label>
                <Input
                    id="email"
                    v-model="form.email"
                    type="email"
                    autocomplete="email"
                    required
                />
                <InputError :message="form.errors.email" />
            </div>
            <div class="grid gap-2">
                <Label for="password">Senha</Label>
                <PasswordInput
                    id="password"
                    v-model="form.password"
                    autocomplete="new-password"
                    :passwordrules="passwordRules"
                    required
                />
                <InputError :message="form.errors.password" />
            </div>
            <div class="grid gap-2">
                <Label for="password_confirmation">Repita a senha</Label>
                <PasswordInput
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    autocomplete="new-password"
                    :passwordrules="passwordRules"
                    required
                />
                <InputError :message="form.errors.password_confirmation" />
            </div>
            <PasswordRequirements
                :rules="passwordRules"
                :password="form.password"
                :confirmation="form.password_confirmation"
            />
            <p class="text-xs text-muted-foreground">
                Já tem conta?
                <Link :href="login()" class="underline">Entre</Link> e volte a
                esta página.
            </p>
        </fieldset>
        <p v-else class="text-sm">
            Cadastrando como <strong>{{ signedIn.name }}</strong>
            <template v-if="signedIn.email"> ({{ signedIn.email }})</template>.
        </p>

        <fieldset class="flex flex-col gap-4">
            <legend class="mb-2 font-semibold">Seus dados</legend>
            <div class="grid gap-2">
                <Label for="cpf">CPF</Label>
                <Input
                    id="cpf"
                    v-model="form.cpf"
                    inputmode="numeric"
                    placeholder="000.000.000-00"
                    required
                />
                <InputError :message="form.errors.cpf" />
            </div>
            <div class="grid gap-2">
                <Label for="phone">Celular (WhatsApp)</Label>
                <Input
                    id="phone"
                    v-model="form.phone"
                    type="tel"
                    inputmode="tel"
                    placeholder="(00) 00000-0000"
                    required
                />
                <InputError :message="form.errors.phone" />
            </div>
            <div class="grid grid-cols-[1fr_6rem] gap-3">
                <div class="grid gap-2">
                    <Label for="city">Cidade</Label>
                    <Input id="city" v-model="form.city" required />
                </div>
                <div class="grid gap-2">
                    <Label for="state">UF</Label>
                    <NativeSelect id="state" v-model="form.state" required>
                        <option value="" disabled>--</option>
                        <option v-for="uf in states" :key="uf" :value="uf">
                            {{ uf }}
                        </option>
                    </NativeSelect>
                </div>
            </div>
            <InputError :message="form.errors.city ?? form.errors.state" />
        </fieldset>

        <fieldset class="flex flex-col gap-4">
            <legend class="mb-2 font-semibold">Veículo</legend>
            <div class="grid grid-cols-3 gap-2">
                <label
                    v-for="vehicle in vehicles"
                    :key="vehicle.value"
                    class="flex cursor-pointer items-center justify-center rounded-xl border p-3 text-sm font-medium"
                    :class="
                        form.vehicle === vehicle.value
                            ? 'border-primary bg-primary/10 text-primary'
                            : ''
                    "
                >
                    <input
                        v-model="form.vehicle"
                        type="radio"
                        name="vehicle"
                        :value="vehicle.value"
                        class="sr-only"
                    />
                    {{ vehicle.label }}
                </label>
            </div>
            <div v-if="needsPlate" class="grid gap-2">
                <Label for="vehicle_plate">Placa</Label>
                <Input
                    id="vehicle_plate"
                    v-model="form.vehicle_plate"
                    class="uppercase"
                    placeholder="ABC1D23"
                />
                <InputError :message="form.errors.vehicle_plate" />
            </div>
        </fieldset>

        <fieldset class="flex flex-col gap-4">
            <legend class="mb-2 font-semibold">
                Chave PIX para os repasses
            </legend>
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
                <Input
                    v-model="form.pix_key"
                    aria-label="Chave PIX"
                    :placeholder="pixPlaceholder"
                    required
                />
            </div>
            <p class="text-xs text-muted-foreground">
                A conta precisa estar no seu nome (mesmo CPF do cadastro).
            </p>
            <InputError :message="form.errors.pix_key" />
        </fieldset>

        <fieldset class="flex flex-col gap-2">
            <legend class="mb-2 font-semibold">Documento</legend>
            <Label for="document">Foto da CNH (ou RG, para bicicleta)</Label>
            <Input
                id="document"
                type="file"
                accept="image/*,application/pdf"
                capture="environment"
                @change="pickDocument"
            />
            <p class="text-xs text-muted-foreground">
                Só a equipe do OpenMenuExpress vê este arquivo.
            </p>
            <InputError :message="form.errors.document" />
        </fieldset>

        <div class="flex items-start gap-2">
            <Checkbox id="terms" v-model="form.terms" />
            <Label for="terms" class="text-sm leading-snug font-normal"
                >Li e concordo: sou responsável pelo meu veículo e pela entrega,
                trato clientes e lojas com respeito, e autorizo o uso da minha
                localização enquanto estiver online.</Label
            >
        </div>
        <InputError :message="form.errors.terms" />

        <Button type="submit" size="lg" :disabled="form.processing"
            >Enviar cadastro</Button
        >
    </form>
</template>
