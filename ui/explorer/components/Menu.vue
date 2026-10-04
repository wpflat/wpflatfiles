<script setup>
import { computed, inject, nextTick, onMounted, ref } from 'vue';
import Icon from './Icon.vue';

/** A popup menu: right-click menus, command-bar dropdowns, folder colours. Items: { label, icon, hint, action, disabled, checked, danger, children, sep, swatch } */
const fx = inject( 'fx' );
const props = defineProps( { menu: { type: Object, required: true } } );
const el = ref( null );
const pos = ref( { left: props.menu.x, top: props.menu.y } );
const sub = ref( -1 );
const active = ref( -1 );
const flip = ref( false );

const list = computed( () => {
	const out = [];
	props.menu.items.forEach( ( it ) => {
		if ( it.sep && ( ! out.length || out[ out.length - 1 ].sep ) ) return;
		out.push( it );
	} );
	while ( out.length && out[ out.length - 1 ].sep ) out.pop();
	return out;
} );
const usable = ( i ) => list.value[ i ] && ! list.value[ i ].sep && ! list.value[ i ].disabled;

onMounted( async () => {
	await nextTick();
	const e = el.value;
	if ( ! e ) return;
	const w = e.offsetWidth || 220;
	const h = e.offsetHeight || 200;
	const vw = window.innerWidth || 1024;
	const vh = window.innerHeight || 768;
	pos.value = {
		left: Math.max( 6, Math.min( props.menu.x, vw - w - 6 ) ),
		top: Math.max( 6, Math.min( props.menu.y, vh - h - 6 ) ),
	};
	flip.value = pos.value.left + w * 2 + 12 > vw;
	e.focus( { preventScroll: true } );
} );

const run = ( it ) => {
	if ( it.sep || it.disabled ) return;
	if ( it.children ) {
		sub.value = sub.value === list.value.indexOf( it ) ? -1 : list.value.indexOf( it );
		return;
	}
	fx.closeMenu();
	if ( it.action ) setTimeout( () => it.action(), 0 );
};
const step = ( d ) => {
	let i = active.value;
	for ( let n = 0; n < list.value.length; n++ ) {
		i = ( i + d + list.value.length ) % list.value.length;
		if ( usable( i ) ) {
			active.value = i;
			return;
		}
	}
};
const hover = ( i, it ) => {
	active.value = i;
	if ( fx.st.narrow ) return;
	sub.value = it.children ? i : -1;
};
const key = ( e ) => {
	if ( e.key === 'Escape' ) {
		e.preventDefault();
		e.stopPropagation();
		fx.closeMenu();
		fx.focusItems();
	} else if ( e.key === 'ArrowDown' ) {
		e.preventDefault();
		step( 1 );
	} else if ( e.key === 'ArrowUp' ) {
		e.preventDefault();
		step( -1 );
	} else if ( e.key === 'ArrowRight' && list.value[ active.value ] && list.value[ active.value ].children ) {
		e.preventDefault();
		sub.value = active.value;
	} else if ( e.key === 'ArrowLeft' ) {
		e.preventDefault();
		if ( sub.value >= 0 ) sub.value = -1;
		else fx.closeMenu();
	} else if ( e.key === 'Enter' || e.key === ' ' ) {
		e.preventDefault();
		if ( usable( active.value ) ) run( list.value[ active.value ] );
	} else if ( e.key === 'Tab' ) {
		e.preventDefault();
	}
};
</script>

<template>
	<div class="fx-menu__layer" @pointerdown.self="fx.closeMenu()" @contextmenu.prevent.self="fx.closeMenu()">
		<div ref="el" class="fx-menu" :class="{ 'fx-menu--flip': flip }" role="menu" tabindex="-1" :aria-label="menu.label || 'Menu'" :style="{ left: pos.left + 'px', top: pos.top + 'px' }" @keydown="key">
			<template v-for="( it, i ) in list" :key="i">
				<div v-if="it.sep" class="fx-menu__sep" role="separator"></div>
				<div v-else class="fx-menu__row" @mouseenter="hover( i, it )">
					<button
						type="button"
						class="fx-menu__item"
						:class="{ 'is-danger': it.danger, 'is-active': active === i, 'has-sub': it.children }"
						:role="it.checked !== undefined ? 'menuitemradio' : 'menuitem'"
						:aria-checked="it.checked !== undefined ? String( !! it.checked ) : undefined"
						:aria-haspopup="it.children ? 'menu' : undefined"
						:aria-expanded="it.children ? String( sub === i ) : undefined"
						:disabled="it.disabled"
						@click="run( it )"
					>
						<span class="fx-menu__ic"><Icon v-if="it.icon" :name="it.icon" :size="16" /><span v-else-if="it.swatch" class="fx-menu__sw" :style="{ background: it.swatch }"></span></span>
						<span class="fx-menu__t">{{ it.label }}</span>
						<span v-if="it.hint" class="fx-menu__hint">{{ it.hint }}</span>
						<Icon v-if="it.checked" class="fx-menu__check" name="check" :size="14" />
						<Icon v-if="it.children" class="fx-menu__chev" name="chev-r" :size="14" />
					</button>
					<div v-if="it.children && sub === i" class="fx-menu fx-menu__sub" role="menu" :aria-label="it.label">
						<template v-for="( c, j ) in it.children" :key="j">
							<div v-if="c.sep" class="fx-menu__sep" role="separator"></div>
							<button v-else type="button" class="fx-menu__item" :role="c.checked !== undefined ? 'menuitemradio' : 'menuitem'" :aria-checked="c.checked !== undefined ? String( !! c.checked ) : undefined" :disabled="c.disabled" @click="run( c )">
								<span class="fx-menu__ic"><span v-if="c.swatch" class="fx-menu__sw" :style="{ background: c.swatch }"></span></span>
								<span class="fx-menu__t">{{ c.label }}</span>
								<Icon v-if="c.checked" class="fx-menu__check" name="check" :size="14" />
							</button>
						</template>
					</div>
				</div>
			</template>
		</div>
	</div>
</template>
