<script setup>
import { inject } from 'vue';

/** The classic theme's menu bar (File Edit View Tools): the same actions as the command bar, in the old place. */
const fx = inject( 'fx' );

const file = () => {
	const c = fx.can;
	return [
		{ label: 'New folder', icon: 'folder-plus', disabled: ! c.newFolder, action: () => fx.ask( 'name', { mode: 'new' } ) },
		{ label: 'Upload files…', icon: 'upload', disabled: ! c.upload, action: () => fx.pick() },
		{ label: 'Upload folder…', icon: 'folder-up', disabled: ! c.uploadFolder, action: () => fx.pick( { folder: true } ) },
		...( c.link ? [ { label: 'Link…', icon: 'link', action: () => fx.ask( 'link' ) } ] : [] ),
		{ sep: true },
		{ label: 'Rename', icon: 'rename', hint: 'F2', disabled: ! c.rename, action: () => fx.ask( 'name', { mode: 'rename', item: fx.selItems[ 0 ] } ) },
		{ label: 'Delete', icon: 'trash', hint: 'Del', disabled: ! c.remove, action: () => fx.confirmDelete( fx.selItems ) },
		{ label: 'Share link…', icon: 'share', disabled: ! c.share, action: () => fx.ask( 'share', { file: fx.selItems[ 0 ].row } ) },
		{ label: 'Download', icon: 'download', disabled: ! c.download, action: () => fx.download( fx.selItems ) },
		{ sep: true },
		{ label: 'Properties', icon: 'info', disabled: ! fx.selItems.length, action: () => fx.ask( 'props', { items: fx.selItems } ) },
	];
};
const edit = () => [
	{ label: 'Cut', icon: 'cut', hint: 'Ctrl+X', disabled: ! fx.can.cut, action: () => fx.cut() },
	{ label: 'Copy', icon: 'copy', hint: 'Ctrl+C', disabled: ! fx.can.copy, action: () => fx.copy() },
	{ label: 'Paste', icon: 'paste', hint: 'Ctrl+V', disabled: ! fx.can.paste, action: () => fx.paste() },
	{ sep: true },
	{ label: 'Select all', icon: 'select-all', hint: 'Ctrl+A', action: () => fx.selectAll() },
	{ label: 'Invert selection', action: () => fx.invertSelection() },
];
const view = () => [
	...fx.viewMenu(),
	{ sep: true },
	{ label: 'Sort by', icon: 'sort', children: fx.sortMenu() },
	{ label: 'Details pane', checked: fx.st.details, action: () => ( fx.st.details = ! fx.st.details ) },
	{ sep: true },
	{ label: 'Refresh', icon: 'refresh', hint: 'F5', action: () => fx.refresh() },
];
const tools = () => [
	...( fx.cfg.theme_switch ? [ { label: 'Theme', icon: 'palette', children: fx.themeMenu() } ] : [] ),
	{ label: 'Recycle Bin', icon: 'trash', action: () => fx.openTrash() },
	{ label: 'Home', icon: 'home', action: () => fx.goHome() },
];
const menus = [ { label: 'File', build: file }, { label: 'Edit', build: edit }, { label: 'View', build: view }, { label: 'Tools', build: tools } ];
const open = ( e, m ) => {
	const r = e.currentTarget.getBoundingClientRect();
	fx.openMenu( m.build(), r.left, r.bottom );
};
</script>

<template>
	<div class="fx-menubar" role="menubar" aria-label="Menu">
		<button v-for="m in menus" :key="m.label" type="button" class="fx-menubar__item" role="menuitem" aria-haspopup="menu" @click="open( $event, m )">{{ m.label }}</button>
	</div>
</template>
