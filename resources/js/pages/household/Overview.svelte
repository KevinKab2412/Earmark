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
    import MoneyRow from '@/components/MoneyRow.svelte';
    import { Button } from '@/components/ui/button';
    import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
            };
            recurring: { count: number };
            goals: { count: number };
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
                series: { name: string; spent: string; width: number }[];
                total: string;
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

    const availableNegative = $derived(cards.budget.available.startsWith('-'));
    const greeting = $derived.by(() => {
        const hour = new Date().getHours();
        const salutation = hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';
        const firstName = page.props.auth.user?.name?.trim().split(/\s+/)[0] ?? 'there';

        return `${salutation}, ${firstName}!`;
    });
    const budgetMonth = new Intl.DateTimeFormat('en-CA', { month: 'long', year: 'numeric' }).format(new Date());

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
        <div>
            <h1 class="text-xl font-semibold tracking-tight">{greeting}</h1>
            <p class="mt-1 text-sm text-muted-foreground">Your money at a glance.</p>
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

    <div class="grid items-start gap-4 lg:grid-cols-2" data-test="overview-grid">
        {#each enabledCards as key (key)}
            <div
                class="flex min-w-0 items-start gap-2 {dragKey === key ? 'opacity-60' : ''}"
                data-test="overview-card-{cardSlugs[key]}"
                data-card-key={key}
            >
                <button
                    type="button"
                    class="mt-2 inline-flex size-6 shrink-0 cursor-grab touch-none items-center justify-center rounded-md text-muted-foreground/70 select-none hover:bg-accent hover:text-foreground active:cursor-grabbing disabled:cursor-not-allowed disabled:opacity-50"
                    data-test="overview-card-drag-{cardSlugs[key]}"
                    aria-label="Drag {cardLabels[key]}"
                    draggable="false"
                    disabled={saving}
                    onpointerdown={(event) => startDrag(event, key)}
                >
                    <GripVertical class="size-4" />
                </button>
                <div class="min-w-0 flex-1">
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
                            <CardContent class="space-y-4">
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <p class="text-sm text-muted-foreground">Planned</p>
                                        <p class="mt-1 text-2xl font-semibold tabular-nums">{cards.budget.planned}</p>
                                        <p class="mt-1 text-xs text-muted-foreground">Across budget categories</p>
                                    </div>
                                    <div>
                                        <p class="text-sm text-muted-foreground">Available</p>
                                        <p class="mt-1 text-2xl font-semibold tabular-nums {availableNegative ? 'text-destructive' : 'text-emerald-700'}">{cards.budget.available}</p>
                                        <p class="mt-1 text-xs text-muted-foreground">Including category rollover</p>
                                    </div>
                                </div>
                                <div class="border-t pt-3" data-test="over-budget-categories">
                                    <div class="flex items-center justify-between gap-3 text-sm">
                                        <span class="font-medium">Over budget categories</span>
                                        <span class="tabular-nums">{cards.budget.over_budget.length}</span>
                                    </div>
                                    {#if cards.budget.over_budget.length === 0}
                                        <p class="mt-2 text-sm text-muted-foreground">No categories are over budget this month.</p>
                                    {:else}
                                        <div class="mt-1 divide-y">
                                            {#each cards.budget.over_budget as category (category.id)}
                                                <MoneyRow label={category.name} amount={category.available} detail="Available" />
                                            {/each}
                                        </div>
                                    {/if}
                                </div>
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
                            <CardHeader><CardTitle>Goals</CardTitle></CardHeader>
                            <CardContent class="flex items-end justify-between gap-3">
                                <div>
                                    <p class="text-3xl font-semibold tabular-nums">{cards.goals.count}</p>
                                    <p class="mt-1 text-sm text-muted-foreground">Active goals</p>
                                </div>
                                <Button variant="link" class="px-0" asChild>
                                    {#snippet children(props)}
                                        <Link href={toUrl(goals())} class={props.class}>Open Goals</Link>
                                    {/snippet}
                                </Button>
                            </CardContent>
                        </Card>
                    {:else if key === 'spending'}
                        <div class="h-full" data-test="spending-card">
                            <Card class="h-full">
                                <CardHeader class="flex flex-row items-center justify-between gap-3">
                                    <div>
                                        <CardTitle>Spending</CardTitle>
                                        <p class="mt-1 text-sm text-muted-foreground">This month</p>
                                    </div>
                                    <span class="text-sm font-medium tabular-nums" data-test="spending-total">{cards.spending.total}</span>
                                </CardHeader>
                                <CardContent>
                                    {#if cards.spending.series.length === 0}
                                        <p class="text-sm text-muted-foreground" data-test="spending-empty">No expenses this month.</p>
                                    {:else}
                                        <div class="flex flex-col gap-3" data-test="spending-series" aria-label="Spending by category this month">
                                            {#each cards.spending.series as category, index (`${category.name}-${index}`)}
                                                <div class="flex flex-col gap-1" data-test="spending-category">
                                                    <div class="flex items-baseline justify-between gap-3 text-sm">
                                                        <span class="min-w-0 truncate">{category.name}</span>
                                                        <span class="shrink-0 tabular-nums">{category.spent}</span>
                                                    </div>
                                                    <div class="h-2 overflow-hidden rounded-full bg-muted" aria-hidden="true">
                                                        <div class="h-full rounded-full bg-primary" style="width: {category.width}%"></div>
                                                    </div>
                                                </div>
                                            {/each}
                                        </div>
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
