import { createApp } from 'vue';
import Explorer from './components/Explorer.vue';
import { createStore } from './store';
import './explorer.css';

/**
 * WPFlat Files' file explorer: one script with its own Vue, for the portal page (and for the staff app, which loads
 * the same file). window.WPFlatFilesExplorer.mount( element, boot ) → { unmount, open( folderId ), refresh() }.
 */
function mount( el, boot ) {
	boot = boot || {};
	if ( ! el ) throw new Error( 'WPFlat FilesExplorer.mount needs an element' );
	el.innerHTML = '';
	const fx = createStore( boot );
	el.classList.add( 'wpflatfiles-mounted' );
	el.setAttribute( 'data-theme', fx.cfg.theme );
	const app = createApp( Explorer, { fx } );
	app.mount( el );
	return {
		fx,
		unmount() {
			app.unmount();
			el.classList.remove( 'wpflatfiles-mounted' );
		},
		open( id ) {
			return fx.openFolder( id );
		},
		refresh() {
			return fx.refresh();
		},
	};
}

window.WPFlatFilesExplorer = { mount };

const start = () => {
	const el = document.getElementById( 'wpflatfiles-root' );
	if ( el && window.WPFlatFilesBoot && ! el.classList.contains( 'wpflatfiles-mounted' ) ) {
		window.WPFlatFilesExplorer.instance = mount( el, window.WPFlatFilesBoot );
	}
};
if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', start );
else start();
