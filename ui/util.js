import { store } from './core';

/** Who may open the file explorer in the app, and who may change its settings. */
export const canFiles = () => !! ( store.caps.use || store.caps.manage || store.caps.admin || store.canManage );
export const canFilesSettings = () => !! ( store.caps.admin || store.canManage );

/** Where the explorer script lives: next to bizyfiles.js (the module's own bundle), the way BizyCore serves module assets. */
export const explorerUrls = () => {
	const boot = window.bizycoreBoot || {};
	const assets = ( boot.moduleAssets && boot.moduleAssets.files ) || {};
	let script = assets.script || '';
	let style = assets.style || '';
	if ( ! script ) {
		const tag = [ ...document.querySelectorAll( 'script[src]' ) ].map( ( s ) => s.getAttribute( 'src' ) ).find( ( s ) => /\/bizyfiles\.js(\?|$)/.test( s ) );
		script = tag || '';
	}
	const swap = ( url, from, to ) => url.replace( new RegExp( '/' + from.replace( '.', '\\.' ) + '(\\?.*)?$' ), '/' + to + '$1' );
	if ( ! style && script ) style = swap( script, 'bizyfiles.js', 'bizyfiles.css' );
	return {
		script: script ? swap( script, 'bizyfiles.js', 'bizyfiles-explorer.js' ) : '',
		style: style ? swap( style, 'bizyfiles.css', 'bizyfiles-explorer.css' ) : '',
	};
};

/** Load the explorer once (script + stylesheet); resolves with window.BizyFilesExplorer. */
export const loadExplorer = () => {
	if ( window.BizyFilesExplorer ) return Promise.resolve( window.BizyFilesExplorer );
	if ( window.__bizyFilesLoading ) return window.__bizyFilesLoading;
	const urls = explorerUrls();
	if ( ! urls.script ) return Promise.reject( new Error( 'The file explorer could not be found.' ) );
	if ( urls.style && ! document.querySelector( 'link[href="' + urls.style + '"]' ) ) {
		const l = document.createElement( 'link' );
		l.rel = 'stylesheet';
		l.href = urls.style;
		document.head.appendChild( l );
	}
	window.__bizyFilesLoading = new Promise( ( resolve, reject ) => {
		const s = document.createElement( 'script' );
		s.src = urls.script;
		s.async = true;
		s.onload = () => resolve( window.BizyFilesExplorer );
		s.onerror = () => {
			window.__bizyFilesLoading = null;
			reject( new Error( 'The file explorer could not be loaded. Reload the page and try again.' ) );
		};
		document.head.appendChild( s );
	} );
	return window.__bizyFilesLoading;
};
