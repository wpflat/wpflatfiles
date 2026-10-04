<script setup>
import { inject, nextTick, onMounted, ref } from 'vue';
import Modal from '../components/Modal.vue';

/** New folder, and Rename (for a folder or a file). */
const fx = inject( 'fx' );
const props = defineProps( { mode: { type: String, default: 'new' }, item: { type: Object, default: null } } );
const isNew = props.mode === 'new';
const name = ref( isNew ? 'New folder' : props.item.name );
const error = ref( '' );
const busy = ref( false );
const input = ref( null );

onMounted( async () => {
	await nextTick();
	if ( ! input.value ) return;
	const dot = ! isNew && props.item.type === 'file' ? name.value.lastIndexOf( '.' ) : -1;
	input.value.focus();
	input.value.setSelectionRange( 0, dot > 0 ? dot : name.value.length );
} );

const submit = async () => {
	const v = name.value.trim();
	if ( ! v ) {
		error.value = 'Type a name.';
		return;
	}
	if ( /[\\/:*?"<>|]/.test( v ) ) {
		error.value = 'A name cannot contain any of these: \\ / : * ? " < > |';
		return;
	}
	if ( ! isNew && v === props.item.name ) return fx.closeDialog();
	busy.value = true;
	error.value = '';
	try {
		if ( isNew ) await fx.createFolder( v );
		else await fx.renameItem( props.item, v );
		fx.closeDialog();
	} catch ( e ) {
		error.value = e.message;
	} finally {
		busy.value = false;
	}
};
</script>

<template>
	<Modal :title="isNew ? 'New folder' : 'Rename'" narrow @close="fx.closeDialog()">
		<form class="fx-form" @submit.prevent="submit">
			<label class="fx-field">
				<span>{{ isNew ? 'Folder name' : 'New name' }}</span>
				<input ref="input" v-model="name" type="text" maxlength="180" autocomplete="off" spellcheck="false" :aria-invalid="error ? 'true' : undefined" aria-describedby="fx-name-err" />
			</label>
			<p v-if="error" id="fx-name-err" class="fx-error" role="alert">{{ error }}</p>
		</form>
		<template #footer>
			<button type="button" class="fx-secondary" @click="fx.closeDialog()">Cancel</button>
			<button type="button" class="fx-primary" :disabled="busy" @click="submit">{{ isNew ? 'Create' : 'Rename' }}</button>
		</template>
	</Modal>
</template>
