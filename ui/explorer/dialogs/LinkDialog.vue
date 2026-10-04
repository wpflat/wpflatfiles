<script setup>
import { inject, nextTick, onMounted, ref } from 'vue';
import Modal from '../components/Modal.vue';
import { isWebUrl } from '../util';

/** New link: a video or file that lives somewhere else (YouTube, Vimeo, Loom, Google Drive, Bunny Stream…). */
const fx = inject( 'fx' );
const name = ref( '' );
const url = ref( '' );
const description = ref( '' );
const error = ref( '' );
const busy = ref( false );
const input = ref( null );

onMounted( async () => {
	await nextTick();
	if ( input.value ) input.value.focus();
} );

const submit = async () => {
	const n = name.value.trim();
	const u = url.value.trim();
	if ( ! n ) {
		error.value = 'Type a name.';
		return;
	}
	if ( /[\\/:*?"<>|]/.test( n ) ) {
		error.value = 'A name cannot contain any of these: \\ / : * ? " < > |';
		return;
	}
	if ( ! isWebUrl( u ) ) {
		error.value = 'Enter a web address that starts with http:// or https://';
		return;
	}
	busy.value = true;
	error.value = '';
	try {
		await fx.createLink( { name: n, url: u, description: description.value.trim() } );
		fx.closeDialog();
		fx.toast( 'Link added.', 'success', 2500 );
	} catch ( e ) {
		error.value = e.message;
	} finally {
		busy.value = false;
	}
};
</script>

<template>
	<Modal title="New link" narrow @close="fx.closeDialog()">
		<form class="fx-form" @submit.prevent="submit">
			<label class="fx-field"><span>Name</span><input ref="input" v-model="name" type="text" maxlength="180" autocomplete="off" data-link="name" /></label>
			<label class="fx-field"><span>Web address</span><input v-model="url" type="url" maxlength="2000" autocomplete="off" spellcheck="false" placeholder="https://" data-link="url" /></label>
			<label class="fx-field"><span>Description</span><textarea v-model="description" rows="2" maxlength="500" data-link="description"></textarea></label>
			<p v-if="error" class="fx-error" role="alert">{{ error }}</p>
		</form>
		<template #footer>
			<button type="button" class="fx-secondary" @click="fx.closeDialog()">Cancel</button>
			<button type="button" class="fx-primary" :disabled="busy" @click="submit">Add link</button>
		</template>
	</Modal>
</template>
