<script setup>
import { computed, inject, ref } from 'vue';
import Modal from '../components/Modal.vue';
import FileIcon from '../components/FileIcon.vue';
import { countLabel, fmtDate, fmtSize, iconKind, isWebUrl, typeLabel } from '../util';

/** Properties: the facts about one item, or a total for several. Description and tags can be edited by people who can edit. */
const fx = inject( 'fx' );
const props = defineProps( { items: { type: Array, required: true } } );
const one = computed( () => ( props.items.length === 1 ? props.items[ 0 ] : null ) );
const editable = computed( () => !! one.value && one.value.type !== 'trash' && ( one.value.type === 'folder' ? one.value.row.level >= 3 : fx.st.can.edit && ! fx.searching ) );
const description = ref( one.value ? one.value.row.description || '' : '' );
const tags = ref( one.value && one.value.type === 'file' ? one.value.row.tags || '' : '' );
const isLink = computed( () => !! one.value && one.value.type === 'file' && !! one.value.row.is_link );
const address = ref( one.value && one.value.row.is_link ? one.value.row.link_url || '' : '' );
const busy = ref( false );
const error = ref( '' );

const rows = computed( () => {
	const it = one.value;
	if ( ! it ) {
		const files = props.items.filter( ( i ) => i.type === 'file' ).length;
		const folders = props.items.filter( ( i ) => i.type === 'folder' ).length;
		return [
			[ 'Contains', [ files ? countLabel( files, 'file', 'files' ) : '', folders ? countLabel( folders, 'folder', 'folders' ) : '' ].filter( Boolean ).join( ', ' ) ],
			[ 'Total size', fmtSize( props.items.reduce( ( n, i ) => n + ( i.type === 'file' ? Number( i.row.size ) || 0 : 0 ), 0 ) ) ],
		];
	}
	const r = it.row;
	if ( it.type === 'folder' ) {
		return [ [ 'Type', 'File folder' ], [ 'Location', ( fx.crumbs.slice( 0, -1 ).map( ( c ) => c.name ).join( ' / ' ) ) || 'Home' ], [ 'Contains', countLabel( r.items || 0 ) ], [ 'Modified', fmtDate( r.modified ) ], [ 'Access', r.inherit ? 'Same as the folder above' : 'Has its own rules' ] ];
	}
	if ( r.is_link ) {
		return [ [ 'Type', 'Link' ], [ 'Address', r.link_url ], [ 'Location', r.path || ( fx.crumbs.map( ( c ) => c.name ).join( ' / ' ) ) || 'Home' ], [ 'Modified', fmtDate( r.modified ) ], [ 'Added', fmtDate( r.created ) ], [ 'Added by', r.uploader || '—' ] ];
	}
	return [
		[ 'Type', typeLabel( it ) ],
		[ 'Size', fmtSize( r.size ) + ' (' + Number( r.size ).toLocaleString() + ' bytes)' ],
		[ 'Location', r.path || ( fx.crumbs.map( ( c ) => c.name ).join( ' / ' ) ) || 'Home' ],
		[ 'Modified', fmtDate( r.modified ) ],
		[ 'Added', fmtDate( r.created ) ],
		[ 'Version', 'v' + r.version ],
		[ 'Added by', r.uploader || '—' ],
	];
} );
const save = async () => {
	error.value = '';
	const patch = { description: description.value, tags: tags.value };
	if ( isLink.value ) {
		delete patch.tags;
		if ( ! isWebUrl( address.value ) ) {
			error.value = 'Enter a web address that starts with http:// or https://';
			return;
		}
		if ( address.value.trim() !== one.value.row.link_url ) patch.link_url = address.value.trim();
	}
	busy.value = true;
	try {
		if ( one.value.type === 'file' ) await fx.updateFile( one.value.row, patch );
		else await fx.updateFolder( one.value.row, { description: description.value } );
		fx.toast( 'Saved.', 'success', 2000 );
		fx.closeDialog();
	} catch ( e ) {
		error.value = e.message;
	} finally {
		busy.value = false;
	}
};
</script>

<template>
	<Modal title="Properties" @close="fx.closeDialog()">
		<div class="fx-props__head">
			<FileIcon v-if="one" :type="one.type === 'folder' ? 'folder' : 'file'" :kind="iconKind( one.row )" :color="one.type === 'folder' ? fx.folderColor( one.row ) : ''" size="44" />
			<div class="fx-props__name">{{ one ? one.name : countLabel( items.length ) }}</div>
		</div>
		<dl class="fx-kv fx-kv--wide"><template v-for="r in rows" :key="r[ 0 ]"><dt>{{ r[ 0 ] }}</dt><dd>{{ r[ 1 ] }}</dd></template></dl>
		<div v-if="one && one.type !== 'trash'" class="fx-form">
			<label v-if="isLink" class="fx-field"><span>Web address</span><input v-model="address" type="url" maxlength="2000" :readonly="! editable" placeholder="https://" /></label>
			<label class="fx-field"><span>Description</span><textarea v-model="description" rows="3" maxlength="500" :readonly="! editable"></textarea></label>
			<label v-if="one.type === 'file' && ! isLink" class="fx-field"><span>Tags</span><input v-model="tags" type="text" maxlength="200" :readonly="! editable" placeholder="Separate with commas" /></label>
			<p v-if="error" class="fx-error" role="alert">{{ error }}</p>
		</div>
		<template #footer>
			<button type="button" class="fx-secondary" @click="fx.closeDialog()">{{ editable ? 'Cancel' : 'Close' }}</button>
			<button v-if="editable" type="button" class="fx-primary" :disabled="busy" @click="save">Save</button>
		</template>
	</Modal>
</template>
