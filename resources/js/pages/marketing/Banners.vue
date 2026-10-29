<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Pencil, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import BannerController from '@/actions/App/Http/Controllers/Marketing/BannerController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/marketing/banners';
import type { SelectOption } from '@/types';

type BannerRow = {
    id: number;
    title: string;
    imageUrl: string;
    linkType: string;
    linkTarget: string | null;
    linkLabel: string;
    startsAt: string;
    endsAt: string;
    isActive: boolean;
    isShowing: boolean;
};

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Banners', href: index() }],
    },
});

const props = defineProps<{
    banners: BannerRow[];
    linkTypes: { value: string; label: string }[];
    products: SelectOption[];
    categories: SelectOption[];
    coupons: string[];
}>();

const dialogOpen = ref(false);
const editing = ref<BannerRow | null>(null);
const preview = ref<string | null>(null);

const form = useForm({
    title: '',
    image: null as File | null,
    link_type: 'none',
    link_target: '',
    starts_at: '',
    ends_at: '',
    is_active: true,
});

const targetOptions = computed(() => {
    switch (form.link_type) {
        case 'product':
            return props.products.map((product) => ({
                value: String(product.id),
                label: product.name,
            }));
        case 'category':
            return props.categories.map((category) => ({
                value: String(category.id),
                label: category.name,
            }));
        case 'coupon':
            return props.coupons.map((code) => ({ value: code, label: code }));
        default:
            return [];
    }
});

function open(banner: BannerRow | null): void {
    editing.value = banner;
    form.reset();
    form.clearErrors();
    form.title = banner?.title ?? '';
    form.link_type = banner?.linkType ?? 'none';
    form.link_target = banner?.linkTarget ?? '';
    form.starts_at = banner?.startsAt ?? '';
    form.ends_at = banner?.endsAt ?? '';
    form.is_active = banner?.isActive ?? true;
    preview.value = banner?.imageUrl ?? null;
    dialogOpen.value = true;
}

function pickImage(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    form.image = file;
    preview.value = file
        ? URL.createObjectURL(file)
        : (editing.value?.imageUrl ?? null);
}

function save(): void {
    const options = {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            dialogOpen.value = false;
        },
    };

    if (editing.value) {
        // Files only travel in multipart POST; Laravel reads _method as PUT.
        form.transform((data) => ({ ...data, _method: 'put' })).post(
            BannerController.update.url(editing.value.id),
            options,
        );
    } else {
        form.transform((data) => data).post(
            BannerController.store.url(),
            options,
        );
    }
}

function move(banner: BannerRow, direction: 'up' | 'down'): void {
    router.patch(
        BannerController.move.url(banner.id),
        { direction },
        { preserveScroll: true },
    );
}

function destroy(banner: BannerRow): void {
    if (!confirm(`Excluir o banner "${banner.title}"?`)) {
        return;
    }

    router.delete(BannerController.destroy.url(banner.id), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Banners" />

    <div class="flex max-w-4xl flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="Banners"
                description="Aparecem no topo do cardápio, na ordem abaixo. Tamanho sugerido: 1200 × 500 px."
            />
            <Button @click="open(null)">
                <Plus />
                Novo banner
            </Button>
        </div>

        <p
            v-if="banners.length === 0"
            class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground"
        >
            Nenhum banner ainda.
        </p>

        <ul class="flex flex-col gap-3">
            <li
                v-for="(banner, position) in banners"
                :key="banner.id"
                class="flex flex-col gap-3 rounded-xl border p-3 sm:flex-row sm:items-center"
            >
                <img
                    :src="banner.imageUrl"
                    :alt="banner.title"
                    class="aspect-[12/5] w-full rounded-lg object-cover sm:w-48"
                />
                <div class="flex-1">
                    <p class="font-medium">{{ banner.title }}</p>
                    <p class="text-sm text-muted-foreground">
                        {{ banner.linkLabel }}
                    </p>
                    <Badge
                        class="mt-1"
                        :variant="banner.isShowing ? 'default' : 'secondary'"
                    >
                        {{ banner.isShowing ? 'No ar' : 'Fora do ar' }}
                    </Badge>
                </div>
                <div class="flex gap-1">
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Subir"
                        :disabled="position === 0"
                        @click="move(banner, 'up')"
                    >
                        <ArrowUp />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Descer"
                        :disabled="position === banners.length - 1"
                        @click="move(banner, 'down')"
                    >
                        <ArrowDown />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        :aria-label="`Editar ${banner.title}`"
                        @click="open(banner)"
                    >
                        <Pencil />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        :aria-label="`Excluir ${banner.title}`"
                        @click="destroy(banner)"
                    >
                        <Trash2 />
                    </Button>
                </div>
            </li>
        </ul>
    </div>

    <Dialog v-model:open="dialogOpen">
        <DialogContent class="sm:max-w-lg">
            <form class="space-y-5" @submit.prevent="save">
                <DialogHeader>
                    <DialogTitle>{{
                        editing ? 'Editar banner' : 'Novo banner'
                    }}</DialogTitle>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="banner-image">Imagem</Label>
                    <img
                        v-if="preview"
                        :src="preview"
                        alt="Prévia do banner"
                        class="aspect-[12/5] w-full rounded-lg border object-cover"
                    />
                    <input
                        id="banner-image"
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        class="text-sm"
                        @change="pickImage"
                    />
                    <InputError :message="form.errors.image" />
                </div>

                <div class="grid gap-2">
                    <Label for="banner-title">Título</Label>
                    <Input
                        id="banner-title"
                        v-model="form.title"
                        required
                        placeholder="Ex.: Pizza em dobro às terças"
                    />
                    <InputError :message="form.errors.title" />
                </div>

                <div class="grid gap-2 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="link_type">Ao tocar</Label>
                        <NativeSelect
                            id="link_type"
                            v-model="form.link_type"
                            @change="form.link_target = ''"
                        >
                            <option
                                v-for="type in linkTypes"
                                :key="type.value"
                                :value="type.value"
                            >
                                {{ type.label }}
                            </option>
                        </NativeSelect>
                    </div>
                    <div v-if="form.link_type === 'url'" class="grid gap-2">
                        <Label for="link_url">Link</Label>
                        <Input
                            id="link_url"
                            v-model="form.link_target"
                            type="url"
                            placeholder="https://"
                        />
                    </div>
                    <div
                        v-else-if="form.link_type !== 'none'"
                        class="grid gap-2"
                    >
                        <Label for="link_target">Destino</Label>
                        <NativeSelect
                            id="link_target"
                            v-model="form.link_target"
                        >
                            <option value="" disabled>Escolha…</option>
                            <option
                                v-for="option in targetOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </NativeSelect>
                    </div>
                </div>
                <InputError :message="form.errors.link_target" />

                <div class="grid gap-2 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="banner-starts">Mostrar a partir de</Label>
                        <Input
                            id="banner-starts"
                            v-model="form.starts_at"
                            type="datetime-local"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="banner-ends">Até</Label>
                        <Input
                            id="banner-ends"
                            v-model="form.ends_at"
                            type="datetime-local"
                        />
                        <InputError :message="form.errors.ends_at" />
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <Checkbox id="banner-active" v-model="form.is_active" />
                    <Label for="banner-active">Banner ativo</Label>
                </div>

                <DialogFooter>
                    <Button type="submit" :disabled="form.processing">
                        Salvar
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
