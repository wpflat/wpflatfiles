<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import Icon from './Icon.vue';

/** The shell every dialog uses: a titled panel over a dimmed backdrop. Esc closes, Tab stays inside, focus returns afterwards. */
const props = defineProps( { title: { type: String, required: true }, wide: Boolean, narrow: Boolean } );
const emit = defineEmits( [ 'close' ] );
const el = ref( null );
let before = null;
const uid = 'fxm' + Math.random().toString( 36 ).slice( 2, 8 );

const focusables = () => [ ...el.value.querySelectorAll( 'button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [href], [tabindex]:not([tabindex="-1"])' ) ].filter( ( e ) => e.offsetParent !== null || e.getClientRects().length || true );
onMounted( () => {
	before = document.activeElement;
	const first = el.value.querySelector( '[data-autofocus]' ) || el.value.querySelector( 'input:not([type=checkbox]), textarea, select' ) || el.value.querySelector( '.fx-modal__body button, .fx-modal__foot button' ) || el.value;
	first.focus && first.focus();
	if ( first.select && first.tagName === 'INPUT' ) first.select();
} );
onBeforeUnmount( () => {
	if ( before && before.focus && document.contains( before ) ) before.focus( { preventScroll: true } );
} );
const key = ( e ) => {
	if ( e.key === 'Escape' ) {
		e.stopPropagation();
		e.preventDefault();
		emit( 'close' );
	} else if ( e.key === 'Tab' ) {
		const f = focusables();
		if ( ! f.length ) return;
		const first = f[ 0 ];
		const last = f[ f.length - 1 ];
		if ( e.shiftKey && document.activeElement === first ) {
			e.preventDefault();
			last.focus();
		} else if ( ! e.shiftKey && document.activeElement === last ) {
			e.preventDefault();
			first.focus();
		}
	}
};
</script>

<template>
	<div class="fx-modal__back" @pointerdown.self="emit( 'close' )">
		<div ref="el" class="fx-modal" :class="{ 'fx-modal--wide': wide, 'fx-modal--narrow': narrow }" role="dialog" aria-modal="true" :aria-labelledby="uid" tabindex="-1" @keydown="key">
			<header class="fx-modal__head">
				<h2 :id="uid" class="fx-modal__title">{{ title }}</h2>
				<button type="button" class="fx-iconbtn" aria-label="Close" @click="emit( 'close' )"><Icon name="x" :size="16" /></button>
			</header>
			<div class="fx-modal__body"><slot /></div>
			<footer v-if="$slots.footer" class="fx-modal__foot"><slot name="footer" /></footer>
		</div>
	</div>
</template>
