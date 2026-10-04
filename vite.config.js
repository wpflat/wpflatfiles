import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

// BizyFiles' app bundle runs inside BizyCore's page: Vue is not bundled again, it is BizyCore's window.Vue.
// The standalone explorer (vite.explorer.config.js) is built into the same folder after this one.
export default defineConfig( {
	root: 'ui',
	plugins: [ vue() ],
	define: { 'process.env.NODE_ENV': '"production"' },
	build: {
		outDir: '../build',
		emptyOutDir: true,
		cssCodeSplit: false,
		lib: { entry: 'main.js', name: 'BizyFiles', formats: [ 'iife' ], fileName: () => 'bizyfiles.js', cssFileName: 'bizyfiles' },
		rollupOptions: { external: [ 'vue' ], output: { globals: { vue: 'Vue' } } },
	},
} );
