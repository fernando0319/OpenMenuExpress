<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { MapPin } from '@lucide/vue';
import { ref } from 'vue';
import ContactController from '@/actions/App/Http/Controllers/StoreSettings/ContactController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/store-settings/contact';
import { edit as courierNetworkEdit } from '@/routes/store-settings/courier-network';

const props = defineProps<{
    store: {
        whatsapp: string;
        email: string;
        address_zip: string;
        address_street: string;
        address_number: string;
        address_complement: string;
        address_district: string;
        address_city: string;
        address_state: string;
    };
    hasLocation: boolean;
    states: string[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dados e endereço', href: edit() }],
    },
});

const form = useForm({ ...props.store });

function save(): void {
    form.put(ContactController.update.url(), { preserveScroll: true });
}

// Fills street, district, city and state from the CEP (ViaCEP, public service of the Correios data).
const lookingUp = ref(false);
const zipError = ref<string | null>(null);

async function lookupZip(): Promise<void> {
    const zip = form.address_zip.replace(/\D/g, '');

    if (zip.length !== 8) {
        return;
    }

    lookingUp.value = true;
    zipError.value = null;

    try {
        const response = await fetch(`https://viacep.com.br/ws/${zip}/json/`);
        const data = (await response.json()) as {
            erro?: boolean;
            logradouro?: string;
            bairro?: string;
            localidade?: string;
            uf?: string;
        };

        if (data.erro) {
            zipError.value = 'CEP não encontrado. Preencha o endereço à mão.';

            return;
        }

        form.address_street = data.logradouro || form.address_street;
        form.address_district = data.bairro || form.address_district;
        form.address_city = data.localidade || form.address_city;
        form.address_state = data.uf || form.address_state;
    } catch {
        zipError.value =
            'Não foi possível buscar o CEP. Preencha o endereço à mão.';
    } finally {
        lookingUp.value = false;
    }
}
</script>

<template>
    <Head title="Dados e endereço" />

    <form class="flex max-w-3xl flex-col gap-8 p-4" @submit.prevent="save">
        <Heading
            title="Dados e endereço"
            description="Como os clientes falam com a loja e onde ela fica. Aparece no cardápio e orienta os entregadores. O nome e a descrição ficam em Aparência."
        />

        <section class="grid gap-4 sm:grid-cols-2">
            <div class="grid content-start gap-2">
                <Label for="whatsapp">WhatsApp da loja</Label>
                <Input
                    id="whatsapp"
                    v-model="form.whatsapp"
                    type="tel"
                    inputmode="tel"
                    placeholder="(00) 00000-0000"
                />
                <p class="text-xs text-muted-foreground">
                    Botão "Falar com a loja" no cardápio.
                </p>
                <InputError :message="form.errors.whatsapp" />
            </div>
            <div class="grid content-start gap-2">
                <Label for="email">E-mail</Label>
                <Input id="email" v-model="form.email" type="email" />
                <InputError :message="form.errors.email" />
            </div>
        </section>

        <section class="flex flex-col gap-4">
            <Heading variant="small" title="Endereço" />
            <div class="grid gap-4 sm:grid-cols-[10rem_1fr_7rem]">
                <div class="grid content-start gap-2">
                    <Label for="address_zip">CEP</Label>
                    <Input
                        id="address_zip"
                        v-model="form.address_zip"
                        inputmode="numeric"
                        placeholder="00000-000"
                        @blur="lookupZip"
                    />
                    <p v-if="lookingUp" class="text-xs text-muted-foreground">
                        Buscando…
                    </p>
                    <InputError
                        :message="zipError ?? form.errors.address_zip"
                    />
                </div>
                <div class="grid content-start gap-2">
                    <Label for="address_street">Rua</Label>
                    <Input id="address_street" v-model="form.address_street" />
                    <InputError :message="form.errors.address_street" />
                </div>
                <div class="grid content-start gap-2">
                    <Label for="address_number">Número</Label>
                    <Input id="address_number" v-model="form.address_number" />
                    <InputError :message="form.errors.address_number" />
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid content-start gap-2">
                    <Label for="address_complement">Complemento</Label>
                    <Input
                        id="address_complement"
                        v-model="form.address_complement"
                        placeholder="Sala, loja, ponto de referência"
                    />
                </div>
                <div class="grid content-start gap-2">
                    <Label for="address_district">Bairro</Label>
                    <Input
                        id="address_district"
                        v-model="form.address_district"
                    />
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-[1fr_7rem]">
                <div class="grid content-start gap-2">
                    <Label for="address_city">Cidade</Label>
                    <Input id="address_city" v-model="form.address_city" />
                </div>
                <div class="grid content-start gap-2">
                    <Label for="address_state">UF</Label>
                    <NativeSelect
                        id="address_state"
                        v-model="form.address_state"
                    >
                        <option value="">--</option>
                        <option v-for="uf in states" :key="uf" :value="uf">
                            {{ uf }}
                        </option>
                    </NativeSelect>
                    <InputError :message="form.errors.address_state" />
                </div>
            </div>
            <p
                class="flex items-start gap-2 rounded-xl border p-3 text-xs text-muted-foreground"
            >
                <MapPin class="size-4 shrink-0" />
                <span>
                    {{
                        hasLocation
                            ? 'Mudou de endereço? Confira também o ponto no mapa em'
                            : 'Para chamar entregadores da rede, marque também o ponto no mapa em'
                    }}
                    <Link
                        :href="courierNetworkEdit()"
                        class="font-medium text-primary underline-offset-4 hover:underline"
                        >Rede de entregadores</Link
                    >.
                </span>
            </p>
        </section>

        <div>
            <Button type="submit" :disabled="form.processing">Salvar</Button>
        </div>
    </form>
</template>
