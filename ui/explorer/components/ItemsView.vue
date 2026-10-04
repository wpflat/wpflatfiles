<script setup>
import { computed, inject, onBeforeUnmount, onMounted, ref } from 'vue';
import Icon from './Icon.vue';
import FileIcon from './FileIcon.vue';
import { dropTarget, TYPE } from '../dnd';
import { countLabel, fmtDate, fmtSize, iconKind, kindOfName, typeLabel } from '../util';

const fx = inject( 'fx' );
const grid = ref( null );
const scroller = ref( null );
const band = ref( null );
let longPress = null;
let longFired = 0;

onMounted( () => {
	fx.refs.items = grid.value;
} );
onBeforeUnmount( () => {
	if ( fx.refs.items === grid.value ) fx.refs.items = null;
} );

const trash = computed( () => fx.st.trash );
const view = computed( () => ( fx.st.trash ? 'details' : fx.st.view ) );
const cols = computed( () =>
	trash.value
		? [ { id: 'name', label: 'Name' }, { id: 'date', label: 'Date deleted' }, { id: 'where', label: 'Original location' }, { id: 'size', label: 'Size' } ]
		: [ { id: 'name', label: 'Name' }, { id: 'date', label: 'Date modified' }, { id: 'type', label: 'Type' }, { id: 'size', label: 'Size' } ]
);
const sortable = ( id ) => ! trash.value && [ 'name', 'date', 'type', 'size' ].includes( id );

const kindOf = ( it ) => ( it.type === 'file' ? iconKind( it.row ) : it.type === 'trash' ? kindOfName( it.name ) : 'other' );
const isFolder = ( it ) => it.type === 'folder' || ( it.type === 'trash' && it.row.type === 'folder' );
const color = ( it ) => ( it.type === 'folder' ? fx.folderColor( it.row ) : '' );
const badge = ( it ) => ( it.type === 'folder' && fx.showBadge( it.row ) ? 'people' : '' );
const sizeOf = ( it ) => ( it.type === 'file' ? it.row.is_link ? '' : fmtSize( it.row.size ) : it.type === 'trash' ? ( it.row.type === 'file' ? fmtSize( it.row.size ) : '' ) : '' );
const dateOf = ( it ) => fmtDate( it.type === 'trash' ? it.row.deleted_at : it.row.modified );
const whereOf = ( it ) => {
	const f = fx.treeById[ it.row.parent_id ];
	return f ? f.name : it.row.parent_id ? 'Folder' : 'Home';
};
const sub = ( it ) => ( it.row.path ? 'in ' + it.row.path : '' );
const detailLine = ( it ) => {
	if ( it.type === 'folder' ) return countLabel( it.row.items || 0 );
	return [ typeLabel( it ), sizeOf( it ) ].filter( Boolean ).join( ' · ' );
};
const ariaSort = ( id ) => ( fx.st.sort.key === id ? ( fx.st.sort.dir === 'asc' ? 'ascending' : 'descending' ) : 'none' );

const cls = ( it ) => ( {
	'is-selected': fx.st.sel.includes( it.key ),
	'is-cursor': fx.st.cursor === it.key,
	'is-cut': fx.isCut( it.key ),
	'is-drop': it.type === 'folder' && fx.st.dropOn === it.id,
	'is-folder': isFolder( it ),
} );

let suppress = false;
const click = ( e, it ) => {
	if ( suppress ) {
		suppress = false;
		return;
	}
	const mod = e.ctrlKey || e.metaKey;
	if ( fx.st.narrow && ! mod && ! e.shiftKey ) {
		if ( fx.st.selectMode || trash.value ) fx.select( it, { ctrl: true } );
		else {
			fx.select( it );
			fx.openItem( it );
		}
		return;
	}
	if ( fx.st.selectMode ) fx.select( it, { ctrl: true } );
	else fx.select( it, { ctrl: mod, shift: e.shiftKey } );
	fx.focusItems();
};
const dbl = ( it ) => {
	if ( ! fx.st.narrow ) fx.openItem( it );
};
const ctx = ( e, it ) => {
	e.preventDefault();
	e.stopPropagation();
	if ( Date.now() - longFired < 900 ) return;
	fx.openContext( e.clientX, e.clientY, it );
};
const more = ( e, it ) => {
	const r = e.currentTarget.getBoundingClientRect();
	fx.openContext( r.left - 160, r.bottom, it );
};
const pdown = ( e, it ) => {
	if ( e.pointerType === 'mouse' ) return;
	clearTimeout( longPress );
	const x = e.clientX;
	const y = e.clientY;
	longPress = setTimeout( () => {
		longFired = Date.now();
		suppress = true;
		fx.openContext( x, y, it );
		if ( navigator.vibrate ) navigator.vibrate( 8 );
	}, 520 );
	const cancel = ( ev ) => {
		if ( ev.type === 'pointermove' && Math.hypot( ev.clientX - x, ev.clientY - y ) < 10 ) return;
		clearTimeout( longPress );
		window.removeEventListener( 'pointermove', cancel );
		window.removeEventListener( 'pointerup', cancel );
		window.removeEventListener( 'pointercancel', cancel );
		setTimeout( () => ( suppress = false ), 50 );
	};
	window.addEventListener( 'pointermove', cancel );
	window.addEventListener( 'pointerup', cancel );
	window.addEventListener( 'pointercancel', cancel );
};

const draggable = ( it ) => ! fx.st.narrow && ! trash.value && fx.canEditItem( it );
const dstart = ( e, it ) => {
	if ( ! fx.st.sel.includes( it.key ) ) fx.select( it );
	fx.st.dragKeys = [ ...fx.st.sel ];
	e.dataTransfer.effectAllowed = 'copyMove';
	e.dataTransfer.setData( TYPE, JSON.stringify( fx.st.dragKeys ) );
	e.dataTransfer.setData( 'text/plain', fx.selItems.map( ( i ) => i.name ).join( '\n' ) );
};
const dend = () => {
	fx.st.dragKeys = [];
	fx.st.dropOn = null;
};
const bind = ( it ) => ( {
	'data-key': it.key,
	role: 'row',
	'aria-selected': String( fx.st.sel.includes( it.key ) ),
	'aria-label': it.name,
	draggable: draggable( it ) ? 'true' : undefined,
	onClick: ( e ) => click( e, it ),
	onDblclick: () => dbl( it ),
	onContextmenu: ( e ) => ctx( e, it ),
	onPointerdown: ( e ) => pdown( e, it ),
	onDragstart: ( e ) => dstart( e, it ),
	onDragend: dend,
	...( it.type === 'folder' ? dropTarget( fx, it.id ) : {} ),
} );

/* rubber band */
let drag = null;
const bgDown = ( e ) => {
	if ( e.pointerType !== 'mouse' || e.button !== 0 ) return;
	if ( e.target.closest( '.fx-item, .fx-head, button' ) ) return;
	const mod = e.ctrlKey || e.metaKey;
	drag = { x0: e.clientX, y0: e.clientY, base: mod ? [ ...fx.st.sel ] : [], moved: false };
	if ( ! mod ) fx.st.sel = [];
	fx.focusItems();
	window.addEventListener( 'pointermove', bgMove );
	window.addEventListener( 'pointerup', bgUp );
};
const bgMove = ( e ) => {
	if ( ! drag ) return;
	if ( ! drag.moved && Math.hypot( e.clientX - drag.x0, e.clientY - drag.y0 ) < 5 ) return;
	drag.moved = true;
	const host = scroller.value.getBoundingClientRect();
	const l = Math.min( drag.x0, e.clientX );
	const r = Math.max( drag.x0, e.clientX );
	const t = Math.min( drag.y0, e.clientY );
	const b = Math.max( drag.y0, e.clientY );
	band.value = { left: l - host.left + scroller.value.scrollLeft, top: t - host.top + scroller.value.scrollTop, width: r - l, height: b - t };
	const hits = [ ...grid.value.querySelectorAll( '[data-key]' ) ]
		.filter( ( el ) => {
			const q = el.getBoundingClientRect();
			return ! ( q.right < l || q.left > r || q.bottom < t || q.top > b );
		} )
		.map( ( el ) => el.dataset.key );
	fx.st.sel = [ ...new Set( [ ...drag.base, ...hits ] ) ];
};
const bgUp = () => {
	window.removeEventListener( 'pointermove', bgMove );
	window.removeEventListener( 'pointerup', bgUp );
	drag = null;
	band.value = null;
};
const bgContext = ( e ) => {
	if ( e.target.closest( '.fx-item' ) ) return;
	e.preventDefault();
	fx.openContext( e.clientX, e.clientY, null );
};

const empty = computed( () => {
	if ( trash.value ) return { icon: 'trash', title: 'The Recycle Bin is empty', text: fx.st.trashData.days ? 'Deleted items stay here for ' + fx.st.trashData.days + ' days so you can get them back.' : 'Deleted items stay here so you can get them back.' };
	if ( fx.searching ) return { icon: 'search', title: 'No results for “' + fx.st.query + '”', text: 'Check the spelling or try a shorter word.' };
	if ( fx.st.folderId === 0 ) return { icon: 'folder', title: 'No folders yet', text: fx.cfg.manager ? 'Create the first folder with New folder.' : 'Folders that are shared with you will show up here.' };
	if ( fx.can.upload ) return { icon: 'upload', title: 'This folder is empty', text: fx.st.narrow ? 'Tap Upload to add files.' : 'Drag files here, or use Upload.' };
	return { icon: 'folder', title: 'This folder is empty', text: '' };
} );
const showEmpty = computed( () => fx.st.loaded && ! fx.st.loading && ! fx.st.error && ! fx.items.length );
const skeleton = computed( () => fx.st.loading && ! fx.items.length );
</script>

<template>
	<div ref="scroller" class="fx-scroll" :class="'fx-v-' + view" @pointerdown="bgDown" @contextmenu="bgContext">
		<div v-if="view === 'details'" class="fx-head" role="row">
			<template v-for="c in cols" :key="c.id">
				<button v-if="sortable( c.id )" type="button" class="fx-th" :class="'fx-c-' + c.id" role="columnheader" :aria-sort="ariaSort( c.id )" @click="fx.setSort( c.id )">
					<span>{{ c.label }}</span><Icon v-if="fx.st.sort.key === c.id" class="fx-th__dir" :name="fx.st.sort.dir === 'asc' ? 'chev-d' : 'chev-d'" :size="11" :style="{ transform: fx.st.sort.dir === 'asc' ? 'rotate(180deg)' : '' }" />
				</button>
				<div v-else class="fx-th" :class="'fx-c-' + c.id" role="columnheader"><span>{{ c.label }}</span></div>
			</template>
		</div>
		<div ref="grid" class="fx-grid" role="grid" tabindex="0" :aria-label="fx.title" :aria-multiselectable="'true'" :aria-busy="String( fx.st.loading )">
			<template v-if="skeleton">
				<div v-for="n in 9" :key="'s' + n" class="fx-skel" aria-hidden="true"><span class="fx-skel__ic"></span><span class="fx-skel__t" :style="{ width: 40 + ( n * 37 ) % 40 + '%' }"></span></div>
			</template>
			<template v-else-if="fx.st.error && ! fx.items.length">
				<div class="fx-empty" role="alert">
					<Icon name="alert" :size="40" />
					<div class="fx-empty__t">We could not load this</div>
					<div class="fx-empty__s">{{ fx.st.error }}</div>
					<button type="button" class="fx-primary" @click="fx.refresh()">Try again</button>
				</div>
			</template>
			<template v-else>
				<div v-for="it in fx.items" :key="it.key" class="fx-item" :class="cls( it )" v-bind="bind( it )">
					<template v-if="view === 'details'">
						<div class="fx-cell fx-c-name" role="gridcell">
							<FileIcon :type="isFolder( it ) ? 'folder' : 'file'" :kind="kindOf( it )" :color="color( it )" size="var(--fx-ico-row)" :badge="badge( it )" />
							<span class="fx-name"><span class="fx-name__t">{{ it.name }}</span><span v-if="sub( it )" class="fx-name__s">{{ sub( it ) }}</span></span>
							<button type="button" class="fx-more" :aria-label="'Actions for ' + it.name" @click.stop="more( $event, it )"><Icon name="more" :size="16" /></button>
						</div>
						<div class="fx-cell fx-c-date" role="gridcell">{{ dateOf( it ) }}</div>
						<div v-if="! trash" class="fx-cell fx-c-type" role="gridcell">{{ typeLabel( it ) }}</div>
						<div v-else class="fx-cell fx-c-where" role="gridcell">{{ whereOf( it ) }}</div>
						<div class="fx-cell fx-c-size" role="gridcell">{{ sizeOf( it ) }}</div>
						<div class="fx-cell fx-c-meta" role="gridcell">{{ [ dateOf( it ), sizeOf( it ) ].filter( Boolean ).join( ' · ' ) }}</div>
					</template>
					<template v-else-if="view === 'list'">
						<div class="fx-cell" role="gridcell">
							<FileIcon :type="isFolder( it ) ? 'folder' : 'file'" :kind="kindOf( it )" :color="color( it )" size="var(--fx-ico-row)" :badge="badge( it )" />
							<span class="fx-name"><span class="fx-name__t">{{ it.name }}</span></span>
							<button type="button" class="fx-more" :aria-label="'Actions for ' + it.name" @click.stop="more( $event, it )"><Icon name="more" :size="16" /></button>
						</div>
					</template>
					<template v-else-if="view === 'tiles'">
						<div class="fx-cell" role="gridcell">
							<FileIcon :type="isFolder( it ) ? 'folder' : 'file'" :kind="kindOf( it )" :color="color( it )" :thumb="it.type === 'file' ? it.row.thumb_url : ''" size="var(--fx-ico-tile)" :badge="badge( it )" />
							<span class="fx-name"><span class="fx-name__t">{{ it.name }}</span><span class="fx-name__s">{{ detailLine( it ) }}</span><span v-if="sub( it )" class="fx-name__s">{{ sub( it ) }}</span></span>
							<button type="button" class="fx-more" :aria-label="'Actions for ' + it.name" @click.stop="more( $event, it )"><Icon name="more" :size="16" /></button>
						</div>
					</template>
					<template v-else>
						<div class="fx-cell" role="gridcell">
							<FileIcon :type="isFolder( it ) ? 'folder' : 'file'" :kind="kindOf( it )" :color="color( it )" :thumb="it.type === 'file' ? it.row.thumb_url : ''" size="var(--fx-ico-large)" :badge="badge( it )" />
							<span class="fx-name"><span class="fx-name__t">{{ it.name }}</span></span>
							<button type="button" class="fx-more" :aria-label="'Actions for ' + it.name" @click.stop="more( $event, it )"><Icon name="more" :size="16" /></button>
						</div>
					</template>
				</div>
			</template>
		</div>
		<div v-if="showEmpty" class="fx-empty">
			<Icon :name="empty.icon" :size="44" />
			<div class="fx-empty__t">{{ empty.title }}</div>
			<div v-if="empty.text" class="fx-empty__s">{{ empty.text }}</div>
		</div>
		<div v-if="band" class="fx-band" :style="{ left: band.left + 'px', top: band.top + 'px', width: band.width + 'px', height: band.height + 'px' }"></div>
	</div>
</template>
