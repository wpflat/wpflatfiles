<script setup>
import { computed, ref, watch } from 'vue';
import FileIcon from './FileIcon.vue';
import Icon from './Icon.vue';
import { CSV_MAX_COLS, CSV_MAX_ROWS, TEXT_EXT, iconKind, parseCsv, sniffDelimiter } from '../util';

/** Shows a file the way the browser can: image, PDF, video, audio, text. Anything else gets its icon and a download. */
const props = defineProps( { file: { type: Object, required: true }, large: Boolean } );
const text = ref( '' );
const state = ref( 'idle' );
const failed = ref( false );
const isCsv = computed( () => [ 'csv', 'tsv' ].includes( String( props.file.ext || '' ).toLowerCase() ) );
const table = computed( () => {
	if ( mode.value !== 'csv' || state.value !== 'ready' ) return null;
	const rows = parseCsv( text.value, sniffDelimiter( text.value, props.file.ext ), CSV_MAX_ROWS + 2 );
	if ( ! rows.length ) return { head: [], body: [], more: false, cols: 0, wide: false };
	const width = rows.reduce( ( n, r ) => Math.max( n, r.length ), 0 );
	const cut = ( r ) => {
		const o = r.slice( 0, CSV_MAX_COLS );
		while ( o.length < Math.min( width, CSV_MAX_COLS ) ) o.push( '' );
		return o;
	};
	return { head: cut( rows[ 0 ] ), body: rows.slice( 1, CSV_MAX_ROWS + 1 ).map( cut ), more: rows.length > CSV_MAX_ROWS + 1, cols: width, wide: width > CSV_MAX_COLS };
} );
const isText = computed( () => props.file.kind === 'text' || TEXT_EXT.includes( String( props.file.ext || '' ).toLowerCase() ) );
const mode = computed( () => {
	const f = props.file;
	if ( f.is_link ) return 'link';
	if ( isCsv.value && f.inline !== false ) return 'csv';
	if ( isText.value && f.inline !== false ) return 'text';
	if ( f.kind === 'image' ) return f.inline || f.thumb_url ? 'image' : 'none';
	if ( ! f.inline ) return 'none';
	return { pdf: 'pdf', video: 'video', audio: 'audio' }[ f.kind ] || 'none';
} );
const imgSrc = computed( () => ( failed.value && props.file.thumb_url ? props.file.thumb_url : props.file.inline ? props.file.view_url : props.file.thumb_url ) );

watch(
	() => props.file.id + ':' + props.file.version + ':' + props.file.embed_url,
	async () => {
		failed.value = false;
		text.value = '';
		state.value = 'idle';
		if ( mode.value !== 'text' && mode.value !== 'csv' ) return;
		state.value = 'loading';
		try {
			const res = await fetch( props.file.view_url, { credentials: 'same-origin', headers: { Range: 'bytes=0-98303' } } );
			if ( ! res.ok ) throw new Error( 'bad' );
			const t = await res.text();
			text.value = t.length > 90000 ? t.slice( 0, 90000 ) + '\n…' : t;
			state.value = 'ready';
		} catch ( e ) {
			state.value = 'error';
		}
	},
	{ immediate: true }
);
</script>

<template>
	<div class="fx-prev" :class="[ 'fx-prev--' + mode, large && 'is-large' ]">
		<div v-if="mode === 'link'" class="fx-prev__link">
			<div v-if="file.embed_url" class="fx-prev__embed"><iframe :src="file.embed_url" :title="file.name" sandbox="allow-scripts allow-same-origin allow-presentation allow-popups" allow="fullscreen; picture-in-picture" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen loading="lazy"></iframe></div>
			<div v-else class="fx-prev__none"><FileIcon type="file" :kind="iconKind( file )" :size="large ? 96 : 64" /></div>
			<div class="fx-prev__addr"><a v-if="! file.embed_url" class="fx-primary fx-prev__open" :href="file.link_url" target="_blank" rel="noopener noreferrer"><Icon name="external" :size="15" /> Open link</a><span class="fx-prev__url">{{ file.link_url }}</span></div>
		</div>
		<img v-else-if="mode === 'image'" class="fx-prev__img" :src="imgSrc" :alt="file.name" @error="failed = true" />
		<iframe v-else-if="mode === 'pdf'" class="fx-prev__frame" :src="file.view_url" :title="'Preview of ' + file.name"></iframe>
		<video v-else-if="mode === 'video'" class="fx-prev__media" :src="file.view_url" controls preload="metadata" playsinline></video>
		<div v-else-if="mode === 'audio'" class="fx-prev__audio"><FileIcon type="file" kind="audio" :size="large ? 96 : 64" /><audio :src="file.view_url" controls preload="metadata"></audio></div>
		<template v-else-if="mode === 'csv'">
			<div v-if="state === 'loading'" class="fx-prev__note">Loading…</div>
			<div v-else-if="state === 'error'" class="fx-prev__note"><Icon name="alert" :size="18" /> The preview could not be loaded.</div>
			<div v-else-if="table" class="fx-csv">
				<div class="fx-csv__scroll">
					<table class="fx-csv__table">
						<thead><tr><th v-for="( h, c ) in table.head" :key="c">{{ h }}</th></tr></thead>
						<tbody><tr v-for="( r, n ) in table.body" :key="n"><td v-for="( v, c ) in r" :key="c">{{ v }}</td></tr></tbody>
					</table>
				</div>
				<p v-if="table.more || table.wide" class="fx-csv__note">Showing the first {{ table.more ? table.body.length + ' rows' : table.head.length + ' columns' }}{{ table.more && table.wide ? ' and ' + table.head.length + ' columns' : '' }}.</p>
			</div>
		</template>
		<template v-else-if="mode === 'text'">
			<div v-if="state === 'loading'" class="fx-prev__note">Loading…</div>
			<div v-else-if="state === 'error'" class="fx-prev__note"><Icon name="alert" :size="18" /> The preview could not be loaded.</div>
			<pre v-else class="fx-prev__text">{{ text }}</pre>
		</template>
		<div v-else class="fx-prev__none"><FileIcon type="file" :kind="iconKind( file )" :size="large ? 96 : 64" /><div class="fx-prev__note">No preview for this kind of file.</div></div>
	</div>
</template>
