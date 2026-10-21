import { Files } from 'lucide-vue-next';
import { sdk } from './core';
import { canFiles, canFilesSettings } from './util';
import FilesExplorer from './views/FilesExplorer.vue';
import FilesSettingsRoute from './views/FilesSettingsRoute.vue';
import FilesSettings from './settings/FilesSettings.vue';
import HomeStats from './slots/HomeStats.vue';

/** WPFlat Files registers its screens and what it adds to WPFlat Core's pages. The explorer itself is a separate script, loaded when /files opens. */
const M = 'files';
sdk.registerIcon( 'files', Files );

sdk.registerRoute( { path: '/files', module: M, component: FilesExplorer } );
sdk.registerRoute( { path: '/files/settings', module: M, component: FilesSettingsRoute } );

sdk.registerSlot( 'home.stats', { id: 'files', module: M, order: 45, component: HomeStats, when: canFiles } );
sdk.registerSlot( 'settings.tabs', { id: 'files', module: M, order: 45, label: 'Files', component: FilesSettings, when: canFilesSettings } );
