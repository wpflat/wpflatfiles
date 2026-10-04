<script setup>
import { inject, onMounted, ref } from 'vue';
import Modal from '../components/Modal.vue';
import Icon from '../components/Icon.vue';
import { copyText, fmtDay } from '../util';

/** Public links for a file: make one (expiry, password, download limit), copy it, see the old ones, switch one off. */
const fx = inject( 'fx' );
const props = defineProps( { file: { type: Object, required: true } } );
const links = ref( [] );
const loading = ref( true );
const form = ref( { days: 7, password: '', max: '' } );
const busy = ref( false );
const error = ref( '' );
const fresh = ref( null );

const load = async () => {
	try {
		links.value = await fx.api.get( '/fm/items/' + props.file.id + '/shares' );
	} catch ( e ) {
		error.value = e.message;
	} finally {
		loading.value = false;
	}
};
onMounted( load );

const create = async () => {
	busy.value = true;
	error.value = '';
	try {
		const body = { expires_days: Number( form.value.days ) };
		if ( form.value.password ) body.password = form.value.password;
		if ( Number( form.value.max ) > 0 ) body.max_downloads = Number( form.value.max );
		const row = await fx.api.post( '/fm/items/' + props.file.id + '/share', body );
		fresh.value = row;
		form.value.password = '';
		form.value.max = '';
		await load();
		copy( row.url );
	} catch ( e ) {
		error.value = e.message;
	} finally {
		busy.value = false;
	}
};
const copy = async ( url ) => {
	fx.toast( ( await copyText( url ) ) ? 'Link copied.' : 'Select the link and copy it.', 'info', 2500 );
};
const revoke = async ( l ) => {
	try {
		await fx.api.del( '/fm/shares/' + l.id );
		if ( fresh.value && fresh.value.id === l.id ) fresh.value = null;
		await load();
		fx.toast( 'The link no longer works.', 'success', 3000 );
	} catch ( e ) {
		fx.fail( e );
	}
};
const state = ( l ) => ( l.active ? 'Active' : 'Not active' );
</script>

<template>
	<Modal :title="'Share · ' + file.name" wide @close="fx.closeDialog()">
		<section class="fx-form">
			<h3 class="fx-h3">Create a link</h3>
			<p class="fx-note">Anyone who has the link can download this file until it expires.</p>
			<div class="fx-row">
				<label class="fx-field"><span>Link works for</span>
					<select v-model="form.days">
						<option :value="1">1 day</option><option :value="7">7 days</option><option :value="30">30 days</option><option :value="90">90 days</option><option :value="0">No expiry</option>
					</select>
				</label>
				<label class="fx-field"><span>Password (optional)</span><input v-model="form.password" type="text" autocomplete="off" maxlength="80" /></label>
				<label class="fx-field"><span>Download limit (optional)</span><input v-model="form.max" type="number" min="1" max="100000" placeholder="No limit" /></label>
			</div>
			<p v-if="error" class="fx-error" role="alert">{{ error }}</p>
			<div v-if="fresh" class="fx-linkbox">
				<input class="fx-linkbox__in" :value="fresh.url" readonly aria-label="The new link" @focus="$event.target.select()" />
				<button type="button" class="fx-primary" @click="copy( fresh.url )"><Icon name="copy" :size="15" /> Copy</button>
			</div>
			<div><button type="button" class="fx-primary" :disabled="busy" @click="create"><Icon name="link" :size="15" /> Create link</button></div>
		</section>
		<section class="fx-form">
			<h3 class="fx-h3">Existing links</h3>
			<div v-if="loading" class="fx-note">Loading…</div>
			<p v-else-if="! links.length" class="fx-note">No links yet.</p>
			<ul v-else class="fx-links">
				<li v-for="l in links" :key="l.id" class="fx-links__row" :class="{ 'is-off': ! l.active }">
					<div class="fx-links__main">
						<div class="fx-links__url">{{ l.url }}</div>
						<div class="fx-links__meta">
							<span class="fx-pill" :class="{ 'is-off': ! l.active }">{{ state( l ) }}</span>
							<span>{{ l.expires_at ? 'Expires ' + fmtDay( l.expires_at ) : 'No expiry' }}</span>
							<span>{{ l.downloads }}{{ l.max_downloads ? ' of ' + l.max_downloads : '' }} downloads</span>
							<span v-if="l.protected"><Icon name="lock" :size="12" /> Password</span>
						</div>
					</div>
					<button type="button" class="fx-iconbtn" aria-label="Copy link" @click="copy( l.url )"><Icon name="copy" :size="15" /></button>
					<button v-if="l.active" type="button" class="fx-secondary" @click="revoke( l )">Revoke</button>
				</li>
			</ul>
		</section>
		<template #footer><button type="button" class="fx-primary" @click="fx.closeDialog()">Done</button></template>
	</Modal>
</template>
