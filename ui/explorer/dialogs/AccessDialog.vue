<script setup>
import { computed, inject, onMounted, ref, watch } from 'vue';
import Modal from '../components/Modal.vue';
import Icon from '../components/Icon.vue';
import { LEVELS, LEVEL_HELP, LEVEL_LABEL, debounce } from '../util';

/**
 * Who can open this folder. "Use the same access as the parent folder", or a list of rules: the team, all users,
 * a group or a client. The owner's words for group and client come from config.words.
 */
const fx = inject( 'fx' );
const props = defineProps( { folder: { type: Object, required: true } } );
const loading = ref( true );
const error = ref( '' );
const saving = ref( false );
const inherit = ref( true );
const topLevel = ref( false );
const from = ref( null );
const rules = ref( [] );

const typeLabel = ( t ) => ( t === 'team' ? fx.word( 'team', true ) : t === 'users' ? 'All users' : t === 'group' ? fx.word( 'group' ) : fx.word( 'client' ) );
const kinds = computed( () => {
	const k = [ { id: 'team', label: typeLabel( 'team' ) }, { id: 'users', label: typeLabel( 'users' ) } ];
	if ( fx.cfg.staff ) k.push( { id: 'group', label: typeLabel( 'group' ) }, { id: 'client', label: typeLabel( 'client' ) } );
	return k;
} );
const adding = ref( 'team' );
const addLevel = ref( 'view' );
const q = ref( '' );
const found = ref( [] );
const searching = ref( false );
const picking = computed( () => adding.value === 'group' || adding.value === 'client' );

onMounted( async () => {
	try {
		const r = await fx.api.get( '/fm/folders/' + props.folder.id + '/access' );
		inherit.value = !! r.inherit;
		topLevel.value = !! r.top_level;
		from.value = r.from || null;
		rules.value = ( r.rules || [] ).map( ( x ) => ( { type: x.type, id: Number( x.id ) || 0, level: x.level, name: x.name || '' } ) );
	} catch ( e ) {
		error.value = e.message;
	} finally {
		loading.value = false;
	}
} );

const search = async () => {
	if ( ! picking.value ) return;
	searching.value = true;
	try {
		found.value = await fx.api.get( '/fm/pickers', { type: adding.value, q: q.value } );
	} catch ( e ) {
		found.value = [];
		error.value = e.message;
	} finally {
		searching.value = false;
	}
};
const later = debounce( search, 250 );
watch( adding, () => {
	q.value = '';
	found.value = [];
	if ( picking.value ) search();
} );
const has = ( type, id ) => rules.value.some( ( r ) => r.type === type && r.id === id );
const add = ( type, id, name ) => {
	if ( has( type, id ) ) return;
	rules.value.push( { type, id, level: addLevel.value, name } );
	if ( inherit.value ) inherit.value = false;
};
const addSimple = () => add( adding.value, 0, typeLabel( adding.value ) );
const remove = ( i ) => rules.value.splice( i, 1 );
const nameOf = ( r ) => ( r.type === 'team' || r.type === 'users' ? typeLabel( r.type ) : r.name || '#' + r.id );

const save = async () => {
	saving.value = true;
	error.value = '';
	try {
		await fx.api.put( '/fm/folders/' + props.folder.id + '/access', { inherit: inherit.value, rules: rules.value.map( ( r ) => ( { type: r.type, id: r.id, level: r.level } ) ) } );
		fx.toast( 'Access saved.', 'success', 3000 );
		fx.closeDialog();
		fx.refresh();
	} catch ( e ) {
		error.value = e.message;
	} finally {
		saving.value = false;
	}
};
</script>

<template>
	<Modal :title="'Who can access “' + folder.name + '”'" wide @close="fx.closeDialog()">
		<div v-if="loading" class="fx-note">Loading…</div>
		<template v-else>
			<label class="fx-check" :class="{ 'is-disabled': topLevel }">
				<input v-model="inherit" type="checkbox" :disabled="topLevel" />
				<span>Use the same access as the parent folder</span>
			</label>
			<p v-if="topLevel" class="fx-note">A top-level folder always has its own access.</p>
			<p v-else-if="inherit" class="fx-note">{{ from ? 'This folder follows “' + from.name + '”.' : 'This folder follows the folder above it.' }} Untick the box to set your own rules.</p>

			<div v-if="! inherit" class="fx-access">
				<h3 class="fx-h3">People with access</h3>
				<p v-if="! rules.length" class="fx-note">Nobody yet. Only the team can open this folder until you add someone.</p>
				<ul class="fx-rules">
					<li v-for="( r, i ) in rules" :key="r.type + r.id" class="fx-rule">
						<span class="fx-rule__ic"><Icon :name="r.type === 'users' || r.type === 'client' ? 'cursor' : 'people'" :size="15" /></span>
						<span class="fx-rule__n"><strong>{{ nameOf( r ) }}</strong><small>{{ r.type === 'group' || r.type === 'client' ? typeLabel( r.type ) : '' }}</small></span>
						<select v-model="r.level" class="fx-select" :aria-label="'Access level for ' + nameOf( r )">
							<option v-for="l in LEVELS" :key="l" :value="l">{{ LEVEL_LABEL[ l ] }}</option>
						</select>
						<button type="button" class="fx-iconbtn" :aria-label="'Remove ' + nameOf( r )" @click="remove( i )"><Icon name="x" :size="15" /></button>
					</li>
				</ul>

				<h3 class="fx-h3">Add</h3>
				<div class="fx-row fx-row--add">
					<label class="fx-field"><span>Who</span>
						<select v-model="adding" aria-label="Who to add">
							<option v-for="k in kinds" :key="k.id" :value="k.id">{{ k.label }}</option>
						</select>
					</label>
					<label class="fx-field"><span>Can</span>
						<select v-model="addLevel" aria-label="Access level to give">
							<option v-for="l in LEVELS" :key="l" :value="l">{{ LEVEL_LABEL[ l ] }}</option>
						</select>
					</label>
					<div v-if="! picking" class="fx-field fx-field--btn"><button type="button" class="fx-secondary" :disabled="has( adding, 0 )" @click="addSimple"><Icon name="plus" :size="14" /> Add</button></div>
				</div>
				<div v-if="picking" class="fx-picker">
					<label class="fx-field"><span>Find a {{ fx.word( adding, false, true ) }}</span><input v-model="q" type="search" autocomplete="off" :placeholder="'Search ' + fx.word( adding, true, true )" @input="later" /></label>
					<ul class="fx-picker__list" role="listbox" :aria-label="fx.word( adding, true )">
						<li v-if="searching" class="fx-note">Searching…</li>
						<li v-else-if="! found.length" class="fx-note">Nothing found.</li>
						<li v-for="f in found" :key="f.id">
							<button type="button" class="fx-picker__item" role="option" :aria-selected="String( has( adding, f.id ) )" :disabled="has( adding, f.id )" @click="add( adding, f.id, f.name )">
								<span>{{ f.name }}</span><Icon v-if="has( adding, f.id )" name="check" :size="14" /><Icon v-else name="plus" :size="14" />
							</button>
						</li>
					</ul>
				</div>
				<dl class="fx-levels">
					<template v-for="l in LEVELS" :key="l"><dt>{{ LEVEL_LABEL[ l ] }}</dt><dd>{{ LEVEL_HELP[ l ] }}</dd></template>
				</dl>
			</div>
			<p v-if="error" class="fx-error" role="alert">{{ error }}</p>
		</template>
		<template #footer>
			<button type="button" class="fx-secondary" @click="fx.closeDialog()">Cancel</button>
			<button type="button" class="fx-primary" :disabled="saving || loading" @click="save">Save</button>
		</template>
	</Modal>
</template>
