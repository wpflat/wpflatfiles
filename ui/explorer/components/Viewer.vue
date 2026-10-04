<script setup>
import { computed, inject, onBeforeUnmount, onMounted, ref } from 'vue';
import Icon from './Icon.vue';
import Preview from './Preview.vue';
import { fmtSize } from '../util';

const fx = inject( 'fx' );
const el = ref( null );
let before = null;
const list = computed( () => fx.viewable );
const item = computed( () => list.value[ fx.st.viewer ] );
const step = ( d ) => {
	const n = list.value.length;
	if ( n > 1 ) fx.st.viewer = ( fx.st.viewer + d + n ) % n;
};
const close = () => fx.closeViewer();
onMounted( () => {
	before = document.activeElement;
	el.value && el.value.focus();
} );
onBeforeUnmount( () => {
	if ( item.value ) fx.selectKeys( [ item.value.key ] );
	if ( before && before.focus && document.contains( before ) ) before.focus( { preventScroll: true } );
} );
const key = ( e ) => {
	if ( e.key === 'Escape' ) close();
	else if ( e.key === 'ArrowRight' ) step( 1 );
	else if ( e.key === 'ArrowLeft' ) step( -1 );
	else return;
	e.preventDefault();
	e.stopPropagation();
};
let tx = 0;
const tstart = ( e ) => ( tx = e.changedTouches[ 0 ].clientX );
const tend = ( e ) => {
	const dx = e.changedTouches[ 0 ].clientX - tx;
	if ( Math.abs( dx ) > 70 ) step( dx < 0 ? 1 : -1 );
};
</script>

<template>
	<div v-if="item" ref="el" class="fx-viewer" role="dialog" aria-modal="true" :aria-label="'Preview of ' + item.name" tabindex="-1" @keydown="key">
		<header class="fx-viewer__bar">
			<div class="fx-viewer__name"><span class="fx-viewer__t">{{ item.name }}</span><span class="fx-viewer__s">{{ ( fx.st.viewer + 1 ) + ' of ' + list.length }} · {{ item.row.is_link ? 'Link' : fmtSize( item.row.size ) }}</span></div>
			<a v-if="! item.row.is_link" class="fx-secondary fx-viewer__dl" :href="item.row.download_url" :download="item.name"><Icon name="download" :size="15" /> <span>Download</span></a>
			<button type="button" class="fx-iconbtn" aria-label="Close preview" data-autofocus @click="close"><Icon name="x" :size="18" /></button>
		</header>
		<div class="fx-viewer__stage" @touchstart.passive="tstart" @touchend.passive="tend">
			<button v-if="list.length > 1" type="button" class="fx-viewer__nav is-prev" aria-label="Previous file" @click="step( -1 )"><Icon name="arrow-l" :size="22" /></button>
			<Preview :key="item.id" :file="item.row" large />
			<button v-if="list.length > 1" type="button" class="fx-viewer__nav is-next" aria-label="Next file" @click="step( 1 )"><Icon name="arrow-r" :size="22" /></button>
		</div>
	</div>
</template>
