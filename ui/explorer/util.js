/** Small helpers shared by the explorer's components. No Vue in here. */

export const fmtSize = ( bytes ) => {
	let n = Number( bytes );
	if ( ! isFinite( n ) || n < 0 ) return '';
	if ( n < 1024 ) return n + ' B';
	const units = [ 'KB', 'MB', 'GB', 'TB' ];
	let i = -1;
	do {
		n /= 1024;
		i++;
	} while ( n >= 1024 && i < units.length - 1 );
	return ( n >= 100 ? Math.round( n ) : Math.round( n * 10 ) / 10 ) + ' ' + units[ i ];
};

/** "2026-01-03 09:00:00" (server time) → a short local date and time. */
export const parseDate = ( s ) => {
	if ( ! s ) return null;
	const d = new Date( String( s ).replace( ' ', 'T' ) );
	return isNaN( d.getTime() ) ? null : d;
};
export const fmtDate = ( s ) => {
	const d = parseDate( s );
	if ( ! d ) return '';
	return d.toLocaleString( undefined, { year: 'numeric', month: 'numeric', day: 'numeric', hour: 'numeric', minute: '2-digit' } );
};
export const fmtDay = ( s ) => {
	const d = parseDate( s );
	return d ? d.toLocaleDateString( undefined, { year: 'numeric', month: 'short', day: 'numeric' } ) : '';
};

export const countLabel = ( n, one = 'item', many = 'items' ) => n + ' ' + ( n === 1 ? one : many );

const KIND_LABEL = {
	pdf: 'PDF document',
	image: 'Image',
	video: 'Video',
	audio: 'Audio',
	doc: 'Document',
	sheet: 'Spreadsheet',
	slides: 'Presentation',
	text: 'Text file',
	archive: 'Compressed folder',
	link: 'Link',
	other: 'File',
};
export const typeLabel = ( item ) => {
	if ( item.type === 'folder' ) return 'File folder';
	const f = item.row || item;
	if ( f.is_link || f.kind === 'link' ) return 'Link';
	const ext = ( f.ext || '' ).toUpperCase();
	if ( f.kind === 'image' && ext ) return ext + ' image';
	if ( f.kind === 'other' && ext ) return ext + ' file';
	return KIND_LABEL[ f.kind ] || ( ext ? ext + ' file' : 'File' );
};

export const extOf = ( name ) => {
	const m = /\.([A-Za-z0-9]+)$/.exec( String( name ) );
	return m ? m[ 1 ].toLowerCase() : '';
};

export const collator = new Intl.Collator( undefined, { numeric: true, sensitivity: 'base' } );

export const TEXT_EXT = [ 'txt', 'md', 'csv', 'json', 'xml', 'log', 'rtf' ];

/** Copy text: the modern API when allowed, a hidden textarea otherwise. */
export const copyText = async ( text ) => {
	try {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			await navigator.clipboard.writeText( text );
			return true;
		}
	} catch ( e ) { /* fall through */ }
	try {
		const t = document.createElement( 'textarea' );
		t.value = text;
		t.style.cssText = 'position:fixed;opacity:0;top:0;left:0';
		document.body.appendChild( t );
		t.select();
		const ok = document.execCommand && document.execCommand( 'copy' );
		t.remove();
		return !! ok;
	} catch ( e ) {
		return false;
	}
};

export const debounce = ( fn, ms ) => {
	let t = null;
	const d = ( ...a ) => {
		clearTimeout( t );
		t = setTimeout( () => fn( ...a ), ms );
	};
	d.cancel = () => clearTimeout( t );
	return d;
};

export const FOLDER_COLORS = [
	{ name: 'Default', value: '' },
	{ name: 'Yellow', value: '#f5b83d' },
	{ name: 'Orange', value: '#f97316' },
	{ name: 'Red', value: '#ef4444' },
	{ name: 'Pink', value: '#ec4899' },
	{ name: 'Purple', value: '#8b5cf6' },
	{ name: 'Blue', value: '#3b82f6' },
	{ name: 'Teal', value: '#14b8a6' },
	{ name: 'Green', value: '#22c55e' },
	{ name: 'Grey', value: '#94a3b8' },
];
export const CLASSROOM_COLORS = [ '#f97316', '#3b82f6', '#22c55e', '#ec4899', '#8b5cf6', '#14b8a6', '#ef4444', '#f5b83d' ];

export const LEVELS = [ 'view', 'edit', 'upload', 'manage' ];
export const LEVELS_MAIN = [ 'view', 'edit' ];
export const LEVELS_MORE = [ 'upload', 'manage' ];
export const LEVEL_LABEL = { view: 'Read only', edit: 'Read & write', upload: 'Add files only', manage: 'Full control' };
export const LEVEL_HELP = {
	view: 'can see and download',
	edit: 'can add, create, rename, move and delete',
	upload: 'can add files but not change or delete',
	manage: 'also decides who has access',
};

const KIND_EXT = {
	pdf: [ 'pdf' ],
	image: [ 'png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'ico', 'heic', 'tif', 'tiff', 'psd', 'ai', 'eps', 'svg' ],
	video: [ 'mp4', 'm4v', 'mov', 'webm', 'mkv', 'avi', 'ogv', 'mpg', 'mpeg' ],
	audio: [ 'mp3', 'm4a', 'wav', 'ogg', 'oga', 'flac', 'aac', 'opus' ],
	doc: [ 'doc', 'docx', 'odt', 'rtf', 'pages', 'epub' ],
	sheet: [ 'xls', 'xlsx', 'ods', 'csv', 'numbers' ],
	slides: [ 'ppt', 'pptx', 'odp', 'key' ],
	text: [ 'txt', 'md', 'json', 'xml', 'log' ],
	archive: [ 'zip', '7z', 'rar', 'gz', 'tar' ],
};
/** The icon kind for a bare file name (used where the server sends no `kind`, e.g. the Recycle Bin). */
export const kindOfName = ( name ) => {
	const e = extOf( name );
	return Object.keys( KIND_EXT ).find( ( k ) => KIND_EXT[ k ].includes( e ) ) || 'other';
};

/** Hosts whose embed is a video player (their link icon is tinted like a video). */
const VIDEO_HOSTS = /(youtube|youtu\.be|vimeo|loom|mediadelivery|bunny)/i;
/** The icon kind for a file row: links get the chain, tinted like a video when the host plays video. */
export const iconKind = ( row ) => {
	if ( ! row ) return 'other';
	if ( row.kind !== 'link' && ! row.is_link ) return row.kind;
	return row.embed_url && VIDEO_HOSTS.test( row.embed_url ) ? 'link-video' : 'link';
};

/** A web address the server will accept: http or https only. */
export const isWebUrl = ( v ) => {
	try {
		const u = new URL( String( v ).trim() );
		return ( u.protocol === 'http:' || u.protocol === 'https:' ) && !! u.hostname;
	} catch ( e ) {
		return false;
	}
};

export const CSV_MAX_ROWS = 200;
export const CSV_MAX_COLS = 30;
/** Guess the separator from the first line (outside quotes): tab, semicolon or comma. */
export const sniffDelimiter = ( text, ext = '' ) => {
	if ( String( ext ).toLowerCase() === 'tsv' ) return '\t';
	const counts = { ',': 0, ';': 0, '\t': 0 };
	let q = false;
	for ( let i = 0; i < text.length && i < 4000; i++ ) {
		const c = text[ i ];
		if ( c === '"' ) q = ! q;
		else if ( ! q && ( c === '\n' || c === '\r' ) ) break;
		else if ( ! q && c in counts ) counts[ c ]++;
	}
	const best = Object.keys( counts ).sort( ( a, b ) => counts[ b ] - counts[ a ] )[ 0 ];
	return counts[ best ] > 0 ? best : ',';
};
/** RFC 4180: quoted fields, "" for a quote, separators and line breaks inside quotes. Stops after `limit` rows (when given). */
export const parseCsv = ( text, delimiter = ',', limit = 0 ) => {
	const rows = [];
	let row = [];
	let field = '';
	let q = false;
	let i = 0;
	if ( text.charCodeAt( 0 ) === 0xfeff ) i = 1;
	const endRow = () => {
		row.push( field );
		field = '';
		if ( ! ( row.length === 1 && row[ 0 ] === '' ) ) rows.push( row );
		row = [];
		return !! limit && rows.length >= limit;
	};
	for ( ; i < text.length; i++ ) {
		const c = text[ i ];
		if ( q ) {
			if ( c === '"' ) {
				if ( text[ i + 1 ] === '"' ) {
					field += '"';
					i++;
				} else q = false;
			} else field += c;
		} else if ( c === '"' && field === '' ) q = true;
		else if ( c === delimiter ) {
			row.push( field );
			field = '';
		} else if ( c === '\n' || c === '\r' ) {
			if ( c === '\r' && text[ i + 1 ] === '\n' ) i++;
			if ( endRow() ) return rows;
		} else field += c;
	}
	if ( field !== '' || row.length ) endRow();
	return rows;
};
