<script setup>
import { computed } from 'vue';
import { KIND_ICONS } from '../icons';

/** A folder (tinted with its colour) or a file-type icon; images can show their thumbnail instead. */
const props = defineProps( {
	type: { type: String, default: 'file' },
	kind: { type: String, default: 'other' },
	color: { type: String, default: '' },
	size: { type: [ Number, String ], default: 20 },
	thumb: { type: String, default: '' },
	badge: { type: String, default: '' },
	open: Boolean,
} );
const html = computed( () => KIND_ICONS[ props.kind ] || KIND_ICONS.other );
const css = computed( () => ( typeof props.size === 'number' ? props.size + 'px' : props.size ) );
const c = computed( () => props.color || 'var(--fx-folder)' );
</script>

<template>
	<span class="fx-fi" :class="{ 'fx-fi--thumb': thumb }" :style="{ width: css, height: css }">
		<svg v-if="type === 'folder'" class="fx-fi__svg" viewBox="0 0 32 32" aria-hidden="true" focusable="false">
			<path d="M3 8a3 3 0 0 1 3-3h6.2a3 3 0 0 1 2.2 1l1.4 1.6H26a3 3 0 0 1 3 3V24a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3z" :fill="c" />
			<path d="M3 8a3 3 0 0 1 3-3h6.2a3 3 0 0 1 2.2 1l1.4 1.6H26a3 3 0 0 1 3 3V24a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3z" fill="#000" opacity=".16" />
			<path d="M3 13.5A2.5 2.5 0 0 1 5.5 11h21a2.5 2.5 0 0 1 2.5 2.5V24a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3z" :fill="c" class="fx-fi__front" />
			<path d="M3 13.5A2.5 2.5 0 0 1 5.5 11h21a2.5 2.5 0 0 1 2.5 2.5V17H3z" fill="#fff" opacity=".24" />
		</svg>
		<img v-else-if="thumb" class="fx-fi__thumb" :src="thumb" alt="" loading="lazy" draggable="false" />
		<svg v-else class="fx-fi__svg" viewBox="0 0 32 32" aria-hidden="true" focusable="false" v-html="html"></svg>
		<span v-if="badge" class="fx-fi__badge" :title="badge === 'people' ? 'Has its own access rules' : ''"><svg viewBox="0 0 24 24" width="100%" height="100%" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/></svg></span>
	</span>
</template>
