import { store } from './core';

/** Who may open the file explorer in the app, and who may change its settings. */
export const canFiles = () => !! ( store.caps.use || store.caps.manage || store.caps.admin || store.canManage );
export const canFilesSettings = () => !! ( store.caps.admin || store.canManage );

/** Where the explorer script lives: next to wpflatfiles.js (the module's own bundle), the way WPFlat Core serves module assets. */
export const explorerUrls = () => {
	const boot = window.wpflatcoreBoot || {};
	const assets = ( boot.moduleAssets && boot.moduleAssets.files ) || {};
	let script = assets.script || '';
	let style = assets.style || '';
	if ( ! script ) {
		const tag = [ ...document.querySelectorAll( 'script[src]' ) ].map( ( s ) => s.getAttribute( 'src' ) ).find( ( s ) => /\/wpflatfiles\.js(\?|$)/.test( s ) );
		script = tag || '';
	}
	const swap = ( url, from, to ) => url.replace( new RegExp( '/' + from.replace( '.', '\\.' ) + '(\\?.*)?$' ), '/' + to + '$1' );
	if ( ! style && script ) style = swap( script, 'wpflatfiles.js', 'wpflatfiles.css' );
	return {
		script: script ? swap( script, 'wpflatfiles.js', 'wpflatfiles-explorer.js' ) : '',
		style: style ? swap( style, 'wpflatfiles.css', 'wpflatfiles-explorer.css' ) : '',
	};
};

/** Load the explorer once (script + stylesheet); resolves with window.WPFlatFilesExplorer. */
export const loadExplorer = () => {
	if ( window.WPFlatFilesExplorer ) return Promise.resolve( window.WPFlatFilesExplorer );
	if ( window.__wpflatFilesLoading ) return window.__wpflatFilesLoading;
	const urls = explorerUrls();
	if ( ! urls.script ) return Promise.reject( new Error( 'The file explorer could not be found.' ) );
	if ( urls.style && ! document.querySelector( 'link[href="' + urls.style + '"]' ) ) {
		const l = document.createElement( 'link' );
		l.rel = 'stylesheet';
		l.href = urls.style;
		document.head.appendChild( l );
	}
	window.__wpflatFilesLoading = new Promise( ( resolve, reject ) => {
		const s = document.createElement( 'script' );
		s.src = urls.script;
		s.async = true;
		s.onload = () => resolve( window.WPFlatFilesExplorer );
		s.onerror = () => {
			window.__wpflatFilesLoading = null;
			reject( new Error( 'The file explorer could not be loaded. Reload the page and try again.' ) );
		};
		document.head.appendChild( s );
	} );
	return window.__wpflatFilesLoading;
};
