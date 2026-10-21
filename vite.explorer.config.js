import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

// The explorer is a standalone bundle for the front end (the portal has no Vue), so it brings its own Vue and CSS.
// emptyOutDir stays off: WPFlatFiles' app bundle (vite.config.js, built first) lives in the same folder.
export default defineConfig( {
	root: 'ui/explorer',
	plugins: [ vue() ],
	define: { 'process.env.NODE_ENV': '"production"', __VUE_OPTIONS_API__: 'false', __VUE_PROD_DEVTOOLS__: 'false', __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false' },
	build: {
		outDir: '../../build',
		emptyOutDir: false,
		cssCodeSplit: false,
		lib: { entry: 'explorer.js', name: 'WPFlatFilesExplorerBundle', formats: [ 'iife' ], fileName: () => 'wpflatfiles-explorer.js', cssFileName: 'wpflatfiles-explorer' },
	},
} );
