<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ExternalLink, ImagePlus, ShoppingBasket, Trash2 } from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import AppearanceController from '@/actions/App/Http/Controllers/StoreSettings/AppearanceController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import TextareaField from '@/components/TextareaField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { show as menuShow } from '@/routes/menu';
import { edit } from '@/routes/store-settings/appearance';
import type { MenuBranding } from '@/types/menu';

type Preset = { name: string; primary: string; accent: string };

const props = defineProps<{
    store: { name: string; slug: string; description: string | null };
    branding: MenuBranding;
    presets: Preset[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Aparência', href: edit() }],
    },
});

const form = useForm({
    _method: 'put',
    name: props.store.name,
    description: props.store.description ?? '',
    primary_color: props.branding.primaryColor,
    accent_color: props.branding.accentColor,
    theme: props.branding.theme,
    logo: null as File | null,
    cover: null as File | null,
    remove_logo: false,
    remove_cover: false,
});

// Local previews of picked files, released when replaced or when leaving the page.
const logoPreview = ref<string | null>(props.branding.logoUrl);
const coverPreview = ref<string | null>(props.branding.coverUrl);
const created: string[] = [];

function pick(event: Event, field: 'logo' | 'cover'): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;

    if (!file) {
        return;
    }

    const url = URL.createObjectURL(file);
    created.push(url);
    form[field] = file;

    if (field === 'logo') {
        form.remove_logo = false;
        logoPreview.value = url;
    } else {
        form.remove_cover = false;
        coverPreview.value = url;
    }
}

function clear(field: 'logo' | 'cover'): void {
    form[field] = null;

    if (field === 'logo') {
        form.remove_logo = true;
        logoPreview.value = null;
    } else {
        form.remove_cover = true;
        coverPreview.value = null;
    }
}

onBeforeUnmount(() => created.forEach((url) => URL.revokeObjectURL(url)));

function usePreset(preset: Preset): void {
    form.primary_color = preset.primary;
    form.accent_color = preset.accent;
}

/** WCAG contrast of white text on the color; below 3:1 white text gets hard to read. */
function contrastWithWhite(hex: string): number {
    const channels = [1, 3, 5].map((start) => {
        const value = parseInt(hex.slice(start, start + 2), 16) / 255;

        return value <= 0.03928
            ? value / 12.92
            : ((value + 0.055) / 1.055) ** 2.4;
    });
    const luminance =
        0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];

    return 1.05 / (luminance + 0.05);
}

const primaryTooLight = computed(
    () => contrastWithWhite(form.primary_color) < 3,
);

const previewStyle = computed(() => ({
    '--color-brand': form.primary_color,
    '--color-brand-accent': form.accent_color,
}));

function save(): void {
    form.post(AppearanceController.update.url(), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.logo = null;
            form.cover = null;
            form.remove_logo = false;
            form.remove_cover = false;
        },
    });
}
</script>

<template>
    <Head title="Aparência" />

    <div class="grid max-w-5xl gap-8 p-4 lg:grid-cols-[1fr_22rem]">
        <form class="flex flex-col gap-8" @submit.prevent="save">
            <div class="flex flex-wrap items-center gap-3">
                <Heading
                    title="Aparência"
                    description="Como a sua loja aparece no cardápio para os clientes."
                />
                <Button as-child variant="outline" class="ml-auto">
                    <a
                        :href="menuShow.url(store.slug)"
                        target="_blank"
                        rel="noopener"
                    >
                        <ExternalLink />
                        Ver cardápio
                    </a>
                </Button>
            </div>

            <section class="grid gap-4">
                <h2 class="font-semibold">Identificação</h2>
                <div class="grid gap-2">
                    <Label for="store-name">Nome da loja</Label>
                    <Input id="store-name" v-model="form.name" maxlength="80" />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="store-description">Descrição curta</Label>
                    <TextareaField
                        id="store-description"
                        v-model="form.description"
                        maxlength="300"
                        class="min-h-16"
                        placeholder="Ex.: Lanches artesanais e porções. Entregamos em toda a cidade."
                    />
                    <InputError :message="form.errors.description" />
                </div>
            </section>

            <section class="grid gap-4">
                <h2 class="font-semibold">Logo e capa</h2>
                <div class="grid gap-4 sm:grid-cols-[8rem_1fr]">
                    <div class="grid gap-2">
                        <Label>Logo</Label>
                        <label
                            class="grid size-32 cursor-pointer place-items-center overflow-hidden rounded-3xl border-2 border-dashed text-muted-foreground hover:border-primary"
                        >
                            <img
                                v-if="logoPreview"
                                :src="logoPreview"
                                alt="Logo"
                                class="size-full object-cover"
                            />
                            <span
                                v-else
                                class="flex flex-col items-center gap-1 text-xs"
                                ><ImagePlus class="size-6" /> Enviar</span
                            >
                            <input
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                class="sr-only"
                                @change="pick($event, 'logo')"
                            />
                        </label>
                        <Button
                            v-if="logoPreview"
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="justify-start"
                            @click="clear('logo')"
                        >
                            <Trash2 /> Remover
                        </Button>
                        <p class="text-xs text-muted-foreground">
                            Quadrada, de preferência 512×512.
                        </p>
                        <InputError :message="form.errors.logo" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label>Capa</Label>
                        <label
                            class="grid aspect-[3/1] cursor-pointer place-items-center overflow-hidden rounded-3xl border-2 border-dashed text-muted-foreground hover:border-primary"
                        >
                            <img
                                v-if="coverPreview"
                                :src="coverPreview"
                                alt="Capa"
                                class="size-full object-cover"
                            />
                            <span
                                v-else
                                class="flex flex-col items-center gap-1 text-xs"
                                ><ImagePlus class="size-6" /> Enviar capa</span
                            >
                            <input
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                class="sr-only"
                                @change="pick($event, 'cover')"
                            />
                        </label>
                        <Button
                            v-if="coverPreview"
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="justify-self-start"
                            @click="clear('cover')"
                        >
                            <Trash2 /> Remover
                        </Button>
                        <p class="text-xs text-muted-foreground">
                            Horizontal, de preferência 1500×500. Sem capa, o
                            topo usa as cores da loja.
                        </p>
                        <InputError :message="form.errors.cover" />
                    </div>
                </div>
            </section>

            <section class="grid gap-4">
                <h2 class="font-semibold">Cores</h2>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="preset in presets"
                        :key="preset.name"
                        type="button"
                        class="flex items-center gap-2 rounded-full border py-1 pr-3 pl-1 text-sm hover:border-primary"
                        :class="{
                            'border-primary ring-1 ring-primary':
                                preset.primary === form.primary_color &&
                                preset.accent === form.accent_color,
                        }"
                        @click="usePreset(preset)"
                    >
                        <span
                            class="size-6 rounded-full"
                            :style="{
                                background: `linear-gradient(135deg, ${preset.primary}, ${preset.accent})`,
                            }"
                        />
                        {{ preset.name }}
                    </button>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="primary-color">Cor principal</Label>
                        <div class="flex items-center gap-2">
                            <input
                                id="primary-color"
                                v-model="form.primary_color"
                                type="color"
                                class="h-10 w-14 cursor-pointer rounded-md border"
                            />
                            <Input
                                v-model="form.primary_color"
                                maxlength="7"
                                class="font-mono uppercase"
                                aria-label="Cor principal em hexadecimal"
                            />
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Botões, títulos e o botão do carrinho.
                        </p>
                        <InputError :message="form.errors.primary_color" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="accent-color">Cor de destaque</Label>
                        <div class="flex items-center gap-2">
                            <input
                                id="accent-color"
                                v-model="form.accent_color"
                                type="color"
                                class="h-10 w-14 cursor-pointer rounded-md border"
                            />
                            <Input
                                v-model="form.accent_color"
                                maxlength="7"
                                class="font-mono uppercase"
                                aria-label="Cor de destaque em hexadecimal"
                            />
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Fim do degradê, preços no carrinho e detalhes.
                        </p>
                        <InputError :message="form.errors.accent_color" />
                    </div>
                </div>
                <p
                    v-if="primaryTooLight"
                    class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900"
                >
                    Essa cor principal é clara: o texto branco dos botões pode
                    ficar difícil de ler. Prefira um tom mais escuro.
                </p>
            </section>

            <section
                class="grid gap-2 rounded-xl border border-dashed p-4 text-sm"
            >
                <h2 class="font-semibold">Tema</h2>
                <p class="text-muted-foreground">
                    Sua loja usa o <strong>Tema Padrão</strong>. Logo, capa e
                    cores valem para qualquer tema que você usar no futuro.
                </p>
            </section>

            <Button type="submit" class="self-start" :disabled="form.processing"
                >Salvar aparência</Button
            >
        </form>

        <aside class="lg:sticky lg:top-4 lg:self-start">
            <p class="mb-2 text-sm font-medium text-muted-foreground">Prévia</p>
            <div
                class="menu-light-scope overflow-hidden rounded-[2rem] border-8 border-slate-900 bg-brand-surface font-display text-brand-ink shadow-xl dark:border-slate-700"
                :style="previewStyle"
            >
                <div class="h-24 overflow-hidden rounded-b-3xl">
                    <img
                        v-if="coverPreview"
                        :src="coverPreview"
                        alt=""
                        class="size-full object-cover"
                    />
                    <div
                        v-else
                        class="size-full bg-linear-to-br from-brand to-brand-accent"
                    />
                </div>
                <div class="relative -mt-7 flex items-start gap-2 px-3">
                    <span
                        class="grid size-14 shrink-0 place-items-center overflow-hidden rounded-2xl bg-white ring-4 ring-white"
                    >
                        <img
                            v-if="logoPreview"
                            :src="logoPreview"
                            alt=""
                            class="size-full object-cover"
                        />
                        <span
                            v-else
                            class="grid size-full place-items-center bg-linear-to-br from-brand to-brand-accent font-bold text-white"
                            >{{
                                (form.name || 'L').charAt(0).toUpperCase()
                            }}</span
                        >
                    </span>
                    <div class="mt-8 min-w-0">
                        <p class="truncate text-sm font-semibold text-brand">
                            {{ form.name || 'Sua loja' }}
                        </p>
                        <p class="text-[10px] text-muted-foreground">
                            ● Aberto agora
                        </p>
                    </div>
                </div>
                <div class="flex flex-col gap-3 p-3">
                    <div class="h-8 rounded-xl border bg-white" />
                    <p class="text-xs font-semibold">Produtos populares</p>
                    <div
                        class="flex items-center gap-2 rounded-2xl bg-white p-2 shadow-soft"
                    >
                        <span class="size-12 rounded-xl bg-brand/10" />
                        <span class="flex-1">
                            <span class="block text-xs font-medium"
                                >Clássico Burguer</span
                            >
                            <span class="block text-xs font-semibold"
                                >R$ 21,99</span
                            >
                        </span>
                    </div>
                    <div
                        class="rounded-xl bg-linear-to-r from-brand to-brand-accent py-2.5 text-center text-xs font-medium text-white"
                    >
                        Adicionar
                    </div>
                    <div
                        class="mx-auto -mb-1 grid size-11 place-items-center rounded-full bg-brand text-white shadow-lg"
                    >
                        <ShoppingBasket class="size-5" />
                    </div>
                </div>
            </div>
        </aside>
    </div>
</template>
