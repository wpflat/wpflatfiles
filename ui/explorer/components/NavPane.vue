<script setup>
import { computed, inject, nextTick } from 'vue';
import Icon from './Icon.vue';
import FileIcon from './FileIcon.vue';
import { dropTarget } from '../dnd';

const fx = inject( 'fx' );

/** The flat list of focusable rows: Home, every visible tree row, then the Recycle Bin. */
const rows = computed( () => [
	{ key: 'home', kind: 'home' },
	...fx.treeRows.map( ( r ) => ( { key: String( r.id ), kind: 'folder', ...r } ) ),
	{ key: 'trash', kind: 'trash' },
] );
const current = computed( () => ( fx.st.trash ? 'trash' : fx.st.query ? '' : fx.st.folderId ? String( fx.st.folderId ) : 'home' ) );
const focusKey = computed( () => fx.st.treeFocus || current.value || 'home' );

const focusRow = async ( key ) => {
	fx.st.treeFocus = key;
	await nextTick();
	const el = document.querySelector( '.fx-nav [data-tkey="' + key + '"]' );
	if ( el ) el.focus();
};
const activate = ( r ) => {
	if ( r.kind === 'home' ) fx.goHome();
	else if ( r.kind === 'trash' ) fx.openTrash();
	else fx.openFolder( r.id );
};
const key = ( e ) => {
	const list = rows.value;
	const i = list.findIndex( ( r ) => r.key === focusKey.value );
	const r = list[ i ];
	if ( ! r ) return;
	if ( e.key === 'ArrowDown' ) focusRow( list[ Math.min( list.length - 1, i + 1 ) ].key );
	else if ( e.key === 'ArrowUp' ) focusRow( list[ Math.max( 0, i - 1 ) ].key );
	else if ( e.key === 'Home' ) focusRow( list[ 0 ].key );
	else if ( e.key === 'End' ) focusRow( list[ list.length - 1 ].key );
	else if ( e.key === 'ArrowRight' && r.kind === 'folder' ) {
		if ( r.hasChildren && ! r.open ) fx.toggleNode( r.id, true );
		else if ( r.open ) focusRow( list[ i + 1 ].key );
	} else if ( e.key === 'ArrowLeft' && r.kind === 'folder' ) {
		if ( r.open ) fx.toggleNode( r.id, false );
		else {
			const parent = list.slice( 0, i ).reverse().find( ( x ) => x.kind === 'folder' && x.depth === r.depth - 1 );
			if ( parent ) focusRow( parent.key );
		}
	} else if ( e.key === 'Enter' || e.key === ' ' ) activate( r );
	else return;
	e.preventDefault();
	e.stopPropagation();
};
</script>

<template>
	<aside class="fx-nav" :class="{ 'is-open': fx.st.navOpen }" aria-label="Folders">
		<div class="fx-nav__head">
			<span class="fx-nav__cap">Folders</span>
			<button v-if="fx.st.narrow" type="button" class="fx-iconbtn" aria-label="Close folders" @click="fx.st.navOpen = false"><Icon name="x" :size="16" /></button>
		</div>
		<div class="fx-nav__scroll" role="tree" aria-label="Folders" @keydown="key">
			<div class="fx-tn fx-tn--home" :class="{ 'is-current': current === 'home' }" role="treeitem" :aria-selected="String( current === 'home' )" :tabindex="focusKey === 'home' ? 0 : -1" data-tkey="home" style="--d: 0" @click="fx.goHome()" @focus="fx.st.treeFocus = 'home'">
				<span class="fx-tn__tw"></span><Icon name="home" :size="17" /><span class="fx-tn__t">Home</span>
			</div>
			<div
				v-for="r in fx.treeRows"
				:key="r.id"
				class="fx-tn"
				:class="{ 'is-current': current === String( r.id ), 'is-drop': fx.st.dropOn === r.id }"
				role="treeitem"
				:aria-level="r.depth"
				:aria-expanded="r.hasChildren ? String( r.open ) : undefined"
				:aria-selected="String( current === String( r.id ) )"
				:tabindex="focusKey === String( r.id ) ? 0 : -1"
				:data-tkey="r.id"
				:style="{ '--d': r.depth }"
				v-bind="dropTarget( fx, r.id )"
				@click="fx.openFolder( r.id )"
				@focus="fx.st.treeFocus = String( r.id )"
			>
				<button v-if="r.hasChildren" type="button" class="fx-tn__tw" tabindex="-1" :aria-label="( r.open ? 'Collapse ' : 'Expand ' ) + r.folder.name" @click.stop="fx.toggleNode( r.id )"><Icon name="chev-r" :size="12" :class="{ 'is-open': r.open }" /></button>
				<span v-else class="fx-tn__tw"></span>
				<FileIcon type="folder" :color="fx.folderColor( r.folder )" size="var(--fx-ico-nav)" :badge="fx.showBadge( r.folder ) ? 'people' : ''" />
				<span class="fx-tn__t">{{ r.folder.name }}</span>
			</div>
			<div class="fx-nav__sep"></div>
			<div class="fx-tn fx-tn--bin" :class="{ 'is-current': current === 'trash' }" role="treeitem" :aria-selected="String( current === 'trash' )" :tabindex="focusKey === 'trash' ? 0 : -1" data-tkey="trash" style="--d: 0" @click="fx.openTrash()" @focus="fx.st.treeFocus = 'trash'">
				<span class="fx-tn__tw"></span><Icon name="trash" :size="17" /><span class="fx-tn__t">Recycle Bin</span>
			</div>
		</div>
	</aside>
	<div v-if="fx.st.narrow && fx.st.navOpen" class="fx-nav__scrim" @click="fx.st.navOpen = false"></div>
</template>
