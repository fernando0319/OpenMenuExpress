<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import type { MenuBanner } from '@/types/menu';

const props = defineProps<{
    banners: MenuBanner[];
}>();

const emit = defineEmits<{
    select: [banner: MenuBanner];
}>();

const track = ref<HTMLElement | null>(null);
const current = ref(0);
let timer: ReturnType<typeof setInterval> | undefined;

function goTo(position: number): void {
    const element = track.value;

    if (!element || props.banners.length === 0) {
        return;
    }

    const next = (position + props.banners.length) % props.banners.length;
    element.scrollTo({ left: next * element.clientWidth, behavior: 'smooth' });
    current.value = next;
}

function onScroll(): void {
    const element = track.value;

    if (element && element.clientWidth > 0) {
        current.value = Math.round(element.scrollLeft / element.clientWidth);
    }
}

function restart(): void {
    clearInterval(timer);

    if (props.banners.length > 1) {
        timer = setInterval(() => goTo(current.value + 1), 6000);
    }
}

onMounted(restart);
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <section v-if="banners.length > 0" aria-label="Destaques">
        <div
            ref="track"
            class="flex snap-x snap-mandatory [scrollbar-width:none] overflow-x-auto rounded-3xl shadow-soft [&::-webkit-scrollbar]:hidden"
            @scroll.passive="onScroll"
            @pointerdown="restart"
        >
            <button
                v-for="banner in banners"
                :key="banner.id"
                type="button"
                class="w-full shrink-0 snap-center"
                :class="{ 'cursor-default': banner.linkType === 'none' }"
                :aria-label="banner.title"
                @click="emit('select', banner)"
            >
                <img
                    :src="banner.imageUrl"
                    :alt="banner.title"
                    class="aspect-[12/5] w-full object-cover md:aspect-[16/5]"
                    loading="lazy"
                />
            </button>
        </div>
        <div v-if="banners.length > 1" class="mt-2 flex justify-center gap-1.5">
            <button
                v-for="(banner, position) in banners"
                :key="banner.id"
                type="button"
                class="h-2 w-2 rounded-full transition-all"
                :class="position === current ? 'w-5 bg-brand' : 'bg-brand/20'"
                :aria-label="`Ir para o banner ${position + 1}`"
                @click="
                    goTo(position);
                    restart();
                "
            />
        </div>
    </section>
</template>
