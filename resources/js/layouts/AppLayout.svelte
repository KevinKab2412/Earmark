<script lang="ts">
    import type { Snippet } from 'svelte';
    import AppLayout from '@/layouts/app/AppSidebarLayout.svelte';
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

<AppLayout {breadcrumbs} {planToolbar}>
    {@render children?.()}
</AppLayout>
