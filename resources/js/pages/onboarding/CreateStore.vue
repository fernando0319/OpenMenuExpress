<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import StoreController from '@/actions/App/Http/Controllers/StoreController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

defineOptions({
    layout: {
        title: 'Crie sua loja',
        description: 'Leva menos de um minuto. Você pode mudar tudo depois.',
    },
});

const props = defineProps<{
    baseDomain: string | null;
}>();

const form = useForm({
    name: '',
    slug: '',
    whatsapp: '',
});

const slugTouched = ref(false);

function slugify(text: string): string {
    return text
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 40);
}

watch(
    () => form.name,
    (name) => {
        if (!slugTouched.value) {
            form.slug = slugify(name);
        }
    },
);

function submit(): void {
    form.post(StoreController.store.url());
}
</script>

<template>
    <Head title="Crie sua loja" />

    <form class="flex flex-col gap-6" @submit.prevent="submit">
        <div class="grid gap-2">
            <Label for="name">Nome da loja</Label>
            <Input
                id="name"
                v-model="form.name"
                required
                autofocus
                placeholder="Ex.: Pizzaria do Zé"
            />
            <InputError :message="form.errors.name" />
        </div>

        <div class="grid gap-2">
            <Label for="slug">Endereço do cardápio</Label>
            <div class="flex items-center gap-2">
                <Input
                    id="slug"
                    v-model="form.slug"
                    required
                    @input="slugTouched = true"
                />
                <span
                    v-if="props.baseDomain"
                    class="text-sm whitespace-nowrap text-muted-foreground"
                    >.{{ props.baseDomain }}</span
                >
            </div>
            <InputError :message="form.errors.slug" />
        </div>

        <div class="grid gap-2">
            <Label for="whatsapp">WhatsApp da loja</Label>
            <Input
                id="whatsapp"
                v-model="form.whatsapp"
                inputmode="tel"
                placeholder="(11) 99999-8888"
            />
            <InputError :message="form.errors.whatsapp" />
        </div>

        <Button type="submit" class="w-full" :disabled="form.processing">
            <Spinner v-if="form.processing" />
            Criar loja
        </Button>
    </form>
</template>
