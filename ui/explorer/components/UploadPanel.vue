<script setup>
import { computed, inject, ref } from 'vue';
import Icon from './Icon.vue';
import { fmtSize } from '../util';

/** The transfer list at the bottom right: one row per file with a progress bar, cancel and retry. */
const fx = inject( 'fx' );
const open = ref( true );
const list = computed( () => fx.uploader.list );
const active = computed( () => list.value.filter( ( t ) => t.status === 'queued' || t.status === 'uploading' ).length );
const failed = computed( () => list.value.filter( ( t ) => t.status === 'error' ).length );
const title = computed( () => {
	if ( active.value ) return 'Uploading ' + active.value + ( active.value === 1 ? ' file' : ' files' );
	if ( failed.value ) return failed.value + ( failed.value === 1 ? ' upload failed' : ' uploads failed' );
	return 'Uploads complete';
} );
const pct = ( t ) => Math.round( ( t.status === 'done' ? 1 : t.progress ) * 100 );
const label = ( t ) => {
	if ( t.status === 'error' ) return t.error;
	if ( t.status === 'done' ) return 'Done · ' + fmtSize( t.size );
	if ( t.status === 'cancelled' ) return 'Cancelled';
	if ( t.status === 'queued' ) return 'Waiting…';
	return pct( t ) + '% of ' + fmtSize( t.size );
};
</script>

<template>
	<section v-if="list.length" class="fx-up" aria-label="Uploads">
		<header class="fx-up__head" @click="open = ! open">
			<span class="fx-up__title" role="status" aria-live="polite">{{ title }}</span>
			<button type="button" class="fx-iconbtn" :aria-label="open ? 'Collapse uploads' : 'Expand uploads'" :aria-expanded="String( open )" @click.stop="open = ! open"><Icon name="chev-d" :size="15" :style="{ transform: open ? '' : 'rotate(180deg)' }" /></button>
			<button v-if="! active" type="button" class="fx-iconbtn" aria-label="Close uploads" @click.stop="fx.uploader.clearFinished()"><Icon name="x" :size="15" /></button>
		</header>
		<ul v-show="open" class="fx-up__list">
			<li v-for="t in list" :key="t.id" class="fx-up__row" :class="'is-' + t.status" :data-status="t.status">
				<div class="fx-up__main">
					<div class="fx-up__name" :title="t.path || t.name">{{ t.name }}</div>
					<div class="fx-up__sub">{{ label( t ) }}</div>
					<div v-if="t.status === 'uploading' || t.status === 'queued'" class="fx-bar" role="progressbar" :aria-label="'Uploading ' + t.name" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="pct( t )"><span :style="{ width: pct( t ) + '%' }"></span></div>
				</div>
				<button v-if="t.status === 'queued' || t.status === 'uploading'" type="button" class="fx-iconbtn" :aria-label="'Cancel ' + t.name" @click="fx.uploader.cancel( t )"><Icon name="x" :size="15" /></button>
				<button v-else-if="t.status === 'error' || t.status === 'cancelled'" type="button" class="fx-iconbtn" :aria-label="'Try ' + t.name + ' again'" title="Try again" @click="fx.uploader.retry( t )"><Icon name="refresh" :size="15" /></button>
				<Icon v-else name="check" :size="16" class="fx-up__ok" />
			</li>
		</ul>
	</section>
</template>
