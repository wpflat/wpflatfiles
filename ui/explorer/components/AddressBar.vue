<script setup>
import { computed, inject, ref, watch } from 'vue';
import Icon from './Icon.vue';
import { dropTarget } from '../dnd';
import { debounce } from '../util';

const fx = inject( 'fx' );
const input = ref( null );

const segments = computed( () => {
	const out = [ { id: 0, name: 'Home', home: true } ];
	if ( fx.st.trash ) return [ ...out, { id: -1, name: 'Recycle Bin', here: true } ];
	if ( fx.searching ) return [ ...out, ...fx.crumbs.map( ( c ) => ( { ...c } ) ), { id: -1, name: 'Search results for “' + fx.st.query + '”', here: true } ];
	fx.crumbs.forEach( ( c ) => out.push( { ...c } ) );
	return out;
} );
const limit = computed( () => ( fx.st.narrow ? 2 : 4 ) );
const shown = computed( () => {
	const s = segments.value;
	if ( s.length <= limit.value ) return { hidden: [], list: s };
	const keep = limit.value - 1;
	return { hidden: s.slice( 0, s.length - keep ), list: s.slice( s.length - keep ) };
} );
const openHidden = ( e ) => {
	const r = e.currentTarget.getBoundingClientRect();
	fx.openMenu( shown.value.hidden.map( ( s ) => ( { label: s.name, icon: s.home ? 'home' : 'folder', action: () => fx.openFolder( s.id ) } ) ), r.left, r.bottom + 4 );
};
const go = ( s ) => {
	if ( s.here ) return;
	fx.openFolder( s.id );
};

const run = debounce( ( v ) => fx.search( v ), 380 );
const onInput = () => run( fx.st.searchText );
const submit = () => {
	run.cancel();
	fx.search( fx.st.searchText );
};
const clear = () => {
	run.cancel();
	fx.st.searchText = '';
	fx.search( '' );
};
const key = ( e ) => {
	if ( e.key === 'Escape' ) {
		e.stopPropagation();
		if ( fx.st.searchText ) clear();
		else {
			input.value.blur();
			fx.focusItems();
		}
	}
};
watch( () => fx.st.query, ( q ) => {
	if ( q === '' && fx.st.searchText && ! run ) fx.st.searchText = '';
} );
const placeholder = computed( () => 'Search ' + ( fx.st.folder ? fx.st.folder.name : 'files' ) );
</script>

<template>
	<div class="fx-addr">
		<div class="fx-addr__nav">
			<button v-if="fx.st.narrow" type="button" class="fx-iconbtn fx-addr__burger" aria-label="Show folders" :aria-expanded="String( fx.st.navOpen )" @click="fx.st.navOpen = ! fx.st.navOpen"><Icon name="menu" :size="18" /></button>
			<button type="button" class="fx-iconbtn" aria-label="Back" title="Back (Alt+Left)" :disabled="! fx.st.back.length" @click="fx.goBack()"><Icon name="back" :size="16" /></button>
			<button type="button" class="fx-iconbtn fx-addr__fwd" aria-label="Forward" title="Forward (Alt+Right)" :disabled="! fx.st.fwd.length" @click="fx.goForward()"><Icon name="forward" :size="16" /></button>
			<button type="button" class="fx-iconbtn" aria-label="Up one level" title="Up (Backspace)" :disabled="! fx.canGoUp" @click="fx.up()"><Icon name="up" :size="16" /></button>
		</div>
		<nav class="fx-crumbs" aria-label="Path">
			<span class="fx-crumbs__ic" aria-hidden="true"><Icon :name="fx.st.trash ? 'trash' : 'folder'" :size="16" /></span>
			<ol class="fx-crumbs__list">
				<li v-if="shown.hidden.length" class="fx-crumbs__li">
					<button type="button" class="fx-crumb" aria-label="Show earlier folders" @click="openHidden">…</button>
					<Icon class="fx-crumbs__sep" name="chev-r" :size="12" />
				</li>
				<li v-for="( s, i ) in shown.list" :key="s.id + '-' + i" class="fx-crumbs__li">
					<Icon v-if="i > 0 || shown.hidden.length" class="fx-crumbs__sep" name="chev-r" :size="12" />
					<button type="button" class="fx-crumb" :class="{ 'is-here': s.here || i === shown.list.length - 1, 'is-home': s.home, 'is-drop': fx.st.dropOn === s.id && s.id > 0 }" :aria-current="i === shown.list.length - 1 ? 'page' : undefined" v-bind="s.id > 0 ? dropTarget( fx, s.id ) : {}" @click="go( s )">
						<Icon v-if="s.home" name="home" :size="14" /><span class="fx-crumb__t">{{ s.name }}</span>
					</button>
				</li>
			</ol>
			<button type="button" class="fx-iconbtn fx-crumbs__refresh" aria-label="Refresh" title="Refresh (F5)" @click="fx.refresh()"><Icon name="refresh" :size="15" /></button>
		</nav>
		<div class="fx-search" role="search">
			<Icon name="search" :size="15" />
			<input ref="input" v-model="fx.st.searchText" type="search" class="fx-search__in" :placeholder="placeholder" :aria-label="placeholder" autocomplete="off" @input="onInput" @keydown.enter.prevent="submit" @keydown="key" />
			<button v-if="fx.st.searchText" type="button" class="fx-iconbtn fx-search__x" aria-label="Clear search" @click="clear"><Icon name="x" :size="13" /></button>
		</div>
	</div>
</template>
