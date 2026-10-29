<script setup lang="ts">
import 'leaflet/dist/leaflet.css';
import type { CircleMarker, Map as LeafletMap } from 'leaflet';
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import type { MapMarker, MapTiles } from '@/types/maps';

/**
 * OpenStreetMap map with live markers (couriers). Leaflet is loaded only where a map is shown.
 * Markers are drawn as circles, so no image files are needed.
 */
const props = withDefaults(
    defineProps<{
        tiles: MapTiles;
        markers: MapMarker[];
        height?: string;
    }>(),
    { height: '18rem' },
);

const container = ref<HTMLElement | null>(null);
const map = shallowRef<LeafletMap | null>(null);
const drawn = new Map<MapMarker['id'], CircleMarker>();
let leaflet: typeof import('leaflet') | null = null;
let fittedFor = '';

function sync(): void {
    if (!map.value || !leaflet) {
        return;
    }

    const seen = new Set<MapMarker['id']>();

    for (const marker of props.markers) {
        seen.add(marker.id);
        const existing = drawn.get(marker.id);

        if (existing) {
            existing.setLatLng([marker.lat, marker.lng]);
            existing.setTooltipContent(marker.label);
            continue;
        }

        const circle = leaflet
            .circleMarker([marker.lat, marker.lng], {
                radius: 9,
                weight: 3,
                color: '#ffffff',
                fillColor: '#2563eb',
                fillOpacity: 1,
            })
            .bindTooltip(marker.label, {
                permanent: true,
                direction: 'top',
                offset: [0, -10],
            })
            .addTo(map.value);

        drawn.set(marker.id, circle);
    }

    for (const [id, circle] of drawn) {
        if (!seen.has(id)) {
            circle.remove();
            drawn.delete(id);
        }
    }

    // Re-frame only when the set of markers changes, so the user's zoom/pan is kept between updates.
    const key = props.markers.map((marker) => marker.id).join(',');

    if (key !== fittedFor && props.markers.length > 0) {
        fittedFor = key;

        if (props.markers.length === 1) {
            map.value.setView([props.markers[0].lat, props.markers[0].lng], 15);
        } else {
            map.value.fitBounds(
                leaflet.latLngBounds(
                    props.markers.map((marker) => [marker.lat, marker.lng]),
                ),
                { padding: [40, 40], maxZoom: 15 },
            );
        }
    } else if (props.markers.length === 1) {
        map.value.panTo([props.markers[0].lat, props.markers[0].lng]);
    }
}

onMounted(async () => {
    leaflet = await import('leaflet');

    if (!container.value) {
        return;
    }

    // Starts over Brazil until the first position arrives.
    map.value = leaflet
        .map(container.value, { zoomControl: true })
        .setView([-14.235, -51.925], 4);
    leaflet
        .tileLayer(props.tiles.url, {
            attribution: props.tiles.attribution,
            maxZoom: 19,
        })
        .addTo(map.value);

    sync();
});

watch(() => props.markers, sync, { deep: true });

onBeforeUnmount(() => {
    map.value?.remove();
    drawn.clear();
});
</script>

<template>
    <div
        ref="container"
        class="z-0 w-full overflow-hidden rounded-xl border"
        :style="{ height }"
    />
</template>
