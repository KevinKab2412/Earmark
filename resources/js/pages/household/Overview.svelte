<script module lang="ts">
    import { dashboard } from '@/routes';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    };
</script>

<script lang="ts">
    import { Link, page, router } from '@inertiajs/svelte';
    import GripVertical from '@lucide/svelte/icons/grip-vertical';
    import AppHead from '@/components/AppHead.svelte';
    import FinanceSummary from '@/components/FinanceSummary.svelte';
    import FreshnessIndicator from '@/components/FreshnessIndicator.svelte';
    import { Button } from '@/components/ui/button';
    import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
    import { SidebarTrigger } from '@/components/ui/sidebar';
    import {
        Dialog,
        DialogContent,
        DialogDescription,
        DialogFooter,
        DialogTitle,
    } from '@/components/ui/dialog';
    import { Label } from '@/components/ui/label';
    import { updateCards } from '@/actions/App/Http/Controllers/Household/DashboardController';
    import { toUrl } from '@/lib/utils';
    import { index as goals } from '@/routes/household/goals';
    import { index as holdings } from '@/routes/household/holdings';
    import { index as importStatements } from '@/routes/household/import';
    import { show as netWorth } from '@/routes/household/net-worth';
    import { index as plan } from '@/routes/household/plan';
    import { index as recurring } from '@/routes/household/recurring';
    import { index as transactions } from '@/routes/household/transactions';

    type CardKey =
        | 'budget'
        | 'net_worth'
        | 'goals'
        | 'spending'
        | 'transactions'
        | 'recurring'
        | 'investments';

    type SpendingPoint = { day: number; amount: number };

    let {
        availableCards,
        enabledCards,
        cards,
    }: {
        availableCards: CardKey[];
        enabledCards: CardKey[];
        cards: {
            budget: {
                planned: string;
                available: string;
                over_budget: { id: string; name: string; available: string }[];
                groups: {
                    direction: 'income' | 'expense';
                    label: string;
                    planned: string;
                    activity: string;
                    remaining: string;
                    progress: number;
                    over_budget: boolean;
                }[];
            };
            recurring: { count: number };
            goals: {
                count: number;
                items: {
                    id: string;
                    name: string;
                    type: 'save_up' | 'pay_down';
                    linked_to: string | null;
                    current: string;
                    target: string;
                    progress: number;
                }[];
            };
            net_worth: {
                investable: string;
                total: string;
                targetGap: string | null;
                freshness: {
                    status: 'current' | 'stale' | 'unknown';
                    label: string;
                };
                hasPositions: boolean;
            };
            investments: { total: string; count: number };
            spending: {
                current_month: string;
                previous_month: string;
                current_total: string;
                previous_total: string;
                current_series: SpendingPoint[];
                previous_series: SpendingPoint[];
            };
            transactions: {
                items: {
                    id: string;
                    date: string;
                    payee: string;
                    account: string;
                    category: string;
                    category_icon: string | null;
                    amount: string;
                    is_income: boolean;
                }[];
            };
        };
    } = $props();

    const cardLabels: Record<CardKey, string> = {
        budget: 'Budget',
        net_worth: 'Net worth',
        goals: 'Goals',
        spending: 'Spending',
        transactions: 'Transactions',
        recurring: 'Recurring',
        investments: 'Investments',
    };

    const cardSlugs: Record<CardKey, string> = {
        budget: 'budget',
        net_worth: 'net-worth',
        goals: 'goals',
        spending: 'spending',
        transactions: 'transactions',
        recurring: 'recurring',
        investments: 'investments',
    };

    let customizing = $state(false);
    let selected = $state<CardKey[]>([]);
    let saving = $state(false);
    let dragKey = $state<CardKey | null>(null);

    const spendingMaximum = $derived(
        Math.max(
            1,
            ...cards.spending.current_series.map((point) => point.amount),
            ...cards.spending.previous_series.map((point) => point.amount),
        ),
    );
    const greeting = $derived.by(() => {
        const hour = new Date().getHours();
        const salutation = hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';
        const firstName = page.props.auth.user?.name?.trim().split(/\s+/)[0] ?? 'there';

        return `${salutation}, ${firstName}!`;
    });
    const budgetMonth = new Intl.DateTimeFormat('en-CA', { month: 'long', year: 'numeric' }).format(new Date());
    const spendingChartTicks = [1, 5, 9, 13, 17, 21, 25, 31];

    function spendingPath(points: SpendingPoint[]): string {
        return points
            .map((point, index) => {
                const x = 48 + ((point.day - 1) / 30) * 650;
                const y = 174 - (point.amount / spendingMaximum) * 145;

                return `${index === 0 ? 'M' : 'L'} ${x} ${y}`;
            })
            .join(' ');
    }

    function formatCents(amount: number): string {
        return new Intl.NumberFormat('en-CA', {
            style: 'currency',
            currency: 'CAD',
            maximumFractionDigits: 0,
        }).format(amount / 100);
    }

    function isCardKey(value: string): value is CardKey {
        return Object.hasOwn(cardLabels, value);
    }

    function sameCards(left: readonly CardKey[], right: readonly CardKey[]): boolean {
        return left.length === right.length && left.every((key, index) => key === right[index]);
    }

    function moveBefore(list: readonly CardKey[], source: CardKey, target: CardKey): CardKey[] {
        const next = list.filter((key) => key !== source);
        const index = next.indexOf(target);

        if (index === -1) {
            return [...list];
        }

        next.splice(index, 0, source);

        return next;
    }

    function persist(next: CardKey[], onError?: () => void): void {
        saving = true;

        router.post(
            updateCards.url(),
            { cards: next },
            {
                preserveScroll: true,
                onError: () => {
                    onError?.();
                },
                onFinish: () => {
                    saving = false;
                },
            },
        );
    }

    function openCustomize(): void {
        selected = [...enabledCards];
        customizing = true;
    }

    function toggle(key: CardKey): void {
        if (saving) {
            return;
        }

        const previous = selected;
        const next = previous.includes(key)
            ? previous.filter((item) => item !== key)
            : [...previous, key];

        selected = next;
        persist(next, () => {
            selected = previous;
        });
    }

    function cardUnderPointer(x: number, y: number, source: CardKey): CardKey | null {
        let found: CardKey | null = null;

        for (const card of document.querySelectorAll<HTMLElement>('[data-card-key]')) {
            const value = card.getAttribute('data-card-key');

            if (value === null || value === source || !isCardKey(value)) {
                continue;
            }

            const rect = card.getBoundingClientRect();

            if (x >= rect.left && x <= rect.right && y >= rect.top && y <= rect.bottom) {
                found = value;
            }
        }

        return found;
    }

    function dropCard(source: CardKey, event: { clientX: number; clientY: number }): void {
        if (dragKey !== source) {
            return;
        }

        dragKey = null;

        const target = cardUnderPointer(event.clientX, event.clientY, source);

        if (target === null) {
            return;
        }

        const next = moveBefore(enabledCards, source, target);

        if (sameCards(next, enabledCards)) {
            return;
        }

        persist(next);
    }

    function startDrag(event: PointerEvent, key: CardKey): void {
        if (event.button !== 0 || saving) {
            return;
        }

        event.preventDefault();
        dragKey = key;

        if (event.currentTarget instanceof HTMLElement) {
            event.currentTarget.setPointerCapture(event.pointerId);
        }

        const end = (upEvent: Event): void => {
            window.removeEventListener('pointerup', end, true);
            window.removeEventListener('mouseup', end, true);
            window.removeEventListener('pointercancel', cancel, true);

            if (upEvent instanceof MouseEvent) {
                dropCard(key, upEvent);
            }
        };

        const cancel = (): void => {
            window.removeEventListener('pointerup', end, true);
            window.removeEventListener('mouseup', end, true);
            window.removeEventListener('pointercancel', cancel, true);
            dragKey = null;
        };

        window.addEventListener('pointerup', end, true);
        window.addEventListener('mouseup', end, true);
        window.addEventListener('pointercancel', cancel, true);
    }
</script>

<AppHead title="Dashboard" />

<div class="flex flex-col gap-5 px-6 py-5" data-test="overview">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex min-w-0 items-center gap-2">
            <SidebarTrigger class="-ml-2" />
            <h1 class="text-xl font-semibold tracking-tight">{greeting}</h1>
        </div>
        <Button variant="outline" size="sm" onclick={openCustomize} data-test="customize-overview">
            Customize
        </Button>
    </div>

    {#if enabledCards.length === 0}
        <Card>
            <CardContent class="py-8 text-center text-sm text-muted-foreground">
                No cards selected. Choose some with the Customize button above.
            </CardContent>
        </Card>
    {/if}

    <div class="grid items-start gap-5 xl:grid-cols-2" data-test="overview-grid">
        {#each enabledCards as key (key)}
            <div
                class="group relative min-w-0 {key === 'goals' ? 'xl:col-span-2' : ''} {dragKey === key ? 'opacity-60' : ''}"
                data-test="overview-card-{cardSlugs[key]}"
                data-card-key={key}
            >
                <button
                    type="button"
                    class="absolute top-3 left-1 z-10 inline-flex size-6 cursor-grab touch-none items-center justify-center rounded-md bg-card/80 text-muted-foreground/70 opacity-0 select-none hover:bg-accent hover:text-foreground group-hover:opacity-100 focus-visible:opacity-100 active:cursor-grabbing disabled:cursor-not-allowed disabled:opacity-50"
                    data-test="overview-card-drag-{cardSlugs[key]}"
                    aria-label="Drag {cardLabels[key]}"
                    draggable="false"
                    disabled={saving}
                    onpointerdown={(event) => startDrag(event, key)}
                >
                    <GripVertical class="size-4" />
                </button>
                <div class="min-w-0">
                    {#if key === 'budget'}
                        <Card class="h-full">
                            <CardHeader class="flex flex-row items-start justify-between gap-3">
                                <div>
                                    <CardTitle>Budget</CardTitle>
                                    <p class="mt-1 text-sm text-muted-foreground">{budgetMonth}</p>
                                </div>
                                <Button variant="link" class="px-0" asChild>
                                    {#snippet children(props)}
                                        <Link href={toUrl(plan())} class={props.class}>Open Budget</Link>
                                    {/snippet}
                                </Button>
                            </CardHeader>
                            <CardContent class="space-y-5">
                                {#each cards.budget.groups as group (group.direction)}
                                    <div class="space-y-2" data-test="budget-direction-{group.direction}">
                                        <div class="flex items-center justify-between gap-3 text-sm">
                                            <span class="font-medium">{group.label}</span>
                                            <span class="text-muted-foreground tabular-nums">{group.planned} planned</span>
                                        </div>
                                        <div class="h-2.5 overflow-hidden rounded-full bg-muted" aria-hidden="true">
                                            <div
                                                class="h-full rounded-full {group.over_budget ? 'bg-destructive' : 'bg-primary'}"
                                                style="width: {group.progress}%"
                                            ></div>
                                        </div>
                                        <div class="flex items-center justify-between gap-3 text-sm">
                                            <span class="font-semibold tabular-nums">{group.activity} {group.direction === 'income' ? 'earned' : 'spent'}</span>
                                            <span class="tabular-nums {group.over_budget ? 'font-semibold text-destructive' : 'text-muted-foreground'}">
                                                {group.remaining} remaining
                                            </span>
                                        </div>
                                    </div>
                                {/each}
                            </CardContent>
                        </Card>
                    {:else if key === 'net_worth'}
                        <Card class="h-full">
                            <CardHeader class="flex flex-row items-center justify-between gap-3">
                                <CardTitle>Net worth</CardTitle>
                                <FreshnessIndicator
                                    label={cards.net_worth.freshness.label}
                                    status={cards.net_worth.freshness.status}
                                />
                            </CardHeader>
                            <CardContent>
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <p class="text-sm text-muted-foreground">Investable assets</p>
                                        <p class="mt-1 text-2xl font-semibold tabular-nums">{cards.net_worth.investable}</p>
                                        <p class="mt-2 text-xs text-muted-foreground">Long-term portfolio, excluding home</p>
                                    </div>
                                    <div>
                                        <p class="text-sm text-muted-foreground">Total net worth</p>
                                        <p class="mt-1 text-2xl font-semibold tabular-nums">{cards.net_worth.total}</p>
                                        <p class="mt-2 text-xs text-muted-foreground">Assets minus liabilities, including home</p>
                                    </div>
                                </div>
                                <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t pt-3">
                                    {#if cards.net_worth.targetGap !== null}
                                        <p class="text-sm text-muted-foreground">
                                            Target gap <span class="ml-1 font-medium text-foreground tabular-nums">{cards.net_worth.targetGap}</span>
                                        </p>
                                    {/if}
                                    <Button variant="link" class="px-0" asChild>
                                        {#snippet children(props)}
                                            <Link href={toUrl(netWorth())} class={props.class}>
                                                {cards.net_worth.hasPositions ? 'Open Net Worth' : 'Add first financial position'}
                                            </Link>
                                        {/snippet}
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    {:else if key === 'goals'}
                        <Card class="h-full">
                            <CardHeader class="flex flex-row items-center justify-between gap-3">
                                <CardTitle>Goals</CardTitle>
                                <Button variant="link" class="px-0" asChild>
                                    {#snippet children(props)}
                                        <Link href={toUrl(goals())} class={props.class}>Open Goals</Link>
                                    {/snippet}
                                </Button>
                            </CardHeader>
                            <CardContent class="pt-0">
                                {#if cards.goals.items.length === 0}
                                    <p class="text-sm text-muted-foreground">No active goals.</p>
                                {:else}
                                    <div class="divide-y">
                                        {#each cards.goals.items as goal (goal.id)}
                                            <Link
                                                href={toUrl(goals())}
                                                class="block space-y-2 py-3 first:pt-0 last:pb-0"
                                                data-test="dashboard-goal"
                                            >
                                                <div class="flex items-center justify-between gap-4">
                                                    <span class="min-w-0">
                                                        <span class="block truncate text-sm font-medium">{goal.name}</span>
                                                        <span class="mt-0.5 block truncate text-xs text-muted-foreground">
                                                            {goal.linked_to ?? (goal.type === 'save_up' ? 'Savings category' : 'Liability')}
                                                        </span>
                                                    </span>
                                                    <span class="shrink-0 text-sm font-semibold tabular-nums">
                                                        {goal.current}{goal.type === 'save_up' ? ` of ${goal.target}` : ''}
                                                    </span>
                                                </div>
                                                {#if goal.type === 'save_up'}
                                                    <div class="h-1.5 overflow-hidden rounded-full bg-muted" aria-hidden="true">
                                                        <div class="h-full rounded-full bg-primary" style="width: {goal.progress}%"></div>
                                                    </div>
                                                {/if}
                                            </Link>
                                        {/each}
                                    </div>
                                {/if}
                            </CardContent>
                        </Card>
                    {:else if key === 'spending'}
                        <div class="h-full" data-test="spending-card">
                            <Card class="h-full">
                                <CardHeader class="flex flex-row items-center justify-between gap-3">
                                    <div>
                                        <CardTitle>Spending</CardTitle>
                                        <p class="mt-1 text-sm text-muted-foreground">This month vs. last month</p>
                                    </div>
                                    <span class="text-sm font-medium tabular-nums" data-test="spending-total">{cards.spending.current_total}</span>
                                </CardHeader>
                                <CardContent class="pt-0">
                                    <div class="flex items-center justify-between text-xs text-muted-foreground">
                                        {#if spendingMaximum > 1}
                                            <span>{formatCents(spendingMaximum)}</span>
                                            <span>{formatCents(Math.round(spendingMaximum / 2))}</span>
                                        {/if}
                                        <span class="text-right">$0</span>
                                    </div>
                                    <svg
                                        viewBox="0 0 720 190"
                                        class="mt-2 h-44 w-full overflow-visible"
                                        role="img"
                                        aria-label="Spending comparison for {cards.spending.current_month} and {cards.spending.previous_month}"
                                        data-test="spending-chart"
                                    >
                                        {#each [29, 77, 125, 174] as y (y)}
                                            <line x1="48" y1={y} x2="698" y2={y} stroke="currentColor" stroke-opacity="0.12" />
                                        {/each}
                                        <path d={spendingPath(cards.spending.previous_series)} fill="none" stroke="#969696" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" />
                                        <path d={spendingPath(cards.spending.current_series)} fill="none" stroke="var(--primary)" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                    <div class="mt-1 flex justify-between pl-8 text-muted-foreground">
                                        {#each spendingChartTicks as day, index (day)}
                                            <span
                                                class="shrink-0 whitespace-nowrap text-[10px] sm:text-[11px] {index % 2 === 1 && index !== spendingChartTicks.length - 1 ? 'hidden sm:inline' : ''}"
                                            >Day {day}</span>
                                        {/each}
                                    </div>
                                    <div class="mt-4 flex flex-wrap items-center justify-center gap-x-5 gap-y-2 text-sm">
                                        <span class="inline-flex items-center gap-2 text-muted-foreground">
                                            <span class="size-2.5 rounded-full bg-neutral-400"></span>
                                            {cards.spending.previous_month} · {cards.spending.previous_total}
                                        </span>
                                        <span class="inline-flex items-center gap-2 text-primary">
                                            <span class="size-2.5 rounded-full bg-primary"></span>
                                            {cards.spending.current_month} · {cards.spending.current_total}
                                        </span>
                                    </div>
                                    {#if cards.spending.current_total === '$0.00' && cards.spending.previous_total === '$0.00'}
                                        <p class="mt-3 text-center text-xs text-muted-foreground" data-test="spending-empty">No expense activity yet.</p>
                                    {/if}
                                </CardContent>
                            </Card>
                        </div>
                    {:else if key === 'transactions'}
                        <div class="h-full" data-test="transactions-card">
                            <Card class="h-full">
                                <CardHeader class="flex flex-row items-center justify-between gap-3">
                                    <div>
                                        <CardTitle>Transactions</CardTitle>
                                        <p class="mt-1 text-sm text-muted-foreground">Most recent</p>
                                    </div>
                                    <Button variant="link" class="px-0" asChild>
                                        {#snippet children(props)}
                                            <Link href={toUrl(transactions())} class={props.class}>View all</Link>
                                        {/snippet}
                                    </Button>
                                </CardHeader>
                                <CardContent class="p-0">
                                    {#if cards.transactions.items.length === 0}
                                        <div class="px-6 pb-6 text-sm text-muted-foreground">
                                            <p>No recent transactions.</p>
                                            <div class="mt-3 flex flex-wrap gap-3">
                                                <Button variant="link" class="h-auto p-0" asChild>
                                                    {#snippet children(props)}
                                                        <Link href={toUrl(importStatements())} class={props.class}>Upload a statement</Link>
                                                    {/snippet}
                                                </Button>
                                                <Button variant="link" class="h-auto p-0" asChild>
                                                    {#snippet children(props)}
                                                        <Link href={toUrl(transactions())} class={props.class}>Add a transaction</Link>
                                                    {/snippet}
                                                </Button>
                                            </div>
                                        </div>
                                    {:else}
                                        <div class="divide-y">
                                            {#each cards.transactions.items as transaction (transaction.id)}
                                                <Link
                                                    href={toUrl(transactions())}
                                                    class="flex items-center gap-3 px-5 py-3 transition-colors hover:bg-muted/50"
                                                    data-test="dashboard-transaction"
                                                >
                                                    <span class="grid size-9 shrink-0 place-items-center rounded-full bg-muted text-xs font-semibold text-muted-foreground">
                                                        {transaction.account.slice(0, 1).toUpperCase()}
                                                    </span>
                                                    <span class="min-w-0 flex-1">
                                                        <span class="block truncate text-sm font-medium">{transaction.payee}</span>
                                                        <span class="mt-0.5 flex min-w-0 items-center gap-1 text-xs text-muted-foreground">
                                                            {#if transaction.category_icon}<span aria-hidden="true">{transaction.category_icon}</span>{/if}
                                                            <span class="truncate">{transaction.category} · {transaction.account} · {transaction.date}</span>
                                                        </span>
                                                    </span>
                                                    <span class="shrink-0 text-sm font-medium tabular-nums {transaction.is_income ? 'text-emerald-700' : ''}">
                                                        {transaction.amount}
                                                    </span>
                                                </Link>
                                            {/each}
                                        </div>
                                    {/if}
                                </CardContent>
                            </Card>
                        </div>
                    {:else if key === 'recurring'}
                        <FinanceSummary
                            title="Recurring"
                            value={String(cards.recurring.count)}
                            description="Confirmed schedules"
                        >
                            {#snippet action()}
                                <Button variant="link" class="px-0" asChild>
                                    {#snippet children(props)}
                                        <Link href={toUrl(recurring())} class={props.class}>Open Recurring</Link>
                                    {/snippet}
                                </Button>
                            {/snippet}
                        </FinanceSummary>
                    {:else if key === 'investments'}
                        <FinanceSummary
                            title="Investments"
                            value={cards.investments.total}
                            description="{cards.investments.count} holding(s) tracked"
                        >
                            {#snippet action()}
                                <Button variant="link" class="px-0" asChild>
                                    {#snippet children(props)}
                                        <Link href={toUrl(holdings())} class={props.class}>Open Holdings</Link>
                                    {/snippet}
                                </Button>
                            {/snippet}
                        </FinanceSummary>
                    {/if}
                </div>
            </div>
        {/each}
    </div>
</div>

<Dialog bind:open={customizing}>
    <DialogContent class="max-h-[85vh] overflow-y-auto">
        <div class="flex flex-col gap-4">
            <div class="flex flex-col gap-2">
                <DialogTitle>Customize cards</DialogTitle>
                <DialogDescription>
                    Show or hide cards. Drag a card on the dashboard to change the order.
                </DialogDescription>
            </div>
            <div class="flex flex-col gap-3" data-test="overview-customize">
                {#each availableCards as key (key)}
                    <div class="flex items-center justify-between gap-3">
                        <Label for="overview-switch-{key}" class="font-normal">
                            {cardLabels[key]}
                        </Label>
                        <button
                            type="button"
                            role="switch"
                            id="overview-switch-{key}"
                            aria-label={cardLabels[key]}
                            aria-checked={selected.includes(key) ? 'true' : 'false'}
                            data-state={selected.includes(key) ? 'checked' : 'unchecked'}
                            data-test="overview-card-switch-{cardSlugs[key]}"
                            class="inline-flex h-5 w-9 shrink-0 items-center rounded-full border border-transparent p-0.5 transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 data-[state=checked]:bg-primary data-[state=unchecked]:bg-input"
                            disabled={saving}
                            onclick={() => toggle(key)}
                        >
                            <span
                                class="pointer-events-none block size-4 rounded-full bg-background shadow-sm transition-transform data-[state=checked]:translate-x-4"
                                data-state={selected.includes(key) ? 'checked' : 'unchecked'}
                            ></span>
                        </button>
                    </div>
                {/each}
            </div>
            <DialogFooter>
                <Button
                    variant="ghost"
                    size="sm"
                    onclick={() => (customizing = false)}
                    disabled={saving}
                >
                    Done
                </Button>
            </DialogFooter>
        </div>
    </DialogContent>
</Dialog>
