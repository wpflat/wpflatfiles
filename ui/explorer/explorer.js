import { createApp } from 'vue';
import Explorer from './components/Explorer.vue';
import { createStore } from './store';
import './explorer.css';

/**
 * BizyFiles' file explorer: one script with its own Vue, for the portal page (and for the staff app, which loads
 * the same file). window.BizyFilesExplorer.mount( element, boot ) → { unmount, open( folderId ), refresh() }.
 */
function mount( el, boot ) {
	boot = boot || {};
	if ( ! el ) throw new Error( 'BizyFilesExplorer.mount needs an element' );
	el.innerHTML = '';
	const fx = createStore( boot );
	el.classList.add( 'bizyfiles-mounted' );
	el.setAttribute( 'data-theme', fx.cfg.theme );
	const app = createApp( Explorer, { fx } );
	app.mount( el );
	return {
		fx,
		unmount() {
			app.unmount();
			el.classList.remove( 'bizyfiles-mounted' );
		},
		open( id ) {
			return fx.openFolder( id );
		},
		refresh() {
			return fx.refresh();
		},
	};
}

window.BizyFilesExplorer = { mount };

const start = () => {
	const el = document.getElementById( 'bizyfiles-root' );
	if ( el && window.BizyFilesBoot && ! el.classList.contains( 'bizyfiles-mounted' ) ) {
		window.BizyFilesExplorer.instance = mount( el, window.BizyFilesBoot );
	}
};
if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', start );
else start();
