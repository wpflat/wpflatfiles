<script setup>
import { computed, onBeforeUnmount, onMounted, provide, ref } from 'vue';
import AddressBar from './AddressBar.vue';
import CommandBar from './CommandBar.vue';
import DetailsPane from './DetailsPane.vue';
import ItemsView from './ItemsView.vue';
import Menu from './Menu.vue';
import MenuBar from './MenuBar.vue';
import NavPane from './NavPane.vue';
import StatusBar from './StatusBar.vue';
import Toasts from './Toasts.vue';
import UploadPanel from './UploadPanel.vue';
import Viewer from './Viewer.vue';
import Icon from './Icon.vue';
import NameDialog from '../dialogs/NameDialog.vue';
import ConfirmDialog from '../dialogs/ConfirmDialog.vue';
import PropsDialog from '../dialogs/PropsDialog.vue';
import VersionsDialog from '../dialogs/VersionsDialog.vue';
import ShareDialog from '../dialogs/ShareDialog.vue';
import AccessDialog from '../dialogs/AccessDialog.vue';
import LinkDialog from '../dialogs/LinkDialog.vue';
import { isFileDrag } from '../dnd';

const props = defineProps( { fx: { type: Object, required: true } } );
const fx = props.fx;
provide( 'fx', fx );

const root = ref( null );
const dialogs = { name: NameDialog, confirm: ConfirmDialog, props: PropsDialog, versions: VersionsDialog, share: ShareDialog, access: AccessDialog, link: LinkDialog };
const dialog = computed( () => ( fx.st.dialog ? dialogs[ fx.st.dialog.name ] : null ) );
const themeName = computed( () => fx.cfg.theme );

/* the size of the explorer itself (not the screen) decides between desktop and phone layouts */
let ro = null;
const measure = () => {
	const w = root.value ? root.value.clientWidth : 0;
	fx.setWidth( w || window.innerWidth || 1000 );
};
onMounted( () => {
	measure();
	if ( typeof ResizeObserver !== 'undefined' ) {
		ro = new ResizeObserver( measure );
		ro.observe( root.value );
	} else window.addEventListener( 'resize', measure );
	fx.init();
} );
onBeforeUnmount( () => {
	if ( ro ) ro.disconnect();
	window.removeEventListener( 'resize', measure );
} );

const typing = ( t ) => t && ( t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable );
const key = ( e ) => {
	if ( fx.st.dialog || fx.st.viewer !== null || fx.st.menu ) return;
	if ( typing( e.target ) ) return;
	if ( e.target.closest && e.target.closest( '.fx-nav' ) && ! ( e.ctrlKey || e.metaKey ) && ( e.key.startsWith( 'Arrow' ) || e.key === 'Home' || e.key === 'End' || e.key === 'Enter' || e.key === ' ' ) ) return;
	if ( fx.onKey( e ) ) e.preventDefault();
};

/* files dragged in from the desktop */
const over = ref( false );
let depth = 0;
const dEnter = ( e ) => {
	if ( ! isFileDrag( e ) ) return;
	depth++;
	over.value = true;
};
const dOver = ( e ) => {
	if ( ! isFileDrag( e ) ) return;
	e.preventDefault();
	e.dataTransfer.dropEffect = fx.can.upload ? 'copy' : 'none';
};
const dLeave = ( e ) => {
	if ( ! isFileDrag( e ) ) return;
	depth = Math.max( 0, depth - 1 );
	if ( ! depth ) over.value = false;
};
const dDrop = ( e ) => {
	if ( ! isFileDrag( e ) ) return;
	e.preventDefault();
	depth = 0;
	over.value = false;
	fx.st.dropOn = null;
	fx.addDropped( e.dataTransfer, fx.st.folderId );
};
const closeNav = () => ( fx.st.navOpen = false );
</script>

<template>
	<div
		ref="root"
		class="fx-root"
		:class="[ 'fx-' + fx.st.mode, fx.st.narrow && 'fx-narrow', fx.st.details && ! fx.st.narrow && 'fx-has-pane', over && 'is-over', fx.st.selectMode && 'is-selecting' ]"
		:data-theme="themeName"
		:data-view="fx.st.view"
		@keydown="key"
		@dragenter="dEnter"
		@dragover="dOver"
		@dragleave="dLeave"
		@drop="dDrop"
	>
		<MenuBar />
		<CommandBar />
		<AddressBar />
		<div class="fx-body">
			<NavPane />
			<main class="fx-main"><ItemsView /></main>
			<DetailsPane v-if="fx.st.details && ! fx.st.narrow" />
		</div>
		<StatusBar />
		<UploadPanel />
		<Menu v-if="fx.st.menu" :key="fx.st.menu.x + ':' + fx.st.menu.y + ':' + fx.st.menu.items.length" :menu="fx.st.menu" />
		<component :is="dialog" v-if="dialog" v-bind="fx.st.dialog.props" />
		<Viewer v-if="fx.st.viewer !== null" />
		<Toasts />
		<div v-if="over" class="fx-drop" aria-hidden="true">
			<div class="fx-drop__box">
				<Icon name="upload" :size="34" />
				<div class="fx-drop__t">{{ fx.can.upload ? 'Drop to upload to “' + fx.title + '”' : 'You cannot add files here' }}</div>
			</div>
		</div>
	</div>
</template>
