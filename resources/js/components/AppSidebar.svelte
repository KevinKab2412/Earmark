<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import ArrowLeftRight from '@lucide/svelte/icons/arrow-left-right';
    import ArrowRightLeft from '@lucide/svelte/icons/arrow-right-left';
    import BadgeCheck from '@lucide/svelte/icons/badge-check';
    import CalendarRange from '@lucide/svelte/icons/calendar-range';
    import ChartColumn from '@lucide/svelte/icons/chart-column';
    import Database from '@lucide/svelte/icons/database';
    import Landmark from '@lucide/svelte/icons/landmark';
    import LayoutDashboard from '@lucide/svelte/icons/layout-dashboard';
    import LineChart from '@lucide/svelte/icons/line-chart';
    import ListFilter from '@lucide/svelte/icons/list-filter';
    import PieChart from '@lucide/svelte/icons/pie-chart';
    import Repeat from '@lucide/svelte/icons/repeat';
    import Scale from '@lucide/svelte/icons/scale';
    import SlidersHorizontal from '@lucide/svelte/icons/sliders-horizontal';
    import Tags from '@lucide/svelte/icons/tags';
    import Target from '@lucide/svelte/icons/target';
    import TrendingUp from '@lucide/svelte/icons/trending-up';
    import Upload from '@lucide/svelte/icons/upload';
    import Users from '@lucide/svelte/icons/users';
    import type { Snippet } from 'svelte';
    import AppLogo from '@/components/AppLogo.svelte';
    import NavFooter from '@/components/NavFooter.svelte';
    import NavMain from '@/components/NavMain.svelte';
    import NavUser from '@/components/NavUser.svelte';
    import {
        Sidebar,
        SidebarContent,
        SidebarFooter,
        SidebarHeader,
        SidebarMenu,
        SidebarMenuButton,
        SidebarMenuItem,
    } from '@/components/ui/sidebar';
    import { toUrl } from '@/lib/utils';
    import { dashboard } from '@/routes';
    import { index as accounts } from '@/routes/household/accounts';
    import { index as assumptions } from '@/routes/household/assumptions';
    import { index as categories } from '@/routes/household/categories';
    import { index as goals } from '@/routes/household/goals';
    import { index as holdings } from '@/routes/household/holdings';
    import { index as importStatements } from '@/routes/household/import';
    import { index as members } from '@/routes/household/members';
    import { show as netWorth } from '@/routes/household/net-worth';
    import { index as plan } from '@/routes/household/plan';
    import { index as portability } from '@/routes/household/portability';
    import { index as reconcile } from '@/routes/household/reconcile';
    import { index as recurring } from '@/routes/household/recurring';
    import { index as registered } from '@/routes/household/registered';
    import { index as reports } from '@/routes/household/reports';
    import { index as rules } from '@/routes/household/rules';
    import { index as scenarios } from '@/routes/household/scenarios';
    import { index as transactions } from '@/routes/household/transactions';
    import { index as transfers } from '@/routes/household/transfers';

    let {
        children,
    }: {
        children?: Snippet;
    } = $props();

    const mainNavItems = [
        { title: 'Dashboard', href: dashboard(), icon: LayoutDashboard },
        { title: 'Accounts', href: accounts(), icon: Landmark },
        { title: 'Transactions', href: transactions(), icon: ArrowLeftRight },
        { title: 'Reports', href: reports(), icon: ChartColumn },
        { title: 'Budget', href: plan(), icon: CalendarRange },
        { title: 'Recurring', href: recurring(), icon: Repeat },
        { title: 'Goals', href: goals(), icon: Target },
        { title: 'Investments', href: holdings(), icon: PieChart },
        { title: 'Forecasting', href: scenarios(), icon: LineChart },
    ];

    const toolNavItems = [
        { title: 'Import', href: importStatements(), icon: Upload },
        { title: 'Rules', href: rules(), icon: ListFilter },
        { title: 'Transfers', href: transfers(), icon: ArrowRightLeft },
        { title: 'Categories', href: categories(), icon: Tags },
        { title: 'Reconcile', href: reconcile(), icon: Scale },
        { title: 'Net worth', href: netWorth(), icon: TrendingUp },
        { title: 'Registered', href: registered(), icon: BadgeCheck },
        { title: 'Assumptions', href: assumptions(), icon: SlidersHorizontal },
        { title: 'Members', href: members(), icon: Users },
        { title: 'Data', href: portability(), icon: Database },
    ];
</script>

<Sidebar collapsible="icon" variant="sidebar">
    <SidebarHeader>
        <SidebarMenu>
            <SidebarMenuItem>
                <SidebarMenuButton size="lg" asChild>
                    {#snippet children(props)}
                        <Link
                            {...props}
                            href={toUrl(dashboard())}
                            class={props.class}
                        >
                            <AppLogo />
                        </Link>
                    {/snippet}
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarHeader>

    <SidebarContent>
        <NavMain items={mainNavItems} />
        <NavMain label="More" items={toolNavItems} />
    </SidebarContent>

    <SidebarFooter>
        <NavFooter items={[]} />
        <NavUser />
    </SidebarFooter>
</Sidebar>
{@render children?.()}
