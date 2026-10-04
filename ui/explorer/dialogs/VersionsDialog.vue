<script setup>
import { computed, inject, onMounted, ref } from 'vue';
import Modal from '../components/Modal.vue';
import Icon from '../components/Icon.vue';
import { fmtDate, fmtSize } from '../util';

const fx = inject( 'fx' );
const props = defineProps( { file: { type: Object, required: true } } );
const rows = ref( [] );
const loading = ref( true );
const error = ref( '' );
const busy = ref( 0 );
const canRestore = computed( () => fx.st.can.edit && ! fx.searching );

const load = async () => {
	loading.value = true;
	try {
		rows.value = await fx.api.get( '/fm/items/' + props.file.id + '/versions' );
	} catch ( e ) {
		error.value = e.message;
	} finally {
		loading.value = false;
	}
};
onMounted( load );
const restore = async ( v ) => {
	busy.value = v.version;
	try {
		await fx.api.post( '/fm/items/' + props.file.id + '/versions/' + v.version + '/restore' );
		fx.toast( 'Version ' + v.version + ' is now the current version.', 'success' );
		await Promise.all( [ load(), fx.refresh() ] );
	} catch ( e ) {
		fx.fail( e );
	} finally {
		busy.value = 0;
	}
};
</script>

<template>
	<Modal :title="'Version history · ' + file.name" wide @close="fx.closeDialog()">
		<div v-if="loading" class="fx-note">Loading…</div>
		<p v-else-if="error" class="fx-error" role="alert">{{ error }}</p>
		<table v-else class="fx-table">
			<thead><tr><th>Version</th><th>Added</th><th>By</th><th>Size</th><th></th></tr></thead>
			<tbody>
				<tr v-for="v in rows" :key="v.version">
					<td><strong>v{{ v.version }}</strong> <span v-if="v.current" class="fx-pill">Current</span><div v-if="v.note" class="fx-table__s">{{ v.note }}</div></td>
					<td>{{ fmtDate( v.created_at ) }}</td>
					<td>{{ v.uploader }}</td>
					<td>{{ fmtSize( v.size ) }}</td>
					<td class="fx-table__act">
						<a class="fx-secondary" :href="v.download_url" :download="v.name" :aria-label="'Download version ' + v.version"><Icon name="download" :size="14" /> <span class="fx-hide-narrow">Download</span></a>
						<button v-if="canRestore && ! v.current" type="button" class="fx-secondary" :disabled="busy === v.version" :aria-label="'Restore version ' + v.version" @click="restore( v )"><Icon name="restore" :size="14" /> <span class="fx-hide-narrow">Restore</span></button>
					</td>
				</tr>
			</tbody>
		</table>
		<template #footer><button type="button" class="fx-primary" @click="fx.closeDialog()">Done</button></template>
	</Modal>
</template>
