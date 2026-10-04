// BizyFiles' app screens run inside BizyCore's page: everything they share comes from window.BizyCore.
const bc = window.BizyCore;
export const { sdk, api, errorMessage, store, navigate, util, ui, components } = bc;
export const { ElMessage } = ui;
export const { PageHeader, StatCard } = components;
