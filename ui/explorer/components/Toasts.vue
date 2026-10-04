<script setup>
import { inject } from 'vue';
import Icon from './Icon.vue';

const fx = inject( 'fx' );
</script>

<template>
	<div class="fx-toasts" role="region" aria-label="Messages">
		<div v-for="t in fx.st.toasts" :key="t.id" class="fx-toast" :class="'is-' + t.kind" :role="t.kind === 'error' ? 'alert' : 'status'">
			<Icon :name="t.kind === 'error' ? 'alert' : t.kind === 'success' ? 'check' : 'info'" :size="16" />
			<span class="fx-toast__t">{{ t.message }}</span>
			<button type="button" class="fx-iconbtn" aria-label="Dismiss" @click="fx.dismissToast( t.id )"><Icon name="x" :size="14" /></button>
		</div>
	</div>
</template>
