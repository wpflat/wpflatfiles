<script setup>
import { computed, inject, ref, watch } from 'vue';
import Icon from './Icon.vue';
import FileIcon from './FileIcon.vue';
import Preview from './Preview.vue';
import { countLabel, fmtDate, fmtSize, typeLabel } from '../util';

const fx = inject( 'fx' );
const one = computed( () => ( fx.selItems.length === 1 ? fx.selItems[ 0 ] : null ) );
const edit = ref( { description: '', tags: '', dirty: false, saving: false } );
const editable = computed( () => !! one.value && fx.canEditItem( one.value ) );
watch(
	() => one.value && one.value.key + ':' + ( one.value.row.description || '' ) + ':' + ( one.value.row.tags || '' ),
	() => {
		edit.value = { description: one.value ? one.value.row.description || '' : '', tags: one.value && one.value.type === 'file' ? one.value.row.tags || '' : '', dirty: false, saving: false };
	},
	{ immediate: true }
);
const save = async () => {
	const it = one.value;
	if ( ! it ) return;
	edit.value.saving = true;
	try {
		if ( it.type === 'file' ) await fx.updateFile( it.row, { description: edit.value.description, tags: edit.value.tags } );
		else await fx.updateFolder( it.row, { description: edit.value.description } );
		edit.value.dirty = false;
		fx.toast( 'Saved.', 'success', 2000 );
	} catch ( e ) {
		fx.fail( e );
	} finally {
		edit.value.saving = false;
	}
};
const rows = computed( () => {
	const it = one.value;
	if ( ! it ) return [];
	const r = it.row;
	if ( it.type === 'trash' ) return [ [ 'Deleted', fmtDate( r.deleted_at ) ], [ 'Size', r.type === 'file' ? fmtSize( r.size ) : '' ] ];
	if ( it.type === 'folder' ) return [ [ 'Type', 'File folder' ], [ 'Contains', countLabel( r.items || 0 ) ], [ 'Modified', fmtDate( r.modified ) ], [ 'Access', r.inherit ? 'Same as the folder above' : 'Has its own rules' ] ];
	if ( r.is_link ) return [ [ 'Type', 'Link' ], [ 'Address', r.link_url ], [ 'Modified', fmtDate( r.modified ) ], [ 'Added', fmtDate( r.created ) ], [ 'Added by', r.uploader || '' ], ...( r.path ? [ [ 'Location', r.path ] ] : [] ) ].filter( ( x ) => x[ 1 ] );
	return [
		[ 'Type', typeLabel( it ) ],
		[ 'Size', fmtSize( r.size ) ],
		[ 'Modified', fmtDate( r.modified ) ],
		[ 'Added', fmtDate( r.created ) ],
		[ 'Version', 'v' + r.version ],
		[ 'Added by', r.uploader || '' ],
		...( r.path ? [ [ 'Location', r.path ] ] : [] ),
	].filter( ( x ) => x[ 1 ] );
} );
const summary = computed( () => {
	if ( fx.st.trash ) return { title: 'Recycle Bin', sub: countLabel( fx.items.length ) };
	if ( fx.st.folder ) return { title: fx.st.folder.name, sub: fx.st.folder.description || countLabel( fx.items.length ) };
	return { title: 'Home', sub: countLabel( fx.items.length ) };
} );
</script>

<template>
	<aside class="fx-pane" aria-label="Details">
		<div class="fx-pane__head">
			<span>{{ one ? 'Details' : 'Folder' }}</span>
			<button type="button" class="fx-iconbtn" aria-label="Hide details" @click="fx.st.details = false"><Icon name="x" :size="14" /></button>
		</div>
		<div v-if="one" class="fx-pane__body">
			<Preview v-if="one.type === 'file'" :file="one.row" />
			<div v-else class="fx-pane__big"><FileIcon :type="one.type === 'trash' && one.row.type === 'file' ? 'file' : 'folder'" :color="one.type === 'folder' ? fx.folderColor( one.row ) : ''" size="64" /></div>
			<h3 class="fx-pane__name">{{ one.name }}</h3>
			<dl class="fx-kv">
				<template v-for="r in rows" :key="r[ 0 ]"><dt>{{ r[ 0 ] }}</dt><dd>{{ r[ 1 ] }}</dd></template>
			</dl>
			<div v-if="one.type !== 'trash'" class="fx-pane__fields">
				<template v-if="editable">
					<label class="fx-field"><span>Description</span><textarea v-model="edit.description" rows="3" maxlength="500" placeholder="Add a short note" @input="edit.dirty = true"></textarea></label>
					<label v-if="one.type === 'file'" class="fx-field"><span>Tags</span><input v-model="edit.tags" type="text" maxlength="200" placeholder="homework, term 1" @input="edit.dirty = true" /></label>
					<button v-if="edit.dirty" type="button" class="fx-primary" :disabled="edit.saving" @click="save">Save</button>
				</template>
				<template v-else>
					<p v-if="one.row.description" class="fx-pane__desc">{{ one.row.description }}</p>
					<p v-if="one.type === 'file' && one.row.tags" class="fx-tags"><span v-for="t in one.row.tags.split( ',' ).filter( ( x ) => x.trim() )" :key="t" class="fx-tag">{{ t.trim() }}</span></p>
				</template>
			</div>
			<div v-if="one.type === 'file'" class="fx-pane__actions">
				<button v-if="! one.row.is_link" type="button" class="fx-secondary" @click="fx.download( [ one ] )"><Icon name="download" :size="15" /> Download</button>
				<button v-if="! one.row.is_link" type="button" class="fx-secondary" @click="fx.preview( one )"><Icon name="eye" :size="15" /> Open</button>
				<button v-if="! one.row.is_link" type="button" class="fx-secondary" @click="fx.ask( 'versions', { file: one.row } )"><Icon name="history" :size="15" /> History</button>
			</div>
			<div v-else-if="one.type === 'folder' && ( fx.can.access || one.row.zip_url )" class="fx-pane__actions">
				<button v-if="one.row.zip_url" type="button" class="fx-secondary" @click="fx.download( [ one ] )"><Icon name="download" :size="15" /> Download as zip</button>
				<button v-if="fx.can.access" type="button" class="fx-secondary" @click="fx.ask( 'access', { folder: one.row } )"><Icon name="people" :size="15" /> Access</button>
			</div>
		</div>
		<div v-else-if="fx.selItems.length > 1" class="fx-pane__body">
			<div class="fx-pane__big"><Icon name="copy" :size="48" /></div>
			<h3 class="fx-pane__name">{{ countLabel( fx.selItems.length ) }} selected</h3>
			<dl class="fx-kv"><template v-if="fx.selSize"><dt>Total size</dt><dd>{{ fmtSize( fx.selSize ) }}</dd></template></dl>
		</div>
		<div v-else class="fx-pane__body">
			<div class="fx-pane__big"><FileIcon v-if="! fx.st.trash" type="folder" :color="fx.st.folder ? fx.folderColor( fx.st.folder ) : ''" size="64" /><Icon v-else name="trash" :size="48" /></div>
			<h3 class="fx-pane__name">{{ summary.title }}</h3>
			<p class="fx-pane__desc">{{ summary.sub }}</p>
			<p class="fx-pane__hint">Select a file to see a preview and its details.</p>
		</div>
	</aside>
</template>
