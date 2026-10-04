<script setup>
import { Folder } from '@element-plus/icons-vue';
import { StatCard } from '../core';

defineProps( { home: { type: Object, default: null } } );
</script>

<template>
	<el-col v-if="home && home.stats.files_total !== undefined" :xs="12" :md="6">
		<StatCard label="Files" :icon="Folder" sub="In the file explorer" to="/files"><span class="bd-stat__text">{{ home.stats.files_total }}</span></StatCard>
	</el-col>
</template>
