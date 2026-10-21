<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { api, errorMessage, navigate } from '../core';
import { loadExplorer } from '../util';

/** /files: the same explorer the front end uses, mounted full height. #/files?folder=ID opens a folder. */
const props = defineProps( { query: { type: Object, default: () => ( {} ) } } );
const host = ref( null );
const state = ref( 'loading' );
const error = ref( '' );
let inst = null;
let current = 0;

const start = async () => {
	state.value = 'loading';
	try {
		const [ Explorer, config ] = await Promise.all( [ loadExplorer(), api.getPath( 'fm/config' ) ] );
		const boot = window.wpflatcoreBoot || {};
		current = Number( props.query.folder ) || 0;
		state.value = 'ready';
		inst = Explorer.mount( host.value, {
			mode: 'app',
			api: boot.restUrl,
			nonce: boot.nonce,
			brand: '',
			config,
			folder: current,
			onFolder: ( id ) => {
				if ( id === current ) return;
				current = id;
				navigate( '/files' + ( id ? '?folder=' + id : '' ) );
			},
		} );
	} catch ( e ) {
		error.value = errorMessage( e );
		state.value = 'error';
	}
};
onMounted( start );
onBeforeUnmount( () => {
	if ( inst ) inst.unmount();
	inst = null;
} );
watch( () => props.query.folder, ( v ) => {
	const id = Number( v ) || 0;
	if ( inst && id !== current ) {
		current = id;
		inst.open( id );
	}
} );
</script>

<template>
	<div class="bd-files-explorer">
		<div v-if="state === 'loading'" v-loading="true" class="bd-files-explorer__wait"></div>
		<el-alert v-if="state === 'error'" type="error" show-icon :closable="false" :title="error">
			<el-button size="small" @click="start">Try again</el-button>
		</el-alert>
		<div v-show="state === 'ready'" ref="host" class="wpflatfiles-root wpflatfiles-app bd-files-explorer__host"></div>
	</div>
</template>

<style>
.bd-files-explorer { margin-top: 8px; }
.bd-files-explorer__host { height: calc(100vh - var(--bd-adminbar, 32px) - 120px); min-height: 520px; }
.bd-files-explorer__wait { height: 240px; }
</style>
