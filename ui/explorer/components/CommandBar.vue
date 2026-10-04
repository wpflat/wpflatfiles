<script setup>
import { computed, inject } from 'vue';
import Icon from './Icon.vue';
import { countLabel } from '../util';

const fx = inject( 'fx' );

const menuAt = ( e, items ) => {
	const r = e.currentTarget.getBoundingClientRect();
	fx.openMenu( items, r.left, r.bottom + 4 );
};
const groups = computed( () => {
	const c = fx.can;
	const g = [];
	if ( fx.st.trash ) {
		g.push( { label: 'Recycle Bin', buttons: [
			{ id: 'restore', label: 'Restore', icon: 'restore', disabled: ! c.restore, run: () => fx.restoreTrash( fx.selItems ) },
			{ id: 'purge', label: 'Delete permanently', icon: 'trash', disabled: ! c.purge, run: () => fx.ask( 'confirm', { title: 'Delete permanently', message: 'Delete ' + countLabel( fx.selItems.length ) + ' for good? This cannot be undone.', confirm: 'Delete permanently', danger: true, run: () => fx.purgeTrash( fx.selItems ) } ) },
			{ id: 'empty', label: 'Empty Recycle Bin', icon: 'x', hidden: ! fx.cfg.manager, disabled: ! c.empty, run: () => fx.ask( 'confirm', { title: 'Empty Recycle Bin', message: 'Delete everything in the Recycle Bin for good? This cannot be undone.', confirm: 'Empty Recycle Bin', danger: true, run: () => fx.emptyBin() } ) },
		] } );
	} else {
		g.push( { label: 'New', buttons: [
			{ id: 'new', label: 'New folder', icon: 'folder-plus', disabled: ! c.newFolder, run: () => fx.ask( 'name', { mode: 'new' } ) },
			{ id: 'upload', label: 'Upload', icon: 'upload', disabled: ! c.upload, run: () => fx.pick() },
			{ id: 'link', label: 'Link', icon: 'link', hidden: ! c.link, run: () => fx.ask( 'link' ) },
			{ id: 'uploadf', label: 'Upload folder', icon: 'folder-up', disabled: ! c.uploadFolder, cls: 'fx-btn--opt', run: () => fx.pick( { folder: true } ) },
		] } );
		g.push( { label: 'Clipboard', buttons: [
			{ id: 'cut', label: 'Cut', icon: 'cut', disabled: ! c.cut, run: () => fx.cut() },
			{ id: 'copy', label: 'Copy', icon: 'copy', disabled: ! c.copy, run: () => fx.copy() },
			{ id: 'paste', label: 'Paste', icon: 'paste', disabled: ! c.paste, run: () => fx.paste() },
		] } );
		g.push( { label: 'Organize', buttons: [
			{ id: 'rename', label: 'Rename', icon: 'rename', disabled: ! c.rename, run: () => fx.ask( 'name', { mode: 'rename', item: fx.selItems[ 0 ] } ) },
			{ id: 'delete', label: 'Delete', icon: 'trash', disabled: ! c.remove, run: () => fx.confirmDelete( fx.selItems ) },
			{ id: 'share', label: 'Share', icon: 'share', disabled: ! c.share, run: () => fx.ask( 'share', { file: fx.selItems[ 0 ].row } ) },
			{ id: 'download', label: fx.selItems.length === 1 && fx.selItems[ 0 ].type === 'folder' ? 'Download as zip' : 'Download', icon: 'download', disabled: ! c.download, run: () => fx.download( fx.selItems ) },
			{ id: 'access', label: 'Access', icon: 'people', hidden: ! c.access, run: () => fx.ask( 'access', { folder: fx.selItems[ 0 ] ? fx.selItems[ 0 ].row : fx.st.folder } ) },
		] } );
		g.push( { label: 'View', buttons: [
			{ id: 'sort', label: 'Sort', icon: 'sort', menu: true, run: ( e ) => menuAt( e, fx.sortMenu() ) },
			{ id: 'view', label: 'View', icon: 'view', menu: true, run: ( e ) => menuAt( e, fx.viewMenu() ) },
		] } );
	}
	const show = [];
	if ( fx.st.narrow ) show.push( { id: 'select', label: 'Select', icon: 'select-all', pressed: fx.st.selectMode, run: () => ( fx.st.selectMode = ! fx.st.selectMode ) } );
	else show.push( { id: 'details', label: 'Details', icon: 'pane', pressed: fx.st.details, run: () => ( fx.st.details = ! fx.st.details ) } );
	if ( fx.cfg.theme_switch && Object.keys( fx.cfg.themes ).length > 1 ) show.push( { id: 'theme', label: 'Theme', icon: 'palette', menu: true, run: ( e ) => menuAt( e, fx.themeMenu() ) } );
	g.push( { label: 'Show', buttons: show, end: true } );
	return g;
} );
</script>

<template>
	<div class="fx-cmd" role="toolbar" aria-label="Commands">
		<div v-for="g in groups" :key="g.label" class="fx-cmd__group" :class="{ 'is-end': g.end }" role="group" :aria-label="g.label">
			<div class="fx-cmd__btns">
				<template v-for="b in g.buttons" :key="b.id">
					<button v-if="! b.hidden" type="button" class="fx-btn" :class="b.cls" :data-cmd="b.id" :disabled="b.disabled" :aria-label="b.label" :aria-pressed="b.pressed === undefined ? undefined : String( b.pressed )" :aria-haspopup="b.menu ? 'menu' : undefined" :title="b.label" @click="b.run( $event )">
						<Icon :name="b.icon" :size="18" /><span class="fx-btn__t">{{ b.label }}</span><Icon v-if="b.menu" class="fx-btn__chev" name="chev-d" :size="11" />
					</button>
				</template>
			</div>
			<div class="fx-cmd__label" aria-hidden="true">{{ g.label }}</div>
		</div>
	</div>
</template>
