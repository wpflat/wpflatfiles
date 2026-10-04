<script setup>
import { inject, ref } from 'vue';
import Modal from '../components/Modal.vue';

const fx = inject( 'fx' );
const props = defineProps( { title: String, message: String, note: { type: String, default: '' }, confirm: { type: String, default: 'OK' }, danger: Boolean, run: Function } );
const busy = ref( false );
const go = async () => {
	busy.value = true;
	fx.closeDialog();
	try {
		await props.run();
	} catch ( e ) {
		fx.fail( e );
	}
};
</script>

<template>
	<Modal :title="title" narrow @close="fx.closeDialog()">
		<p class="fx-confirm__msg">{{ message }}</p>
		<p v-if="note" class="fx-confirm__note">{{ note }}</p>
		<template #footer>
			<button type="button" class="fx-secondary" data-autofocus @click="fx.closeDialog()">Cancel</button>
			<button type="button" class="fx-primary" :class="{ 'is-danger': danger }" :disabled="busy" @click="go">{{ confirm }}</button>
		</template>
	</Modal>
</template>
