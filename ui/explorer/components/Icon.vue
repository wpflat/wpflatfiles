<script setup>
import { computed } from 'vue';
import { ICONS } from '../icons';

const props = defineProps( { name: { type: String, required: true }, size: { type: [ Number, String ], default: 18 } } );
const html = computed( () => ICONS[ props.name ] || '' );
</script>

<template>
	<svg class="fx-icon" :width="size" :height="size" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" v-html="html"></svg>
</template>
