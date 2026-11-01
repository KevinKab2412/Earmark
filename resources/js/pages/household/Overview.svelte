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
    import { Link, router } from '@inertiajs/svelte';
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

<div class="flex flex-col gap-4 px-6 py-5" data-test="overview">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-lg font-semibold">Dashboard</h1>
        <Button variant="outline" size="sm" onclick={openCustomize}>
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

    {#each enabledCards as key (key)}
        <div
            class="flex items-start gap-2 {dragKey === key ? 'opacity-60' : ''}"
            data-test="overview-card-{cardSlugs[key]}"
            data-card-key={key}
        >
            <button
                type="button"
                class="mt-0.5 inline-flex size-8 shrink-0 cursor-grab touch-none items-center justify-center rounded-md text-muted-foreground select-none hover:bg-accent active:cursor-grabbing disabled:cursor-not-allowed disabled:opacity-50"
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
                    <div class="flex flex-col gap-3">
                        <h2 class="text-sm font-medium text-muted-foreground">Budget</h2>
                        <div class="grid gap-4 md:grid-cols-3">
                            <FinanceSummary
                                title="Planned"
                                value={cards.budget.planned}
                                description="Across budget categories"
                            />
                            <FinanceSummary
                                title="Available"
                                value={cards.budget.available}
                                description="Including category rollover"
                                class={availableNegative ? 'text-destructive' : ''}
                            />

                            <div data-test="over-budget-categories">
                                <Card>
                                    <CardHeader class="flex flex-row items-center justify-between">
                                        <CardTitle class="text-sm font-medium text-muted-foreground">
                                            Over budget categories
                                        </CardTitle>
                                        <span class="text-sm tabular-nums">{cards.budget.over_budget.length}</span>
                                    </CardHeader>
                                    <CardContent>
                                        {#if cards.budget.over_budget.length === 0}
                                            <p class="text-sm text-muted-foreground">
                                                No categories are over budget this month.
                                            </p>
                                        {:else}
                                            {#each cards.budget.over_budget as category (category.id)}
                                                <MoneyRow
                                                    label={category.name}
                                                    amount={category.available}
                                                    detail="Negative available balance"
                                                />
                                            {/each}
                                            <Button variant="link" class="px-0" asChild>
                                                {#snippet children(props)}
                                                    <Link href={toUrl(plan())} class={props.class}>
                                                        Open Budget
                                                    </Link>
                                                {/snippet}
                                            </Button>
                                        {/if}
                                    </CardContent>
                                </Card>
                            </div>
                        </div>
                    </div>
                {:else if key === 'net_worth'}
                    <div class="flex flex-col gap-3">
                        <h2 class="text-sm font-medium text-muted-foreground">Net worth</h2>
                        <div class="grid gap-4 md:grid-cols-2" data-test="net-worth-cards">
                            <FinanceSummary
                                title="Investable assets"
                                value={cards.net_worth.investable}
                                description="Long-term portfolio, excluding home"
                            />
                            <FinanceSummary
                                title="Total net worth"
                                value={cards.net_worth.total}
                                description="Assets minus liabilities, including home"
                            >
                                {#snippet action()}
                                    <div class="flex flex-col items-end gap-2">
                                        <FreshnessIndicator
                                            label={cards.net_worth.freshness.label}
                                            status={cards.net_worth.freshness.status}
                                        />
                                        <Button variant="link" class="px-0" asChild>
                                            {#snippet children(props)}
                                                <Link href={toUrl(netWorth())} class={props.class}>
                                                    {cards.net_worth.hasPositions
                                                        ? 'Open Net Worth'
                                                        : 'Add first financial position'}
                                                </Link>
                                            {/snippet}
                                        </Button>
                                    </div>
                                {/snippet}
                            </FinanceSummary>
                        </div>
                    </div>
                {:else if key === 'goals'}
                    <FinanceSummary
                        title="Goals"
                        value={String(cards.goals.count)}
                        description="Active goals"
                    >
                        {#snippet action()}
                            <Button variant="link" class="px-0" asChild>
                                {#snippet children(props)}
                                    <Link href={toUrl(goals())} class={props.class}>
                                        Open Goals
                                    </Link>
                                {/snippet}
                            </Button>
                        {/snippet}
                    </FinanceSummary>
                {:else if key === 'spending'}
                    <Card>
                        <CardHeader class="flex flex-row items-center justify-between">
                            <CardTitle class="text-sm font-medium text-muted-foreground">
                                Spending
                            </CardTitle>
                            {#if cards.spending.series.length > 0}
                                <span class="text-sm font-medium tabular-nums" data-test="spending-total">
                                    {cards.spending.total}
                                </span>
                            {/if}
                        </CardHeader>
                        <CardContent>
                            {#if cards.spending.series.length === 0}
                                <p class="text-sm text-muted-foreground" data-test="spending-empty">
                                    No expenses this month.
                                </p>
                            {:else}
                                <div
                                    class="flex flex-col gap-3"
                                    data-test="spending-series"
                                    aria-label="Spending by category this month"
                                >
                                    {#each cards.spending.series as category, index (`${category.name}-${index}`)}
                                        <div class="flex flex-col gap-1" data-test="spending-category">
                                            <div class="flex items-baseline justify-between gap-3 text-sm">
                                                <span class="min-w-0 truncate">{category.name}</span>
                                                <span class="shrink-0 tabular-nums">{category.spent}</span>
                                            </div>
                                            <div class="h-2 overflow-hidden rounded-full bg-muted" aria-hidden="true">
                                                <div
                                                    class="h-full rounded-full bg-primary"
                                                    style="width: {category.width}%"
                                                ></div>
                                            </div>
                                        </div>
                                    {/each}
                                </div>
                            {/if}
                        </CardContent>
                    </Card>
                {:else if key === 'transactions'}
                    <Card>
                        <CardHeader class="flex flex-row items-center justify-between">
                            <CardTitle class="text-sm font-medium text-muted-foreground">
                                Transactions
                            </CardTitle>
                            <Button variant="link" class="px-0" asChild>
                                {#snippet children(props)}
                                    <Link href={toUrl(transactions())} class={props.class}>
                                        Open Transactions
                                    </Link>
                                {/snippet}
                            </Button>
                        </CardHeader>
                    </Card>
                {:else if key === 'recurring'}
                    <FinanceSummary
                        title="Recurring"
                        value={String(cards.recurring.count)}
                        description="Confirmed schedules"
                    >
                        {#snippet action()}
                            <Button variant="link" class="px-0" asChild>
                                {#snippet children(props)}
                                    <Link href={toUrl(recurring())} class={props.class}>
                                        Open Recurring
                                    </Link>
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
                                    <Link href={toUrl(holdings())} class={props.class}>
                                        Open Holdings
                                    </Link>
                                {/snippet}
                            </Button>
                        {/snippet}
                    </FinanceSummary>
                {/if}
            </div>
        </div>
    {/each}
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
