<script setup>
import { onMounted, reactive, ref } from 'vue';
import { computed } from 'vue';
import { api, errorMessage, ElMessage, store, sdk } from '../core';

/** File Explorer settings: the default look, upload limits, the Recycle Bin. Used by /files/settings and by the Settings page tab. */
const form = reactive( { theme: 'win11', theme_switch: true, users_can_upload: true, max_mb: 500, chunk_mb: 4, trash_days: 30, default_view: 'details', front_enabled: false, front_slug: 'files' } );
const frontUrl = ref( '' );
const home = computed( () => String( ( store && store.homeUrl ) || '' ).replace( /\/+$/, '' ) );
const slug = computed( () => String( form.front_slug || '' ).trim().replace( /^\/+|\/+$/g, '' ) );
const note = computed( () => sdk.fmt( 'People with a {client} record also get Files in their portal. People who have a linked role instead (teachers and so on) sign in here.' ) );
const roles = [
	[ 'Bizy File Manager', 'Create, rename, move, delete and upload everywhere. Cannot change who has access.' ],
	[ 'Bizy File Contributor', 'See everything and add files anywhere.' ],
	[ 'Bizy File Viewer', 'See and download everything only.' ],
	[ 'Bizy Files Admin', 'Full control, including who has access.' ],
];
const rolesHow = computed( () => sdk.fmt( 'Give someone one of these roles under Users → Edit user → Role, or add the capabilities bizyfiles_view_all, bizyfiles_upload, bizyfiles_edit to a role you made in your role editor. These roles apply to every folder; use {groups} to give someone access to particular folders.' ).replace( '{groups}', 'groups' ) );
const preview = computed( () => home.value + '/' + ( slug.value || 'files' ) + '/' );
const themes = ref( {} );
const loaded = ref( false );
const saving = ref( false );
const views = [ [ 'details', 'Details' ], [ 'list', 'List' ], [ 'tiles', 'Tiles' ], [ 'icons', 'Large icons' ] ];

onMounted( async () => {
	try {
		const [ s, c ] = await Promise.all( [ api.getPath( 'fm/settings' ), api.getPath( 'fm/config' ) ] );
		Object.assign( form, s );
		themes.value = c.themes || {};
		frontUrl.value = c.front_url || '';
		loaded.value = true;
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
} );
const save = async () => {
	saving.value = true;
	try {
		Object.assign( form, await api.post( 'fm/settings', { ...form } ) );
		try {
			frontUrl.value = ( await api.getPath( 'fm/config' ) ).front_url || '';
		} catch ( e ) {
			frontUrl.value = '';
		}
		ElMessage.success( 'File explorer settings saved' );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		saving.value = false;
	}
};
</script>

<template>
	<div v-loading="! loaded" class="bd-filessettings">
		<el-card shadow="never" class="bd-mb">
			<template #header><div class="bd-widget__head"><span>Look</span></div></template>
			<el-form label-position="top" style="max-width: 640px" @submit.prevent>
				<el-form-item label="Default theme">
					<el-select v-model="form.theme" style="width: 260px">
						<el-option v-for="( label, id ) in themes" :key="id" :label="label" :value="id" />
					</el-select>
				</el-form-item>
				<el-form-item>
					<el-switch v-model="form.theme_switch" active-text="Let people pick their own theme" />
				</el-form-item>
				<el-form-item label="Default view">
					<el-radio-group v-model="form.default_view">
						<el-radio-button v-for="v in views" :key="v[ 0 ]" :value="v[ 0 ]">{{ v[ 1 ] }}</el-radio-button>
					</el-radio-group>
				</el-form-item>
			</el-form>
		</el-card>
		<el-card shadow="never" class="bd-mb">
			<template #header><div class="bd-widget__head"><span>Uploads and the Recycle Bin</span></div></template>
			<el-form label-position="top" style="max-width: 640px" @submit.prevent>
				<el-form-item>
					<el-switch v-model="form.users_can_upload" active-text="Let people on the front end add files (where a folder allows it)" />
				</el-form-item>
				<el-form-item label="Largest file (MB)">
					<el-input-number v-model="form.max_mb" :min="1" :max="5000" controls-position="right" />
				</el-form-item>
				<el-form-item label="Upload piece size (MB)">
					<el-input-number v-model="form.chunk_mb" :min="1" :max="32" controls-position="right" />
					<div class="bd-help">Files are sent in pieces of this size. Lower it if uploads fail on slow connections or strict hosts.</div>
				</el-form-item>
				<el-form-item label="Keep deleted items for (days)">
					<el-input-number v-model="form.trash_days" :min="0" :max="3650" controls-position="right" />
					<div class="bd-help">After this time the Recycle Bin empties itself. 0 keeps items until someone empties it.</div>
				</el-form-item>
			</el-form>
		</el-card>
		<el-card shadow="never" class="bd-mb">
			<template #header><div class="bd-widget__head"><span>Front-end page</span></div></template>
			<el-form label-position="top" style="max-width: 640px" @submit.prevent>
				<el-form-item>
					<el-switch v-model="form.front_enabled" active-text="Let anyone signed in who has access open Files at their own page" />
				</el-form-item>
				<el-form-item label="Page address">
					<el-input v-model="form.front_slug" :disabled="! form.front_enabled" placeholder="files" style="max-width: 320px" aria-label="Page address" />
					<div class="bd-help">Address: <code class="bd-front-preview">{{ preview }}</code></div>
				</el-form-item>
				<p class="bd-help">{{ note }}</p>
				<p v-if="frontUrl"><a class="bd-link" :href="frontUrl" target="_blank" rel="noopener">Open page</a></p>
			</el-form>
		</el-card>
		<el-card shadow="never" class="bd-mb">
			<template #header><div class="bd-widget__head"><span>Roles</span></div></template>
			<ul class="bd-help" style="padding-left: 18px; margin: 0 0 8px">
				<li v-for="r in roles" :key="r[ 0 ]"><strong>{{ r[ 0 ] }}</strong>: {{ r[ 1 ] }}</li>
			</ul>
			<p class="bd-help">{{ rolesHow }}</p>
		</el-card>
		<el-button type="primary" :loading="saving" @click="save">Save settings</el-button>
	</div>
</template>
