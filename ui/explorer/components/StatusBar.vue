<script setup>
import { inject } from 'vue';
import Icon from './Icon.vue';

const fx = inject( 'fx' );
</script>

<template>
	<footer class="fx-status">
		<div class="fx-status__t" role="status" aria-live="polite">{{ fx.statusText }}</div>
		<div v-if="! fx.st.trash" class="fx-status__views" role="group" aria-label="View">
			<button v-for="v in fx.VIEWS" :key="v.id" type="button" class="fx-iconbtn" :class="{ 'is-on': fx.st.view === v.id }" :aria-label="v.label" :aria-pressed="String( fx.st.view === v.id )" :title="v.label" @click="fx.setView( v.id )"><Icon :name="v.icon" :size="16" /></button>
		</div>
	</footer>
</template>
