<script setup lang="ts">
import 'leaflet/dist/leaflet.css';
import type { CircleMarker, Map as LeafletMap } from 'leaflet';
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import type { MapTiles } from '@/types/maps';

/**
 * Map where a place is marked by tapping on it. Without a point yet it opens on Brazil.
 */
const props = withDefaults(
    defineProps<{
        tiles: MapTiles;
        height?: string;
    }>(),
    { height: '18rem' },
);

const point = defineModel<{ lat: number; lng: number } | null>({
    required: true,
});

const container = ref<HTMLElement | null>(null);
const map = shallowRef<LeafletMap | null>(null);
let marker: CircleMarker | null = null;
let leaflet: typeof import('leaflet') | null = null;

function draw(): void {
    if (!map.value || !leaflet) {
        return;
    }

    if (point.value === null) {
        marker?.remove();
        marker = null;

        return;
    }

    if (marker) {
        marker.setLatLng([point.value.lat, point.value.lng]);
    } else {
        marker = leaflet
            .circleMarker([point.value.lat, point.value.lng], {
                radius: 10,
                weight: 3,
                color: '#ffffff',
                fillColor: '#7b5cff',
                fillOpacity: 1,
            })
            .addTo(map.value);
    }
}

onMounted(async () => {
    leaflet = await import('leaflet');

    if (!container.value) {
        return;
    }

    map.value = leaflet.map(container.value, { zoomControl: true });
    leaflet
        .tileLayer(props.tiles.url, {
            attribution: props.tiles.attribution,
            maxZoom: 19,
        })
        .addTo(map.value);

    if (point.value) {
        map.value.setView([point.value.lat, point.value.lng], 17);
    } else {
        map.value.setView([-14.2, -51.9], 4);
    }

    map.value.on('click', (event) => {
        point.value = {
            lat: Number(event.latlng.lat.toFixed(7)),
            lng: Number(event.latlng.lng.toFixed(7)),
        };
    });

    draw();
});

watch(point, (value, previous) => {
    draw();

    // A point set from outside (the device location) is brought into view.
    if (
        value &&
        map.value &&
        (!previous ||
            Math.abs(value.lat - previous.lat) > 0.001 ||
            Math.abs(value.lng - previous.lng) > 0.001)
    ) {
        map.value.setView(
            [value.lat, value.lng],
            Math.max(map.value.getZoom(), 16),
        );
    }
});

onBeforeUnmount(() => {
    map.value?.remove();
});
</script>

<template>
    <div
        ref="container"
        class="z-0 w-full overflow-hidden rounded-xl border"
        :style="{ height }"
    />
</template>
