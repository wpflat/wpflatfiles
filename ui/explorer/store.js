import { computed, reactive } from 'vue';
import { createApi } from './api';
import { createUploader } from './uploads';
import { CLASSROOM_COLORS, FOLDER_COLORS, collator, countLabel, extOf, fmtSize } from './util';

const VIEWS = [
	{ id: 'details', label: 'Details', icon: 'view-details' },
	{ id: 'list', label: 'List', icon: 'view-list' },
	{ id: 'tiles', label: 'Tiles', icon: 'view-tiles' },
	{ id: 'icons', label: 'Large icons', icon: 'view-icons' },
];
export const SORTS = [
	{ id: 'name', label: 'Name' },
	{ id: 'date', label: 'Date modified' },
	{ id: 'type', label: 'Type' },
	{ id: 'size', label: 'Size' },
];
const NARROW = 700;
const refs = { items: null };

/**
 * Everything the explorer knows and does lives here: one store per mounted explorer, handed to the components with
 * provide/inject. Components stay thin (they draw state and call these functions).
 */
export function createStore( boot ) {
	const api = createApi( boot );
	const cfg = reactive( {
		themes: {},
		theme: 'win11',
		default_theme: 'win11',
		theme_switch: false,
		view: 'details',
		max_mb: 500,
		chunk_size: 4194304,
		users_can_upload: true,
		staff: false,
		manager: false,
		simulated: false,
		user: '',
		allowed: [],
		words: {},
		...( boot.config || {} ),
	} );

	const st = reactive( {
		mode: boot.mode || 'portal',
		brand: boot.brand || '',
		folderId: Number( boot.folder ) || 0,
		query: '',
		searchText: '',
		trash: false,
		folder: null,
		can: { upload: false, edit: false, manage: false, create_folder: false },
		folders: [],
		files: [],
		loading: true,
		loaded: false,
		error: '',
		tree: { folders: [], roots: [] },
		expanded: {},
		trashData: { items: [], days: 0 },
		sort: { key: 'name', dir: 'asc' },
		view: [ 'details', 'list', 'tiles', 'icons' ].includes( cfg.view ) ? cfg.view : 'details',
		sel: [],
		anchor: null,
		cursor: null,
		selectMode: false,
		clip: null,
		back: [],
		fwd: [],
		navOpen: false,
		details: true,
		narrow: false,
		width: 1000,
		menu: null,
		dialog: null,
		viewer: null,
		toasts: [],
		dragKeys: [],
		dropOn: null,
		treeFocus: null,
	} );

	let reqSeq = 0;
	let toastSeq = 0;
	let reloadTimer = null;

	/* ───────────── words (the owner's names for clients and groups) ───────────── */
	const word = ( key, plural = false, lower = false ) => {
		const w = ( cfg.words || {} )[ key ];
		const out = w ? ( plural ? w.p : w.s ) : key.charAt( 0 ).toUpperCase() + key.slice( 1 ) + ( plural ? 's' : '' );
		return lower ? String( out ).toLowerCase() : out;
	};

	const toast = ( message, kind = 'info', ms = 5000 ) => {
		const t = { id: ++toastSeq, message, kind };
		st.toasts.push( t );
		if ( ms ) setTimeout( () => dismissToast( t.id ), ms );
		return t.id;
	};
	const dismissToast = ( id ) => {
		const i = st.toasts.findIndex( ( t ) => t.id === id );
		if ( i >= 0 ) st.toasts.splice( i, 1 );
	};
	const fail = ( e ) => toast( ( e && e.message ) || 'Something went wrong.', 'error', 7000 );

	/* ───────────── the tree ───────────── */
	const treeById = computed( () => {
		const m = {};
		st.tree.folders.forEach( ( f ) => ( m[ f.id ] = f ) );
		return m;
	} );
	const childrenOf = ( id ) => st.tree.folders.filter( ( f ) => f.parent_id === id && treeById.value[ f.id ] );
	const rootIds = computed( () => {
		const given = ( st.tree.roots || [] ).filter( ( id ) => treeById.value[ id ] );
		return given;
	} );
	const treeRows = computed( () => {
		const out = [];
		const walk = ( id, depth ) => {
			const f = treeById.value[ id ];
			if ( ! f ) return;
			const kids = childrenOf( id );
			const open = !! st.expanded[ id ];
			out.push( { id, depth, folder: f, hasChildren: kids.length > 0, open } );
			if ( open ) kids.forEach( ( k ) => walk( k.id, depth + 1 ) );
		};
		rootIds.value.forEach( ( id ) => walk( id, 1 ) );
		return out;
	} );
	const isInside = ( id, ancestor ) => {
		let cur = treeById.value[ id ];
		let guard = 0;
		while ( cur && guard++ < 50 ) {
			if ( cur.id === ancestor ) return true;
			cur = treeById.value[ cur.parent_id ];
		}
		return false;
	};
	const levelOf = ( id ) => ( treeById.value[ id ] ? treeById.value[ id ].level : 0 );
	const expandTo = ( id ) => {
		let cur = treeById.value[ id ];
		let guard = 0;
		while ( cur && guard++ < 50 ) {
			cur = treeById.value[ cur.parent_id ];
			if ( cur ) st.expanded[ cur.id ] = true;
		}
	};
	const toggleNode = ( id, force ) => {
		st.expanded[ id ] = force === undefined ? ! st.expanded[ id ] : force;
	};
	const loadTree = async () => {
		try {
			const r = await api.get( '/fm/tree' );
			st.tree = { folders: r.folders || [], roots: r.roots || [] };
			expandTo( st.folderId );
		} catch ( e ) {
			/* the list still works without the tree */
		}
	};

	/* ───────────── folder colours and badges ───────────── */
	const folderColor = ( row ) => row.color || ( cfg.theme === 'classroom' ? CLASSROOM_COLORS[ row.id % CLASSROOM_COLORS.length ] : '' );
	const showBadge = ( row ) => ! row.inherit && ( cfg.staff || row.level >= 4 );

	/* ───────────── items ───────────── */
	const items = computed( () => {
		if ( st.trash ) {
			return st.trashData.items.map( ( t ) => ( { key: 't:' + t.type + ':' + t.id, type: 'trash', kind: t.type, id: t.id, name: t.name, row: t } ) );
		}
		const fo = st.folders.map( ( r ) => ( { key: 'd' + r.id, type: 'folder', id: r.id, name: r.name, row: r } ) );
		const fi = st.files.map( ( r ) => ( { key: 'f' + r.id, type: 'file', id: r.id, name: r.name, row: r } ) );
		const m = st.sort.dir === 'desc' ? -1 : 1;
		const byName = ( a, b ) => collator.compare( a.name, b.name );
		const cmp = {
			name: byName,
			date: ( a, b ) => String( a.row.modified || '' ).localeCompare( String( b.row.modified || '' ) ) || byName( a, b ),
			size: ( a, b ) => ( ( a.row.size ?? a.row.items ?? 0 ) - ( b.row.size ?? b.row.items ?? 0 ) ) || byName( a, b ),
			type: ( a, b ) => collator.compare( a.row.ext || '', b.row.ext || '' ) || byName( a, b ),
		};
		const sortFolders = st.sort.key === 'date' || st.sort.key === 'size' ? cmp[ st.sort.key ] : byName;
		fo.sort( ( a, b ) => m * sortFolders( a, b ) );
		fi.sort( ( a, b ) => m * cmp[ st.sort.key ]( a, b ) );
		return [ ...fo, ...fi ];
	} );
	const itemByKey = ( k ) => items.value.find( ( i ) => i.key === k );
	const selItems = computed( () => st.sel.map( itemByKey ).filter( Boolean ) );
	const isLink = ( it ) => !! it && it.type === 'file' && !! it.row.is_link;
	const selSize = computed( () => selItems.value.reduce( ( n, i ) => n + ( i.type === 'file' && ! i.row.is_link ? Number( i.row.size ) || 0 : 0 ), 0 ) );
	const statusText = computed( () => {
		const parts = [ countLabel( items.value.length ) ];
		if ( selItems.value.length ) {
			parts.push( countLabel( selItems.value.length ) + ' selected' );
			if ( selSize.value ) parts.push( fmtSize( selSize.value ) );
			if ( selItems.value.length === 1 && isLink( selItems.value[ 0 ] ) ) parts.push( 'Link' );
		}
		return parts.join( ' | ' );
	} );
	const viewable = computed( () => items.value.filter( ( i ) => i.type === 'file' ) );
	const searching = computed( () => st.query !== '' );
	const crumbs = computed( () => ( st.folder && st.folder.path ? st.folder.path : [] ) );
	const title = computed( () => ( st.trash ? 'Recycle Bin' : searching.value ? 'Search results' : st.folder ? st.folder.name : 'Home' ) );

	/* ───────────── loading ───────────── */
	const clean = () => {
		const keys = new Set( items.value.map( ( i ) => i.key ) );
		st.sel = st.sel.filter( ( k ) => keys.has( k ) );
		if ( st.cursor && ! keys.has( st.cursor ) ) st.cursor = null;
		if ( st.anchor && ! keys.has( st.anchor ) ) st.anchor = null;
	};
	const load = async ( silent = false ) => {
		const seq = ++reqSeq;
		if ( ! silent ) st.loading = true;
		st.error = '';
		try {
			if ( st.trash ) {
				const r = await api.get( '/fm/trash' );
				if ( seq !== reqSeq ) return;
				st.trashData = { items: r.items || [], days: r.days || 0 };
				st.folder = null;
				st.folders = [];
				st.files = [];
			} else {
				const r = await api.get( '/fm/list', { folder: st.folderId, q: st.query, sort: st.sort.key, dir: st.sort.dir } );
				if ( seq !== reqSeq ) return;
				st.folder = r.folder || null;
				st.can = { upload: false, edit: false, manage: false, create_folder: false, ...( r.can || {} ) };
				st.folders = r.folders || [];
				st.files = r.files || [];
				if ( ! st.query ) expandTo( st.folderId );
			}
			st.loaded = true;
			clean();
			if ( boot.onFolder && ! st.trash && ! st.query ) boot.onFolder( st.folderId );
		} catch ( e ) {
			if ( seq !== reqSeq ) return;
			st.error = e.message;
			if ( e.status === 403 && st.folderId ) {
				toast( 'You cannot open that folder.', 'error' );
				st.folderId = 0;
				return load( silent );
			}
		} finally {
			if ( seq === reqSeq ) st.loading = false;
		}
	};
	const reloadSoon = () => {
		clearTimeout( reloadTimer );
		reloadTimer = setTimeout( () => load( true ), 500 );
	};
	const refresh = async () => {
		await Promise.all( [ load( true ), loadTree() ] );
	};

	/* ───────────── navigation ───────────── */
	const here = () => ( { folder: st.folderId, q: st.query, trash: st.trash } );
	const go = ( to, push = true ) => {
		if ( push ) {
			st.back.push( here() );
			if ( st.back.length > 100 ) st.back.shift();
			st.fwd = [];
		}
		st.folderId = to.folder || 0;
		st.query = to.q || '';
		st.searchText = st.query;
		st.trash = !! to.trash;
		st.sel = [];
		st.anchor = null;
		st.cursor = null;
		st.selectMode = false;
		st.navOpen = false;
		return load();
	};
	const openFolder = ( id ) => go( { folder: Number( id ) || 0 } );
	const goHome = () => openFolder( 0 );
	const openTrash = () => go( { trash: true } );
	const search = ( q ) => {
		q = String( q || '' ).trim();
		if ( q === st.query ) return;
		if ( ! q ) return go( { folder: st.folderId } );
		return go( { folder: st.folderId, q } );
	};
	const parentId = () => {
		if ( st.query || st.trash ) return st.folderId;
		const c = crumbs.value;
		if ( c.length >= 2 ) return c[ c.length - 2 ].id;
		const p = st.folder && treeById.value[ st.folder.id ] ? treeById.value[ st.folder.id ].parent_id : 0;
		return treeById.value[ p ] ? p : 0;
	};
	const canGoUp = computed( () => st.trash || searching.value || st.folderId !== 0 );
	const up = () => {
		if ( st.trash || searching.value ) return go( { folder: st.folderId } );
		if ( st.folderId ) return openFolder( parentId() );
	};
	const goBack = () => {
		if ( ! st.back.length ) return;
		st.fwd.push( here() );
		return go( st.back.pop(), false );
	};
	const goForward = () => {
		if ( ! st.fwd.length ) return;
		st.back.push( here() );
		return go( st.fwd.pop(), false );
	};

	/* ───────────── selection ───────────── */
	const select = ( item, { ctrl = false, shift = false } = {} ) => {
		const list = items.value;
		const idx = list.findIndex( ( i ) => i.key === item.key );
		if ( shift && st.anchor ) {
			const a = list.findIndex( ( i ) => i.key === st.anchor );
			const [ lo, hi ] = a < idx ? [ a, idx ] : [ idx, a ];
			const range = list.slice( lo, hi + 1 ).map( ( i ) => i.key );
			st.sel = ctrl ? [ ...new Set( [ ...st.sel, ...range ] ) ] : range;
		} else if ( ctrl ) {
			st.sel = st.sel.includes( item.key ) ? st.sel.filter( ( k ) => k !== item.key ) : [ ...st.sel, item.key ];
			st.anchor = item.key;
		} else {
			st.sel = [ item.key ];
			st.anchor = item.key;
		}
		st.cursor = item.key;
	};
	const selectKeys = ( keys ) => {
		st.sel = keys.filter( ( k ) => items.value.some( ( i ) => i.key === k ) );
		st.cursor = st.sel[ 0 ] || null;
		st.anchor = st.cursor;
	};
	const selectAll = () => {
		st.sel = items.value.map( ( i ) => i.key );
	};
	const invertSelection = () => {
		st.sel = items.value.map( ( i ) => i.key ).filter( ( k ) => ! st.sel.includes( k ) );
	};
	const clearSelection = () => {
		st.sel = [];
		st.selectMode = false;
	};

	/* ───────────── what the person may do ───────────── */
	const canEditItem = ( it ) => ( it.type === 'folder' ? it.row.level >= 3 : ! searching.value && st.can.edit );
	const clipTarget = ( target ) => ( target === undefined ? st.folderId : target );
	const canDropOn = ( folderId ) => folderId > 0 && levelOf( folderId ) >= 3;
	const can = computed( () => {
		const sel = selItems.value;
		const one = sel.length === 1 ? sel[ 0 ] : null;
		const trash = st.trash;
		const here = ! searching.value && ! trash;
		const clipFolders = !! st.clip && st.clip.items.some( ( i ) => i.type === 'folder' );
		const zipOne = !! one && one.type === 'folder' && !! one.row.zip_url;
		return {
			link: here && st.can.upload,
			newFolder: here && st.can.create_folder,
			upload: here && st.can.upload,
			uploadFolder: here && st.can.upload && st.can.create_folder,
			cut: ! trash && sel.length > 0 && sel.every( canEditItem ),
			copy: ! trash && sel.length > 0 && sel.every( ( i ) => i.type === 'file' || i.type === 'folder' ),
			paste: here && !! st.clip && ( st.clip.mode === 'cut' ? st.can.edit : clipFolders ? st.can.create_folder : st.can.upload ),
			rename: ! trash && !! one && canEditItem( one ),
			remove: ! trash && sel.length > 0 && sel.every( canEditItem ),
			share: ! trash && !! one && one.type === 'file' && ! one.row.is_link && ! searching.value && st.can.edit,
			download: ! trash && ( zipOne || sel.some( ( i ) => i.type === 'file' && ! i.row.is_link && !! i.row.download_url ) ),
			access: ! trash && ( ( !! one && one.type === 'folder' && one.row.level >= 4 ) || ( ! sel.length && here && !! st.folder && st.can.manage ) ),
			restore: trash && sel.length > 0,
			purge: trash && sel.length > 0 && cfg.manager,
			empty: trash && cfg.manager && st.trashData.items.length > 0,
		};
	} );

	/* ───────────── dialogs, viewer, menus ───────────── */
	const ask = ( name, props = {} ) => {
		st.menu = null;
		st.dialog = { name, props };
	};
	const closeDialog = () => {
		st.dialog = null;
	};
	const openMenu = ( menuItems, x, y, extra = {} ) => {
		st.menu = { items: menuItems, x, y, ...extra };
	};
	const closeMenu = () => {
		st.menu = null;
	};
	const preview = ( item ) => {
		const i = viewable.value.findIndex( ( v ) => v.key === item.key );
		if ( i >= 0 ) st.viewer = i;
	};
	const closeViewer = () => {
		st.viewer = null;
	};
	const openItem = ( item ) => {
		if ( item.type === 'folder' ) return openFolder( item.id );
		if ( item.type === 'file' ) return preview( item );
	};
	const showLocation = async ( item ) => {
		const target = item.row.folder_id;
		const key = 'f' + item.id;
		await go( { folder: target } );
		selectKeys( [ key ] );
	};

	/* ───────────── folder and file actions ───────────── */
	const createFolder = async ( name ) => {
		const r = await api.post( '/fm/folders', { parent_id: st.folderId, name } );
		await refresh();
		selectKeys( [ 'd' + r.id ] );
		return r;
	};
	const createLink = async ( { name, url, description } ) => {
		const r = await api.post( '/fm/links', { folder_id: st.folderId, name, url, description: description || '' } );
		await refresh();
		selectKeys( [ 'f' + r.id ] );
		return r;
	};
	const renameItem = async ( item, name ) => {
		if ( item.type === 'folder' ) await api.patch( '/fm/folders/' + item.id, { name } );
		else await api.patch( '/fm/items/' + item.id, { name } );
		await refresh();
		selectKeys( [ item.key ] );
	};
	const updateFile = async ( file, patch ) => {
		const r = await api.patch( '/fm/items/' + file.id, patch );
		Object.assign( file, r && typeof r === 'object' ? r : patch );
		return r;
	};
	const updateFolder = async ( folder, patch ) => {
		const r = await api.patch( '/fm/folders/' + folder.id, patch );
		Object.assign( folder, r && typeof r === 'object' ? r : patch );
		await loadTree();
		return r;
	};
	const recolor = async ( item, color ) => {
		try {
			await api.patch( '/fm/folders/' + item.id, { color } );
			await refresh();
		} catch ( e ) {
			fail( e );
		}
	};
	const removeItems = async ( list ) => {
		let bad = 0;
		let last = null;
		for ( const it of list ) {
			try {
				await api.del( ( it.type === 'folder' ? '/fm/folders/' : '/fm/items/' ) + it.id );
			} catch ( e ) {
				bad++;
				last = e;
			}
		}
		st.sel = [];
		await refresh();
		if ( bad ) toast( ( bad === list.length ? 'Nothing was deleted. ' : bad + ' of ' + list.length + ' could not be deleted. ' ) + ( last ? last.message : '' ), 'error' );
		else toast( countLabel( list.length ) + ' moved to the Recycle Bin.', 'success' );
	};
	const moveItems = async ( list, target ) => {
		let moved = 0;
		for ( const it of list ) {
			if ( it.type === 'file' && it.row.folder_id === target ) continue;
			if ( it.type === 'folder' && ( it.id === target || it.row.parent_id === target || isInside( target, it.id ) ) ) {
				toast( 'You cannot move a folder into itself.', 'error' );
				continue;
			}
			try {
				if ( it.type === 'folder' ) await api.patch( '/fm/folders/' + it.id, { parent_id: target } );
				else await api.patch( '/fm/items/' + it.id, { folder_id: target } );
				moved++;
			} catch ( e ) {
				fail( e );
			}
		}
		st.sel = [];
		await refresh();
		if ( moved ) toast( countLabel( moved ) + ' moved.', 'success' );
		return moved;
	};
	const copyItems = async ( list, target ) => {
		const made = [];
		let bad = 0;
		const busy = toast( 'Copying ' + countLabel( list.length ) + '…', 'info', 0 );
		for ( const it of list ) {
			try {
				const r = it.type === 'folder'
					? await api.post( '/fm/folders/' + it.id + '/copy', { parent_id: target } )
					: await api.post( '/fm/items/' + it.id + '/copy', { folder_id: target } );
				made.push( { type: it.type, id: r && r.id } );
			} catch ( e ) {
				bad++;
				fail( e );
			}
		}
		dismissToast( busy );
		await refresh();
		const keys = made.filter( ( m ) => m.id ).map( ( m ) => ( m.type === 'folder' ? 'd' : 'f' ) + m.id );
		if ( target === st.folderId && ! st.query && ! st.trash && keys.length ) selectKeys( keys );
		if ( made.length ) toast( countLabel( made.length ) + ' copied.' + ( bad ? ' ' + bad + ' could not be copied.' : '' ), 'success' );
	};
	const cut = () => {
		if ( ! can.value.cut ) return;
		st.clip = { mode: 'cut', items: selItems.value.map( ( i ) => ( { type: i.type, id: i.id, name: i.name, key: i.key, row: i.row } ) ) };
		toast( countLabel( st.clip.items.length ) + ' cut. Open a folder and paste.', 'info', 2500 );
	};
	const copy = () => {
		if ( ! can.value.copy ) return;
		st.clip = { mode: 'copy', items: selItems.value.map( ( i ) => ( { type: i.type, id: i.id, name: i.name, key: i.key, row: i.row } ) ) };
		toast( countLabel( st.clip.items.length ) + ' copied. Open a folder and paste.', 'info', 2500 );
	};
	const paste = async ( target ) => {
		const to = clipTarget( target );
		if ( ! st.clip || ( to === st.folderId && ! can.value.paste ) ) return;
		const clip = st.clip;
		if ( clip.mode === 'cut' ) {
			await moveItems( clip.items.map( ( i ) => ( { ...i, type: i.type } ) ), to );
			st.clip = null;
		} else {
			await copyItems( clip.items, to );
		}
	};
	const isCut = ( key ) => !! st.clip && st.clip.mode === 'cut' && st.clip.items.some( ( i ) => i.key === key );
	const download = ( list ) => {
		if ( list.length === 1 && list[ 0 ].type === 'folder' ) {
			const zip = list[ 0 ].row.zip_url;
			if ( ! zip ) return;
			const a = document.createElement( 'a' );
			a.href = zip;
			a.rel = 'noopener';
			a.style.display = 'none';
			document.body.appendChild( a );
			a.click();
			a.remove();
			toast( 'Preparing “' + list[ 0 ].name + '.zip”…', 'info', 3000 );
			return;
		}
		const files = list.filter( ( i ) => i.type === 'file' && ! i.row.is_link && i.row.download_url );
		if ( ! files.length ) return;
		files.forEach( ( f, n ) => {
			setTimeout( () => {
				const a = document.createElement( 'a' );
				a.href = f.row.download_url;
				a.download = f.name;
				a.rel = 'noopener';
				a.style.display = 'none';
				document.body.appendChild( a );
				a.click();
				a.remove();
			}, n * 350 );
		} );
		if ( list.length > files.length ) toast( 'Folders download one at a time; their files were skipped here.', 'info' );
	};

	/* ───────────── recycle bin ───────────── */
	const restoreTrash = async ( list ) => {
		for ( const t of list ) {
			try {
				await api.post( '/fm/trash/restore', { type: t.row.type, id: t.id } );
			} catch ( e ) {
				fail( e );
			}
		}
		st.sel = [];
		await Promise.all( [ load( true ), loadTree() ] );
		toast( 'Restored ' + countLabel( list.length ) + '.', 'success' );
	};
	const purgeTrash = async ( list ) => {
		for ( const t of list ) {
			try {
				await api.post( '/fm/trash/purge', { type: t.row.type, id: t.id } );
			} catch ( e ) {
				fail( e );
			}
		}
		st.sel = [];
		await load( true );
	};
	const emptyBin = async () => {
		try {
			await api.post( '/fm/trash/purge', { all: true } );
			st.sel = [];
			await load( true );
			toast( 'The Recycle Bin is empty.', 'success' );
		} catch ( e ) {
			fail( e );
		}
	};

	/* ───────────── preferences ───────────── */
	const savePrefs = async ( patch ) => {
		try {
			const r = await api.post( '/fm/prefs', patch );
			if ( r && typeof r === 'object' && r.themes ) Object.assign( cfg, { themes: r.themes, theme_switch: r.theme_switch } );
		} catch ( e ) { /* a preference that did not save is not worth a message */ }
	};
	const setTheme = ( id ) => {
		if ( ! cfg.themes[ id ] ) return;
		cfg.theme = id;
		if ( cfg.theme_switch ) savePrefs( { theme: id } );
	};
	const setView = ( v ) => {
		st.view = v;
		savePrefs( { view: v } );
	};
	const setSort = ( key, dir ) => {
		if ( key === st.sort.key && dir === undefined ) st.sort.dir = st.sort.dir === 'asc' ? 'desc' : 'asc';
		else {
			st.sort.key = key;
			st.sort.dir = dir || 'asc';
		}
	};

	/* ───────────── uploads ───────────── */
	const uploader = createUploader( {
		api,
		cfg,
		retryDelay: boot.retryDelay === undefined ? 600 : boot.retryDelay,
		onDone: ( t ) => {
			if ( ! st.trash && ! st.query && t.folder_id === st.folderId ) reloadSoon();
		},
	} );
	const folderCache = new Map();
	const ensurePath = async ( parent, segments ) => {
		let cur = parent;
		let path = String( parent );
		for ( const name of segments ) {
			path += '/' + name;
			if ( folderCache.has( path ) ) {
				cur = folderCache.get( path );
				continue;
			}
			const existing = st.tree.folders.find( ( f ) => f.parent_id === cur && f.name.toLowerCase() === name.toLowerCase() );
			if ( existing ) {
				cur = existing.id;
			} else {
				const r = await api.post( '/fm/folders', { parent_id: cur, name } );
				st.tree.folders.push( r );
				cur = r.id;
			}
			folderCache.set( path, cur );
		}
		return cur;
	};
	/** list: [ { file, dirs: [ 'School', 'Year 1' ] } ] — dirs are folders to create under the target. */
	const queueFiles = async ( list, target, fileId = 0 ) => {
		if ( ! list.length ) return;
		const entries = [];
		try {
			for ( const e of list ) {
				const folder_id = e.dirs && e.dirs.length ? await ensurePath( target, e.dirs ) : target;
				entries.push( { file: e.file, folder_id, file_id: fileId } );
			}
		} catch ( err ) {
			fail( err );
		}
		if ( entries.length ) uploader.enqueue( entries );
		if ( list.some( ( e ) => e.dirs && e.dirs.length ) ) refresh();
	};
	const addFiles = ( files, target = st.folderId, fileId = 0 ) => {
		if ( target === st.folderId && ! can.value.upload && ! ( fileId && st.can.upload ) ) {
			toast( 'You cannot add files here. Open a folder you can add files to.', 'error' );
			return;
		}
		if ( target !== st.folderId && levelOf( target ) < 2 ) {
			toast( 'You cannot add files to that folder.', 'error' );
			return;
		}
		const list = [ ...files ].map( ( file ) => {
			const rel = file.webkitRelativePath || '';
			const dirs = rel.includes( '/' ) ? rel.split( '/' ).slice( 0, -1 ) : [];
			return { file, dirs };
		} );
		return queueFiles( list, target, fileId );
	};
	const pick = ( { folder = false, fileId = 0, target = st.folderId } = {} ) => {
		const input = document.createElement( 'input' );
		input.type = 'file';
		input.style.display = 'none';
		if ( folder ) {
			input.setAttribute( 'webkitdirectory', '' );
			input.setAttribute( 'directory', '' );
		} else if ( ! fileId ) {
			input.multiple = true;
		}
		input.addEventListener( 'change', () => {
			if ( input.files && input.files.length ) addFiles( input.files, target, fileId );
			input.remove();
		} );
		document.body.appendChild( input );
		input.click();
	};
	const readEntry = ( entry, dirs = [] ) =>
		new Promise( ( resolve ) => {
			if ( entry.isFile ) {
				entry.file( ( file ) => resolve( [ { file, dirs } ] ), () => resolve( [] ) );
			} else if ( entry.isDirectory ) {
				const reader = entry.createReader();
				const all = [];
				const next = () =>
					reader.readEntries( async ( batch ) => {
						if ( ! batch.length ) {
							const nested = await Promise.all( all.map( ( e ) => readEntry( e, [ ...dirs, entry.name ] ) ) );
							resolve( nested.flat() );
							return;
						}
						all.push( ...batch );
						next();
					}, () => resolve( [] ) );
				next();
			} else resolve( [] );
		} );
	const addDropped = async ( dt, target = st.folderId ) => {
		if ( target === st.folderId && ! can.value.upload ) {
			toast( 'You cannot add files here. Open a folder you can add files to.', 'error' );
			return;
		}
		if ( target !== st.folderId && levelOf( target ) < 2 ) {
			toast( 'You cannot add files to that folder.', 'error' );
			return;
		}
		const entries = [];
		const itemsList = dt.items ? [ ...dt.items ] : [];
		if ( itemsList.length && itemsList[ 0 ].webkitGetAsEntry ) {
			const roots = itemsList.filter( ( i ) => i.kind === 'file' ).map( ( i ) => i.webkitGetAsEntry() ).filter( Boolean );
			for ( const r of roots ) entries.push( ...( await readEntry( r ) ) );
		} else {
			[ ...( dt.files || [] ) ].forEach( ( file ) => entries.push( { file, dirs: [] } ) );
		}
		if ( entries.some( ( e ) => e.dirs.length ) && ! st.can.create_folder && target === st.folderId ) {
			toast( 'You cannot create folders here.', 'error' );
			return;
		}
		return queueFiles( entries, target );
	};

	/* ───────────── menus ───────────── */
	const viewMenu = () => VIEWS.map( ( v ) => ( { label: v.label, icon: v.icon, checked: st.view === v.id, action: () => setView( v.id ) } ) );
	const sortMenu = () => [
		...SORTS.map( ( s ) => ( { label: s.label, checked: st.sort.key === s.id, action: () => setSort( s.id, st.sort.dir ) } ) ),
		{ sep: true },
		{ label: 'Ascending', checked: st.sort.dir === 'asc', action: () => setSort( st.sort.key, 'asc' ) },
		{ label: 'Descending', checked: st.sort.dir === 'desc', action: () => setSort( st.sort.key, 'desc' ) },
	];
	const themeMenu = () => Object.entries( cfg.themes ).map( ( [ id, label ] ) => ( { label, checked: cfg.theme === id, action: () => setTheme( id ) } ) );
	const colorMenu = ( item ) =>
		FOLDER_COLORS.map( ( c ) => ( { label: c.name, swatch: c.value || 'var(--fx-folder)', checked: ( item.row.color || '' ) === c.value, action: () => recolor( item, c.value ) } ) );

	const contextItems = ( item ) => {
		const c = can.value;
		const sel = selItems.value;
		if ( st.trash ) {
			return [
				{ label: 'Restore', icon: 'restore', disabled: ! c.restore, action: () => restoreTrash( sel ) },
				{ label: 'Delete permanently', icon: 'trash', danger: true, disabled: ! c.purge, action: () => ask( 'confirm', { title: 'Delete permanently', message: 'Delete ' + countLabel( sel.length ) + ' for good? This cannot be undone.', confirm: 'Delete permanently', danger: true, run: () => purgeTrash( sel ) } ) },
			];
		}
		if ( ! item ) {
			const out = [
				{ label: 'View', icon: 'view', children: viewMenu() },
				{ label: 'Sort by', icon: 'sort', children: sortMenu() },
				{ label: 'Refresh', icon: 'refresh', hint: 'F5', action: () => refresh() },
				{ sep: true },
			];
			if ( c.paste ) out.push( { label: 'Paste', icon: 'paste', hint: 'Ctrl+V', action: () => paste() } );
			if ( c.newFolder ) out.push( { label: 'New folder', icon: 'folder-plus', action: () => ask( 'name', { mode: 'new' } ) } );
			if ( c.upload ) out.push( { label: 'Upload files…', icon: 'upload', action: () => pick() } );
			if ( c.uploadFolder ) out.push( { label: 'Upload folder…', icon: 'folder-up', action: () => pick( { folder: true } ) } );
			if ( c.link ) out.push( { label: 'Link…', icon: 'link', action: () => ask( 'link' ) } );
			if ( st.folder && st.can.manage ) out.push( { sep: true }, { label: 'Who can access this folder…', icon: 'people', action: () => ask( 'access', { folder: st.folder } ) } );
			if ( st.folder ) out.push( { label: 'Properties', icon: 'info', action: () => ask( 'props', { items: [ { type: 'folder', id: st.folder.id, name: st.folder.name, key: 'd' + st.folder.id, row: st.folder } ] } ) } );
			return out;
		}
		const multi = sel.length > 1;
		const one = sel.length === 1 ? sel[ 0 ] : item;
		const out = [];
		if ( ! multi ) {
			out.push( { label: one.type === 'folder' || isLink( one ) ? 'Open' : 'Preview', icon: one.type === 'folder' ? 'folder' : 'eye', hint: 'Enter', action: () => openItem( one ) } );
			if ( searching.value && one.type === 'file' ) out.push( { label: 'Open file location', icon: 'external', action: () => showLocation( one ) } );
		}
		if ( c.download ) out.push( { label: one.type === 'folder' && ! multi ? 'Download as zip' : 'Download', icon: 'download', action: () => download( sel ) } );
		out.push( { sep: true } );
		out.push( { label: 'Cut', icon: 'cut', hint: 'Ctrl+X', disabled: ! c.cut, action: cut } );
		out.push( { label: 'Copy', icon: 'copy', hint: 'Ctrl+C', disabled: ! c.copy, action: copy } );
		if ( ! multi && one.type === 'folder' && st.clip && canDropOn( one.id ) ) out.push( { label: 'Paste into folder', icon: 'paste', action: () => paste( one.id ) } );
		out.push( { sep: true } );
		if ( ! multi ) {
			if ( one.type === 'file' && ! isLink( one ) ) {
				if ( c.share ) out.push( { label: 'Share link…', icon: 'share', action: () => ask( 'share', { file: one.row } ) } );
				if ( ! searching.value && st.can.upload ) out.push( { label: 'Upload new version…', icon: 'new-version', action: () => pick( { fileId: one.id, target: one.row.folder_id } ) } );
				out.push( { label: 'Version history', icon: 'history', action: () => ask( 'versions', { file: one.row } ) } );
			}
			if ( one.type === 'folder' && c.access ) out.push( { label: 'Who can access this folder…', icon: 'people', action: () => ask( 'access', { folder: one.row } ) } );
			if ( one.type === 'folder' && canEditItem( one ) ) out.push( { label: 'Folder colour', icon: 'palette', children: colorMenu( one ) } );
			if ( c.rename ) out.push( { label: 'Rename', icon: 'rename', hint: 'F2', action: () => ask( 'name', { mode: 'rename', item: one } ) } );
		}
		if ( c.remove ) out.push( { label: 'Delete', icon: 'trash', hint: 'Del', danger: true, action: () => confirmDelete( sel ) } );
		out.push( { sep: true } );
		if ( st.narrow ) out.push( { label: st.selectMode ? 'Stop selecting' : 'Select several', icon: 'select-all', action: () => ( st.selectMode = ! st.selectMode ) } );
		out.push( { label: 'Properties', icon: 'info', action: () => ask( 'props', { items: sel.length ? sel : [ item ] } ) } );
		return out;
	};
	const confirmDelete = ( list ) =>
		ask( 'confirm', {
			title: 'Delete',
			message: list.length === 1 ? 'Move “' + list[ 0 ].name + '” to the Recycle Bin?' : 'Move these ' + list.length + ' items to the Recycle Bin?',
			note: list.some( ( i ) => i.type === 'folder' ) ? 'Everything inside a folder goes with it. You can restore it from the Recycle Bin' + ( st.trashData.days ? '.' : '.' ) : 'You can restore it from the Recycle Bin.',
			confirm: 'Delete',
			danger: true,
			run: () => removeItems( list ),
		} );
	const openContext = ( x, y, item ) => {
		if ( item && ! st.sel.includes( item.key ) ) select( item );
		if ( ! item && ! st.selectMode ) st.sel = [];
		openMenu( contextItems( item ), x, y );
	};

	/* ───────────── keyboard ───────────── */
	let typed = '';
	let typedAt = 0;
	const itemEls = () => ( refs.items ? [ ...refs.items.querySelectorAll( '[data-key]' ) ] : [] );
	const scrollTo = ( key ) => {
		const el = itemEls().find( ( e ) => e.dataset.key === key );
		if ( el && el.scrollIntoView ) el.scrollIntoView( { block: 'nearest', inline: 'nearest' } );
	};
	const moveCursor = ( dir, extend ) => {
		const list = items.value;
		if ( ! list.length ) return;
		let i = list.findIndex( ( x ) => x.key === st.cursor );
		let next = -1;
		const els = itemEls();
		const rects = els.map( ( e ) => e.getBoundingClientRect() );
		const geometric = st.view !== 'details' && i >= 0 && rects.length === list.length && rects[ i ] && rects[ i ].width > 0;
		if ( i < 0 ) next = dir === 'up' || dir === 'left' ? list.length - 1 : 0;
		else if ( geometric ) {
			const r = rects[ i ];
			let best = null;
			rects.forEach( ( q, n ) => {
				if ( n === i ) return;
				let primary;
				let secondary;
				if ( dir === 'down' ) {
					if ( q.top < r.bottom - 4 ) return;
					primary = q.top - r.bottom;
					secondary = Math.abs( q.left - r.left );
				} else if ( dir === 'up' ) {
					if ( q.bottom > r.top + 4 ) return;
					primary = r.top - q.bottom;
					secondary = Math.abs( q.left - r.left );
				} else if ( dir === 'right' ) {
					if ( q.left < r.right - 4 ) return;
					primary = q.left - r.right;
					secondary = Math.abs( q.top - r.top );
				} else {
					if ( q.right > r.left + 4 ) return;
					primary = r.left - q.right;
					secondary = Math.abs( q.top - r.top );
				}
				const score = primary * 4 + secondary;
				if ( ! best || score < best.score ) best = { n, score };
			} );
			next = best ? best.n : i;
		} else if ( dir === 'down' || dir === 'right' ) next = Math.min( list.length - 1, i + ( dir === 'right' && st.view === 'details' ? 0 : 1 ) );
		else next = Math.max( 0, i - ( dir === 'left' && st.view === 'details' ? 0 : 1 ) );
		const it = list[ next ];
		if ( ! it ) return;
		if ( extend ) select( it, { shift: true } );
		else {
			st.sel = [ it.key ];
			st.anchor = it.key;
			st.cursor = it.key;
		}
		scrollTo( it.key );
	};
	const jumpTo = ( which, extend ) => {
		const list = items.value;
		const it = which === 'home' ? list[ 0 ] : list[ list.length - 1 ];
		if ( ! it ) return;
		if ( extend ) select( it, { shift: true } );
		else selectKeys( [ it.key ] );
		st.cursor = it.key;
		scrollTo( it.key );
	};
	const focusItems = () => {
		if ( refs.items && refs.items.focus ) refs.items.focus( { preventScroll: true } );
	};
	/** Returns true when it used the key. */
	const onKey = ( e ) => {
		const mod = e.ctrlKey || e.metaKey;
		const k = e.key;
		const cur = itemByKey( st.cursor ) || ( selItems.value.length === 1 ? selItems.value[ 0 ] : null );
		if ( mod && ( k === 'a' || k === 'A' ) ) return selectAll(), true;
		if ( mod && ( k === 'c' || k === 'C' ) ) return copy(), true;
		if ( mod && ( k === 'x' || k === 'X' ) ) return cut(), true;
		if ( mod && ( k === 'v' || k === 'V' ) ) return paste(), true;
		if ( e.altKey && k === 'ArrowLeft' ) return goBack(), true;
		if ( e.altKey && k === 'ArrowRight' ) return goForward(), true;
		if ( e.altKey && k === 'ArrowUp' ) return up(), true;
		if ( mod || e.altKey ) return false;
		switch ( k ) {
			case 'ArrowDown':
				return moveCursor( 'down', e.shiftKey ), true;
			case 'ArrowUp':
				return moveCursor( 'up', e.shiftKey ), true;
			case 'ArrowLeft':
				return moveCursor( 'left', e.shiftKey ), true;
			case 'ArrowRight':
				return moveCursor( 'right', e.shiftKey ), true;
			case 'Home':
				return jumpTo( 'home', e.shiftKey ), true;
			case 'End':
				return jumpTo( 'end', e.shiftKey ), true;
			case 'Enter':
				if ( cur ) return openItem( cur ), true;
				return false;
			case 'F2':
				if ( can.value.rename ) return ask( 'name', { mode: 'rename', item: selItems.value[ 0 ] } ), true;
				return false;
			case 'Delete':
				if ( can.value.remove ) return confirmDelete( selItems.value ), true;
				if ( st.trash && can.value.restore ) return false;
				return false;
			case 'Backspace':
				return up(), true;
			case 'F5':
				return refresh(), true;
			case 'Escape':
				if ( st.sel.length || st.selectMode ) return clearSelection(), true;
				return false;
			case ' ':
				if ( cur ) {
					select( cur, { ctrl: true } );
					return true;
				}
				return false;
			default:
				if ( k.length === 1 && /\S/.test( k ) ) {
					const now = Date.now();
					typed = now - typedAt > 800 ? k.toLowerCase() : typed + k.toLowerCase();
					typedAt = now;
					const hit = items.value.find( ( i ) => i.name.toLowerCase().startsWith( typed ) );
					if ( hit ) {
						selectKeys( [ hit.key ] );
						scrollTo( hit.key );
					}
					return true;
				}
				return false;
		}
	};

	/* ───────────── layout ───────────── */
	const setWidth = ( w ) => {
		if ( ! w ) return;
		const was = st.narrow;
		st.width = w;
		st.narrow = w < NARROW;
		if ( st.narrow !== was ) {
			st.navOpen = false;
			if ( st.narrow ) st.details = false;
		}
	};

	/* ───────────── start ───────────── */
	const init = async () => {
		if ( ! boot.config ) {
			try {
				Object.assign( cfg, await api.get( '/fm/config' ) );
				st.view = [ 'details', 'list', 'tiles', 'icons' ].includes( cfg.view ) ? cfg.view : st.view;
			} catch ( e ) {
				st.error = e.message;
			}
		}
		if ( st.width < 900 ) st.details = false;
		await Promise.all( [ loadTree(), load() ] );
	};

	return reactive( {
		api, cfg, st, refs, word, toast, dismissToast, fail,
		VIEWS, SORTS,
		treeById, treeRows, rootIds, childrenOf, isInside, levelOf, toggleNode, expandTo, loadTree,
		folderColor, showBadge,
		items, selItems, selSize, statusText, viewable, searching, crumbs, title, can, canGoUp,
		load, refresh, reloadSoon, go, openFolder, goHome, openTrash, search, up, goBack, goForward,
		select, selectKeys, selectAll, invertSelection, clearSelection,
		canEditItem, canDropOn,
		ask, closeDialog, openMenu, closeMenu, preview, closeViewer, openItem, showLocation,
		createFolder, createLink, isLink, renameItem, updateFile, updateFolder, removeItems, moveItems, copyItems, cut, copy, paste, isCut, download, confirmDelete,
		restoreTrash, purgeTrash, emptyBin,
		setTheme, setView, setSort, viewMenu, sortMenu, themeMenu, contextItems, openContext,
		uploader, addFiles, addDropped, pick,
		onKey, focusItems, setWidth, init, extOf,
	} );
}
