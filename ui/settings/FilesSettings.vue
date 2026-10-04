<script setup>
import { onMounted, reactive, ref } from 'vue';
import { api, errorMessage, ElMessage } from '../core';

/** File Explorer settings: the default look, upload limits, the Recycle Bin. Used by /files/settings and by the Settings page tab. */
const form = reactive( { theme: 'win11', theme_switch: true, users_can_upload: true, max_mb: 500, chunk_mb: 4, trash_days: 30, default_view: 'details' } );
const themes = ref( {} );
const loaded = ref( false );
const saving = ref( false );
const views = [ [ 'details', 'Details' ], [ 'list', 'List' ], [ 'tiles', 'Tiles' ], [ 'icons', 'Large icons' ] ];

onMounted( async () => {
	try {
		const [ s, c ] = await Promise.all( [ api.getPath( 'fm/settings' ), api.getPath( 'fm/config' ) ] );
		Object.assign( form, s );
		themes.value = c.themes || {};
		loaded.value = true;
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
} );
const save = async () => {
	saving.value = true;
	try {
		Object.assign( form, await api.post( 'fm/settings', { ...form } ) );
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
		<el-button type="primary" :loading="saving" @click="save">Save settings</el-button>
	</div>
</template>
