<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import Breadcrumbs from '@/components/Breadcrumbs.svelte';
    import { Button } from '@/components/ui/button';
    import { SidebarTrigger } from '@/components/ui/sidebar';
    import type { BreadcrumbItem, PlanToolbar } from '@/types';

    let {
        breadcrumbs = [],
        planToolbar = null,
    }: {
        breadcrumbs?: BreadcrumbItem[];
        planToolbar?: PlanToolbar | null;
    } = $props();
</script>

<header
    class="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/70 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4"
>
    <div class="flex min-w-0 flex-1 items-center gap-2">
        <SidebarTrigger class="-ml-1" />
        {#if planToolbar}
            <div class="hidden min-w-0 flex-1 items-center justify-between gap-4 lg:flex">
                <div class="flex min-w-0 items-center gap-2">
                    <Button variant="outline" size="sm" asChild>
                        {#snippet children(props)}
                            <Link href={planToolbar.previousUrl} class={props.class} aria-label="Previous month" data-test="plan-prev-month">←</Link>
                        {/snippet}
                    </Button>
                    <h1 class="min-w-36 whitespace-nowrap text-center text-lg font-semibold" data-test="plan-current-month">{planToolbar.monthLabel}</h1>
                    <Button variant="outline" size="sm" asChild>
                        {#snippet children(props)}
                            <Link href={planToolbar.nextUrl} class={props.class} aria-label="Next month" data-test="plan-next-month">→</Link>
                        {/snippet}
                    </Button>
                    <Button variant="outline" size="sm" asChild>
                        {#snippet children(props)}
                            <Link href={planToolbar.todayUrl} class={props.class} data-test="plan-today">Today</Link>
                        {/snippet}
                    </Button>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <Button type="button" variant="outline" size="sm" onclick={planToolbar.onAddGroup}>Add group</Button>
                    <Button type="button" size="sm" class="bg-emerald-600 text-white hover:bg-emerald-700" onclick={planToolbar.onAddCategory}>Add category</Button>
                </div>
            </div>
            {#if breadcrumbs.length > 0}
                <div class="min-w-0 lg:hidden"><Breadcrumbs {breadcrumbs} /></div>
            {/if}
        {:else if breadcrumbs.length > 0}
            <Breadcrumbs {breadcrumbs} />
        {/if}
    </div>
</header>
