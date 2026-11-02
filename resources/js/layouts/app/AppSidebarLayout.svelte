<script lang="ts">
    import { page } from '@inertiajs/svelte';
    import type { Snippet } from 'svelte';
    import AppContent from '@/components/AppContent.svelte';
    import AppShell from '@/components/AppShell.svelte';
    import AppSidebar from '@/components/AppSidebar.svelte';
    import AppSidebarHeader from '@/components/AppSidebarHeader.svelte';
    import { Toaster } from '@/components/ui/sonner';
    import type { BreadcrumbItem, PlanToolbar } from '@/types';

    let {
        breadcrumbs = [],
        planToolbar = null,
        children,
    }: {
        breadcrumbs?: BreadcrumbItem[];
        planToolbar?: PlanToolbar | null;
        children?: Snippet;
    } = $props();
</script>

<AppShell variant="sidebar">
    <AppSidebar />
    <AppContent variant="sidebar" class="min-w-0 overflow-x-clip">
        {#if page.component !== 'household/Overview'}
            <AppSidebarHeader {breadcrumbs} {planToolbar} />
        {/if}
        {@render children?.()}
    </AppContent>
    <Toaster />
</AppShell>
