<script setup>
import { PageHeader } from '../core';
import FilesSettings from '../settings/FilesSettings.vue';
</script>

<template>
	<div>
		<PageHeader title="File Explorer settings" description="The look of the file explorer, upload limits and the Recycle Bin." />
		<FilesSettings />
	</div>
</template>
