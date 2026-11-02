<script module lang="ts">
    import { index } from '@/routes/household/plan';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Budget',
                href: index(),
            },
        ],
    };
</script>

<script lang="ts">
    import { Link, router, setLayoutProps } from '@inertiajs/svelte';
    import ChevronDown from '@lucide/svelte/icons/chevron-down';
    import PlanController from '@/actions/App/Http/Controllers/Household/PlanController';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogTitle } from '@/components/ui/dialog';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { toUrl } from '@/lib/utils';
    import { index as planIndex } from '@/routes/household/plan';
    import { exportMethod as planExport } from '@/routes/household/plan';
    import type { PlanToolbar } from '@/types';

    type Category = {
        id: string;
        name: string;
        direction: 'income' | 'expense' | 'transfer';
        budgetable: boolean;
        icon: string | null;
        planned: string;
        planned_cents: number;
        activity: string;
        activity_cents: number;
        carryover: string;
        carryover_cents: number;
        opening_balance: string;
        opening_balance_cents: number;
        available: string;
        available_cents: number;
        rollover_enabled: boolean;
    };

    type Group = {
        id: string;
        name: string;
        direction: 'income' | 'expense' | 'transfer';
        categories: Category[];
        planned_cents: number;
        activity_cents: number;
        available_cents: number;
    };

    let {
        year,
        month,
        monthLabel,
        groups,
        categories,
        budgetCategories,
        budgetSummary,
        previous,
        next,
        groupsForSelect,
        defaults,
    }: {
        year: number;
        month: number;
        monthLabel: string;
        groups: Group[];
        categories: Category[];
        budgetCategories: Category[];
        budgetSummary: {
            left_to_budget: string;
            left_to_budget_cents: number;
            income: { planned: string; planned_cents: number; actual: string; actual_cents: number };
            expenses: { planned: string; planned_cents: number; actual: string; actual_cents: number };
        };
        previous: { year: number; month: number };
        next: { year: number; month: number };
        groupsForSelect: { id: string; name: string; direction: 'income' | 'expense' | 'transfer' }[];
        defaults: { apply_to_future_months: boolean };
    } = $props();

    const monthQuery = (cursor: { year: number; month: number }) =>
        planIndex({ query: { year: cursor.year, month: cursor.month } });

    const selectClass = 'flex h-10 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm shadow-sm';
    const planRow = 'grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 px-4 sm:grid-cols-[minmax(11rem,1.35fr)_minmax(5rem,1fr)_5.25rem_5.25rem_6.5rem] sm:px-5';

    let editingCategoryId = $state<string | null>(null);
    let budgetDraft = $state('');
    let applyToFutureMonths = $state(true);
    let budgetErrors = $state<Record<string, string>>({});
    let budgetEditorPosition = $state({ top: 96, left: 96 });
    let collapsedGroups = $state<string[]>([]);
    let collapsedSections = $state<string[]>([]);
    let shownUnbudgeted = $state<string[]>([]);
    let summaryTab = $state<'summary' | 'income' | 'expenses'>('summary');

    const editingCategory = $derived(
        groups.flatMap((group) => group.categories).find((category) => category.id === editingCategoryId),
    );

    type BudgetSection = {
        key: 'income' | 'expense' | 'transfer';
        label: string;
        groups: Group[];
    };

    const budgetSections = $derived.by((): BudgetSection[] => {
        const buckets: Record<BudgetSection['key'], Group[]> = {
            income: [],
            expense: [],
            transfer: [],
        };

        for (const group of groups) {
            buckets[sectionKey(group)].push(group);
        }

        return ([
            { key: 'income', label: 'Income', groups: buckets.income },
            { key: 'expense', label: 'Expenses', groups: buckets.expense },
            { key: 'transfer', label: 'Transfers', groups: buckets.transfer },
        ] satisfies BudgetSection[]).filter((section) => section.groups.length > 0);
    });

    type MoveCategoryField = 'from_category_id' | 'to_category_id';

    let moveOpen = $state(false);
    let moveForm = $state({ from_category_id: '', to_category_id: '', amount: '0', memo: '' });
    let moveErrors = $state<Record<string, string>>({});
    let movePicker = $state<MoveCategoryField | null>(null);
    let moveMemoOpen = $state(false);

    const pace = $derived(paceMarkerPercent());
    const moveReady = $derived(
        moveForm.from_category_id !== '' &&
            moveForm.to_category_id !== '' &&
            moveForm.from_category_id !== moveForm.to_category_id &&
            Number(moveForm.amount) > 0,
    );

    let categoryOpen = $state(false);
    let categoryForm = $state({ name: '', category_group_id: '', icon: '', starting_month: '', starting_balance: '0.00' });
    let categoryErrors = $state<Record<string, string>>({});

    let groupOpen = $state(false);
    let groupName = $state('');
    let groupDirection = $state<'income' | 'expense' | 'transfer'>('expense');
    let groupErrors = $state<Record<string, string>>({});

    function openBudgetEditor(category: Category, event: MouseEvent): void {
        if (editingCategoryId === category.id) {
            editingCategoryId = null;
            return;
        }

        const trigger = event.currentTarget as HTMLElement;
        const rect = trigger.getBoundingClientRect();
        const panelWidth = Math.max(1, Math.min(336, window.innerWidth - 32));
        const panelHeight = category.direction === 'expense' ? 300 : 260;
        const table = document.querySelector('[data-test="plan-table"]');
        const tableRect = table?.getBoundingClientRect();
        const viewportMinLeft = 8;
        const viewportMaxLeft = Math.max(viewportMinLeft, window.innerWidth - panelWidth - 8);
        const tableMinLeft = Math.max(viewportMinLeft, (tableRect?.left ?? viewportMinLeft) + 8);
        const tableMaxLeft = Math.min(viewportMaxLeft, (tableRect?.right ?? window.innerWidth) - panelWidth - 8);
        const minLeft = tableMaxLeft >= tableMinLeft ? tableMinLeft : viewportMinLeft;
        const maxLeft = tableMaxLeft >= tableMinLeft ? tableMaxLeft : viewportMaxLeft;
        const left = Math.min(Math.max(rect.left, minLeft), maxLeft);
        const below = rect.bottom + 8;

        budgetEditorPosition = {
            top: below + panelHeight > window.innerHeight ? Math.max(8, rect.top - panelHeight - 8) : below,
            left,
        };
        moveOpen = false;
        editingCategoryId = category.id;
        budgetDraft = (category.planned_cents / 100).toFixed(2);
        applyToFutureMonths = defaults.apply_to_future_months;
        budgetErrors = {};
    }

    function saveBudget(category: Category): void {
        budgetErrors = {};
        router.post(
            PlanController.setCategoryBudget(category.id).url,
            {
                amount: budgetDraft,
                year,
                month,
                apply_to_future_months: applyToFutureMonths,
            },
            {
                preserveScroll: true,
                onSuccess: () => (editingCategoryId = null),
                onError: (errors) => (budgetErrors = errors as Record<string, string>),
            },
        );
    }

    function setRollover(category: Category, enabled: boolean): void {
        router.post(
            PlanController.setCategoryRollover(category.id).url,
            { enabled, year, month },
            { preserveScroll: true },
        );
    }

    function moveFunds(): void {
        moveErrors = {};
        router.post(
            PlanController.move.url(),
            { ...moveForm, amount: String(moveForm.amount), year, month },
            {
                preserveScroll: true,
                onSuccess: () => {
                    moveOpen = false;
                    moveForm = { from_category_id: '', to_category_id: '', amount: '0', memo: '' };
                    movePicker = null;
                    moveMemoOpen = false;
                },
                onError: (errors) => (moveErrors = errors as Record<string, string>),
            },
        );
    }

    function createCategory(): void {
        categoryErrors = {};
        router.post(
            PlanController.storeCategory.url(),
            { ...categoryForm, year, month },
            {
                preserveScroll: true,
                onSuccess: () => {
                    categoryOpen = false;
                    categoryForm = { name: '', category_group_id: '', icon: '', starting_month: '', starting_balance: '0.00' };
                },
                onError: (errors) => (categoryErrors = errors as Record<string, string>),
            },
        );
    }

    function openCategoryDialog(): void {
        categoryErrors = {};
        categoryForm = {
            name: '',
            category_group_id: groupsForSelect.find((group) => group.direction === 'expense')?.id ?? groupsForSelect[0]?.id ?? '',
            icon: '',
            starting_month: `${year}-${String(month).padStart(2, '0')}`,
            starting_balance: '0.00',
        };
        categoryOpen = true;
    }

    function openGroupDialog(): void {
        groupOpen = true;
    }

    function createGroup(): void {
        groupErrors = {};
        router.post(
            PlanController.storeGroup.url(),
            { name: groupName, direction: groupDirection, year, month },
            {
                preserveScroll: true,
                onSuccess: () => {
                    groupOpen = false;
                    groupName = '';
                    groupDirection = 'expense';
                },
                onError: (errors) => (groupErrors = errors as Record<string, string>),
            },
        );
    }

    function toggleGroup(groupId: string): void {
        collapsedGroups = collapsedGroups.includes(groupId)
            ? collapsedGroups.filter((id) => id !== groupId)
            : [...collapsedGroups, groupId];
    }

    function toggleSection(key: string): void {
        collapsedSections = collapsedSections.includes(key)
            ? collapsedSections.filter((id) => id !== key)
            : [...collapsedSections, key];
    }

    function isUnbudgeted(category: Category): boolean {
        return (
            category.budgetable &&
            category.planned_cents === 0 &&
            category.activity_cents === 0 &&
            category.carryover_cents === 0 &&
            category.opening_balance_cents === 0 &&
            category.available_cents === 0
        );
    }

    function toggleUnbudgeted(groupId: string): void {
        shownUnbudgeted = shownUnbudgeted.includes(groupId)
            ? shownUnbudgeted.filter((id) => id !== groupId)
            : [...shownUnbudgeted, groupId];
    }

    function sectionKey(group: Group): BudgetSection['key'] {
        return group.direction;
    }

    function paceMarkerPercent(): number {
        const today = new Date();
        const viewing = new Date(year, month - 1, 1);
        const current = new Date(today.getFullYear(), today.getMonth(), 1);

        if (viewing < current) {
            return 100;
        }

        if (viewing > current) {
            return 0;
        }

        const daysInMonth = new Date(year, month, 0).getDate();

        return (today.getDate() / daysInMonth) * 100;
    }

    function spentCents(category: Category): number {
        if (category.direction === 'income') {
            return Math.max(0, category.activity_cents);
        }

        return Math.max(0, -category.activity_cents);
    }

    function progressFill(category: Category): number {
        const spent = spentCents(category);

        if (category.planned_cents <= 0) {
            return spent > 0 ? 100 : 0;
        }

        return Math.min(100, (spent / category.planned_cents) * 100);
    }

    function progressTone(category: Category): 'empty' | 'ok' | 'over' {
        const spent = spentCents(category);

        if (spent === 0) {
            return 'empty';
        }

        if (category.direction === 'expense' && spent > category.planned_cents) {
            return 'over';
        }

        return 'ok';
    }

    function balanceClass(categoryId: string): string {
        const selected = budgetCategories.find((category) => category.id === categoryId);

        if (selected === undefined || selected.available_cents === 0) {
            return '';
        }

        return selected.available_cents < 0 ? 'text-red-600' : 'text-emerald-700';
    }

    function selectedMoveCategory(field: MoveCategoryField): Category | undefined {
        return budgetCategories.find((category) => category.id === moveForm[field]);
    }

    function toggleMovePicker(field: MoveCategoryField): void {
        movePicker = movePicker === field ? null : field;
    }

    function chooseMoveCategory(field: MoveCategoryField, categoryId: string): void {
        moveForm = { ...moveForm, [field]: categoryId };
        movePicker = null;
    }

    function openMove(event: MouseEvent): void {
        editingCategoryId = null;

        const trigger = event.currentTarget as HTMLElement;
        const clickedCategory = budgetCategories.find((category) => category.id === trigger.dataset.categoryId);
        const destinationCategory = clickedCategory !== undefined && clickedCategory.available_cents < 0 ? clickedCategory : undefined;
        const sourceCategory = clickedCategory !== undefined && clickedCategory.available_cents > 0
            ? clickedCategory
            : budgetCategories.find((category) => category.id !== clickedCategory?.id && category.available_cents > 0);
        const amount = sourceCategory?.available_cents ?? 0;
        moveForm = {
            from_category_id: sourceCategory?.id ?? '',
            to_category_id: destinationCategory?.id ?? '',
            amount: amount > 0 ? String(amount / 100) : '0',
            memo: '',
        };
        moveErrors = {};
        movePicker = null;
        moveMemoOpen = false;
        moveOpen = true;
    }

    function meterPercent(actualCents: number, plannedCents: number): number {
        const actual = Math.abs(actualCents);

        if (plannedCents <= 0) {
            return actual > 0 ? 100 : 0;
        }

        return Math.min(100, (actual / plannedCents) * 100);
    }

    function meterIsOver(actualCents: number, plannedCents: number): boolean {
        return plannedCents > 0 && Math.abs(actualCents) > plannedCents;
    }

    function formatCents(cents: number): string {
        const value = Math.abs(cents) / 100;
        const formatted = new Intl.NumberFormat('en-CA', { style: 'currency', currency: 'CAD' }).format(value);
        return cents < 0 ? `-${formatted}` : formatted;
    }

    function directionLabel(direction: Category['direction']): string {
        return {
            income: 'Income',
            expense: 'Expense',
            transfer: 'Transfer',
        }[direction];
    }

    $effect(() => {
        setLayoutProps<{
            planToolbar: PlanToolbar | null;
        }>({
            planToolbar: {
                monthLabel,
                previousUrl: toUrl(monthQuery(previous)),
                nextUrl: toUrl(monthQuery(next)),
                todayUrl: toUrl(planIndex()),
                onAddGroup: openGroupDialog,
                onAddCategory: openCategoryDialog,
            },
        });
    });
</script>

<AppHead title="Budget" />

<svelte:window
    onkeydown={(event) => {
        if (event.key === 'Escape') {
            moveOpen = false;
            editingCategoryId = null;
        }
    }}
/>

<div class="flex w-full flex-col gap-4 bg-neutral-50 p-4" data-test="plan-page">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between lg:hidden">
        <div class="flex flex-wrap items-center gap-2">
            <Button variant="outline" size="sm" asChild>
                {#snippet children(props)}
                    <Link href={toUrl(monthQuery(previous))} class={props.class} data-test="plan-prev-month">←</Link>
                {/snippet}
            </Button>
            <h1 class="min-w-36 text-center text-lg font-semibold">{monthLabel}</h1>
            <Button variant="outline" size="sm" asChild>
                {#snippet children(props)}
                    <Link href={toUrl(monthQuery(next))} class={props.class} data-test="plan-next-month">→</Link>
                {/snippet}
            </Button>
            <Button variant="outline" size="sm" asChild>
                {#snippet children(props)}
                    <Link href={toUrl(planIndex())} class={props.class} data-test="plan-today">Today</Link>
                {/snippet}
            </Button>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <Button type="button" variant="outline" size="sm" onclick={() => (groupOpen = true)}>Add group</Button>
            <Button type="button" size="sm" class="bg-emerald-600 text-white hover:bg-emerald-700" onclick={openCategoryDialog}>Add category</Button>
        </div>
    </div>

    <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] 2xl:grid-cols-[minmax(0,1fr)_29rem]" data-test="plan-columns">
    <div class="overflow-hidden rounded-lg border border-neutral-200 bg-white" data-test="plan-table">
        {#if groups.length === 0}
            <div class="p-10 text-center">
                <h2 class="text-lg font-semibold">Start with a category group</h2>
                <p class="mt-1 text-sm text-muted-foreground">Groups keep related categories together. Budgets belong to each category.</p>
                <Button class="mt-4" onclick={() => (groupOpen = true)}>Create a group</Button>
            </div>
        {:else}
            {#each budgetSections as section (section.key)}
                <section class="border-b last:border-b-0">
                    <div class="{planRow} border-b border-neutral-200 bg-white py-2 text-sm font-semibold">
                        <button type="button" class="flex min-w-0 items-center gap-2 text-left" onclick={() => toggleSection(section.key)} aria-expanded={!collapsedSections.includes(section.key)}>
                            <span class="text-muted-foreground">{collapsedSections.includes(section.key) ? '▸' : '▾'}</span>
                            <span class="truncate">{section.label}</span>
                        </button>
                        {#if section.key !== 'transfer'}
                            <span class="hidden text-center text-xs font-normal text-neutral-500 sm:block">
                                Progress
                                <span class="sr-only">. The line marks today's pace through the month.</span>
                            </span>
                            <span class="hidden text-right text-xs font-normal text-neutral-500 sm:block">Planned</span>
                            <span class="hidden text-right text-xs font-normal text-neutral-500 sm:block">Actual</span>
                            <span class="hidden text-right text-xs font-normal text-neutral-500 sm:block">Remaining</span>
                        {/if}
                    </div>

                    {#if !collapsedSections.includes(section.key)}
                        {#each section.groups as group (group.id)}
                <section class="border-b last:border-b-0" data-test="category-group">
                    <div class="{planRow} bg-neutral-50 py-2">
                        <button type="button" class="flex min-w-0 items-center gap-2 text-left" onclick={() => toggleGroup(group.id)} aria-expanded={!collapsedGroups.includes(group.id)}>
                            <span class="text-neutral-400">{collapsedGroups.includes(group.id) ? '▸' : '▾'}</span>
                            <span class="truncate text-sm font-semibold">{group.name}</span>
                            <span class="text-xs text-neutral-500">{group.categories.length}</span>
                        </button>
                        {#if section.key !== 'transfer'}
                            <span class="hidden sm:block"></span>
                            <span class="hidden text-right text-sm font-medium tabular-nums sm:block">{formatCents(group.planned_cents)}</span>
                            <span class="hidden text-right text-sm tabular-nums text-neutral-700 sm:block">{formatCents(group.activity_cents)}</span>
                            <span class="hidden text-right text-sm font-medium tabular-nums sm:block">{formatCents(group.available_cents)}</span>
                        {/if}
                    </div>

                    {#if !collapsedGroups.includes(group.id)}
                        {#if group.categories.length === 0}
                            <p class="px-5 py-4 text-sm text-muted-foreground">No categories in this group yet.</p>
                        {:else}
                            {@const hiddenCount = group.categories.filter((category) => isUnbudgeted(category)).length}
                            {@const visibleCategories = group.categories.filter((category) => shownUnbudgeted.includes(group.id) || !isUnbudgeted(category))}
                            <div>
                                {#each visibleCategories as category (category.id)}
                    <div class="{planRow} border-b border-neutral-100 bg-white py-1.5 last:border-b-0" data-test="category-budget-row">
                                        <div class="col-start-1 row-start-1 min-w-0">
                                            <div class="flex items-center gap-2">
                                                {#if category.icon}
                                                    <span aria-hidden="true" class="shrink-0">{category.icon}</span>
                                                {/if}
                                                <p class="truncate font-medium">{category.name}</p>
                                            </div>
                                            {#if category.carryover_cents !== 0}
                                                <p class="text-xs text-muted-foreground">Carryover {category.carryover}</p>
                                            {/if}
                                            {#if category.opening_balance_cents !== 0}
                                                <p class="text-xs text-muted-foreground">Opening balance {category.opening_balance}</p>
                                            {/if}
                                        </div>
                                        <div class="col-span-2 col-start-1 row-start-2 sm:col-span-1 sm:col-start-2 sm:row-start-1">
                                            {#if category.budgetable}
                                                {@const tone = progressTone(category)}
                                                <div class="relative h-1.5 rounded-full bg-neutral-200" role="img" aria-label={`${category.name} progress`}>
                                                    <div
                                                        class="h-full rounded-full {tone === 'over' ? 'bg-red-500' : tone === 'ok' ? 'bg-emerald-600' : 'bg-transparent'}"
                                                        style="width: {progressFill(category)}%"
                                                    ></div>
                                                    <span class="absolute inset-y-0 w-px bg-neutral-900" style="left: {pace}%"></span>
                                                </div>
                                            {:else}
                                                <p class="text-xs text-muted-foreground">{directionLabel(category.direction)}</p>
                                            {/if}
                                        </div>
                                        <div class="relative col-start-1 row-start-3 sm:col-start-3 sm:row-start-1">
                                            <p class="text-xs text-muted-foreground sm:hidden">Planned</p>
                                            {#if category.budgetable}
                                                <button type="button" class="h-8 w-full rounded-md border border-neutral-200 bg-white px-2 text-right text-sm tabular-nums hover:border-emerald-600 focus-visible:outline-2 focus-visible:outline-emerald-600" onclick={(event) => openBudgetEditor(category, event)} aria-expanded={editingCategoryId === category.id} data-test="edit-category-budget">
                                                    {category.planned}
                                                </button>
                                            {:else}
                                                <span class="block text-right text-sm text-muted-foreground">—</span>
                                            {/if}
                                        </div>
                                        <div class="col-start-2 row-start-3 text-right sm:col-start-4 sm:row-start-1">
                                            <p class="text-xs text-muted-foreground sm:hidden">Actual</p>
                                            <p class="text-sm tabular-nums">{category.activity}</p>
                                        </div>
                                        <div class="col-start-2 row-start-1 justify-self-end sm:col-start-5 sm:row-start-1">
                                            {#if category.budgetable}
                                                <button
                                                    type="button"
                                                    class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-sm font-medium tabular-nums {category.available_cents < 0 ? 'bg-red-50 text-red-600' : category.available_cents > 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-neutral-100 text-neutral-700'}"
                                                    data-test="open-move-money"
                                                    data-category-id={category.id}
                                                    onclick={(event) => openMove(event)}
                                                >
                                                    {#if category.rollover_enabled}
                                                        <svg viewBox="0 0 24 24" class="size-3.5" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                            <path d="M21 12a9 9 0 1 1-2.64-6.36" stroke-linecap="round" />
                                                            <path d="M21 3v6h-6" stroke-linecap="round" stroke-linejoin="round" />
                                                        </svg>
                                                    {/if}
                                                    {formatCents(category.available_cents)}
                                                </button>
                                            {/if}
                                        </div>
                                    </div>
                                {/each}
                                {#if hiddenCount > 0}
                                    <button type="button" class="px-5 py-3 text-sm font-medium text-emerald-700" data-test={`show-unbudgeted-${group.id}`} onclick={() => toggleUnbudgeted(group.id)}>
                                        {shownUnbudgeted.includes(group.id) ? 'Hide unbudgeted' : `Show ${hiddenCount} unbudgeted`}
                                    </button>
                                {/if}
                            </div>
                        {/if}
                    {/if}
                </section>
                        {/each}
                        {#if section.key === 'income'}
                            {@const incomePlanned = section.groups.reduce((sum, group) => sum + group.planned_cents, 0)}
                            {@const incomeActual = section.groups.reduce((sum, group) => sum + group.activity_cents, 0)}
                            {@const incomeRemaining = section.groups.reduce((sum, group) => sum + group.available_cents, 0)}
                            <div class="{planRow} border-t border-neutral-200 bg-white py-2.5 text-sm font-semibold" data-test="total-income">
                                <span>Total Income</span>
                                <span class="hidden sm:block"></span>
                                <span class="text-right tabular-nums">{formatCents(incomePlanned)}</span>
                                <span class="text-right tabular-nums">{formatCents(incomeActual)}</span>
                                <span class="text-right tabular-nums">{formatCents(incomeRemaining)}</span>
                            </div>
                        {/if}
                    {/if}
                </section>
            {/each}
        {/if}
    </div>
    <aside class="rounded-lg border border-neutral-200 bg-white p-4 lg:sticky lg:top-4" data-test="left-to-budget">
        <h2 class="text-sm font-medium text-neutral-500">Left to budget</h2>
        <p class="mt-1 text-3xl font-semibold tabular-nums text-emerald-700">{budgetSummary.left_to_budget}</p>
        <div class="mt-4 space-y-3">
            <div data-test="plan-income-bar">
                <div class="mb-1 flex justify-between text-xs text-neutral-500">
                    <span>Earned</span>
                    <span class="tabular-nums">{budgetSummary.income.actual}</span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-neutral-200" role="img" aria-label="Income earned versus remaining">
                    <div class="h-full rounded-full {meterIsOver(budgetSummary.income.actual_cents, budgetSummary.income.planned_cents) ? 'bg-red-500' : 'bg-emerald-600'}" style="width: {meterPercent(budgetSummary.income.actual_cents, budgetSummary.income.planned_cents)}%"></div>
                </div>
            </div>
            <div data-test="plan-expense-bar">
                <div class="mb-1 flex justify-between text-xs text-neutral-500">
                    <span>Spent</span>
                    <span class="tabular-nums">{budgetSummary.expenses.actual}</span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-neutral-200" role="img" aria-label="Expenses spent versus remaining">
                    <div class="h-full rounded-full {meterIsOver(budgetSummary.expenses.actual_cents, budgetSummary.expenses.planned_cents) ? 'bg-red-500' : 'bg-emerald-600'}" style="width: {meterPercent(budgetSummary.expenses.actual_cents, budgetSummary.expenses.planned_cents)}%"></div>
                </div>
            </div>
        </div>
        <div class="mt-4 flex gap-3 border-b border-neutral-200 text-sm">
            <button type="button" class="-mb-px border-b-2 px-0.5 pb-2 {summaryTab === 'summary' ? 'border-emerald-600 font-semibold text-emerald-700' : 'border-transparent text-neutral-500'}" onclick={() => (summaryTab = 'summary')}>Summary</button>
            <button type="button" class="-mb-px border-b-2 px-0.5 pb-2 {summaryTab === 'income' ? 'border-emerald-600 font-semibold text-emerald-700' : 'border-transparent text-neutral-500'}" onclick={() => (summaryTab = 'income')}>Income</button>
            <button type="button" class="-mb-px border-b-2 px-0.5 pb-2 {summaryTab === 'expenses' ? 'border-emerald-600 font-semibold text-emerald-700' : 'border-transparent text-neutral-500'}" onclick={() => (summaryTab = 'expenses')}>Expenses</button>
        </div>
        <dl class="mt-3 space-y-2 text-sm">
            {#if summaryTab === 'income'}
                <div class="flex justify-between"><dt>Planned</dt><dd class="tabular-nums">{budgetSummary.income.planned}</dd></div>
                <div class="flex justify-between"><dt>Actual</dt><dd class="tabular-nums">{budgetSummary.income.actual}</dd></div>
            {:else if summaryTab === 'expenses'}
                <div class="flex justify-between"><dt>Planned</dt><dd class="tabular-nums">{budgetSummary.expenses.planned}</dd></div>
                <div class="flex justify-between"><dt>Actual</dt><dd class="tabular-nums">{budgetSummary.expenses.actual}</dd></div>
            {:else}
                <div class="flex justify-between"><dt>Income</dt><dd class="tabular-nums">{budgetSummary.income.planned}</dd></div>
                <div class="flex justify-between"><dt>Expenses</dt><dd class="tabular-nums">{budgetSummary.expenses.planned}</dd></div>
            {/if}
        </dl>
        <a class="mt-4 inline-flex text-sm font-medium text-emerald-700" href={toUrl(planExport({ query: { year, month } }))} data-test="download-plan-csv">Download CSV</a>
    </aside>
    </div>
</div>

{#if editingCategory}
    <div
        class="fixed z-50 max-h-[calc(100vh-1rem)] w-[min(21rem,calc(100vw-2rem))] overflow-y-auto rounded-xl border bg-background p-4 shadow-xl"
        style={`top:${budgetEditorPosition.top}px;left:${budgetEditorPosition.left}px`}
        role="dialog"
        aria-label={`Plan ${editingCategory.name}`}
        data-test="budget-editor"
    >
        <div class="flex items-start justify-between gap-3">
            <div>
                <h3 class="font-semibold">Plan {editingCategory.name}</h3>
                <p class="text-xs text-muted-foreground">{monthLabel}</p>
            </div>
            <button type="button" class="text-muted-foreground" aria-label="Close budget editor" onclick={() => (editingCategoryId = null)}>×</button>
        </div>
        <div class="mt-3 grid gap-2">
            <Label for={`budget-${editingCategory.id}`}>Planned amount</Label>
            <Input id={`budget-${editingCategory.id}`} type="number" min="0" step="0.01" bind:value={budgetDraft} />
            <InputError message={budgetErrors.amount} />
        </div>
        <label class="mt-3 flex cursor-pointer items-start gap-2 text-sm">
            <input type="checkbox" class="mt-1 accent-emerald-600" bind:checked={applyToFutureMonths} />
            <span>Apply this amount to all future months</span>
        </label>
        <InputError message={budgetErrors.apply_to_future_months} />
        {#if editingCategory.direction === 'expense'}
            <label class="mt-3 flex cursor-pointer items-start gap-2 text-sm">
                <input type="checkbox" class="mt-1 size-4 accent-emerald-600" checked={editingCategory.rollover_enabled} onchange={(event) => setRollover(editingCategory, event.currentTarget.checked)} data-test="category-rollover" />
                <span>Roll a positive or negative balance into next month</span>
            </label>
        {/if}
        <div class="mt-4 flex justify-end gap-2">
            <Button type="button" size="sm" variant="ghost" onclick={() => (editingCategoryId = null)}>Cancel</Button>
            <Button type="button" size="sm" class="bg-emerald-600 text-white hover:bg-emerald-700" onclick={() => saveBudget(editingCategory)}>Save</Button>
        </div>
    </div>
{/if}

{#if moveOpen}
    <div
        class="fixed inset-x-0 bottom-0 z-40 flex h-auto min-h-[min(560px,calc(100dvh-16px))] max-h-[calc(100dvh-16px)] w-full flex-col overflow-y-auto rounded-t-[24px] border bg-background px-6 pt-6 pb-8 shadow-2xl md:inset-y-6 md:right-[56px] md:left-auto md:h-[758px] md:min-h-[600px] md:max-h-[calc(100vh-48px)] md:w-[calc(100vw-48px)] md:max-w-[610px] md:rounded-[20px] md:px-8 md:pt-7 md:pb-8"
        role="dialog"
        tabindex="-1"
        aria-modal="false"
        aria-labelledby="move-budget-title"
        data-test="move-budget"
    >
        <div class="flex items-center justify-between border-b pb-5">
            <h2 id="move-budget-title" class="text-2xl font-semibold md:text-[30px]">Move Budget</h2>
            <button type="button" class="rounded-full p-2 text-muted-foreground hover:bg-muted" aria-label="Close move budget" onclick={() => (moveOpen = false)}>×</button>
        </div>
        <form class="flex min-h-0 flex-1 flex-col pt-7 md:pt-9" onsubmit={(event) => { event.preventDefault(); if (moveReady) moveFunds(); }}>
            <input type="hidden" name="year" value={year} />
            <input type="hidden" name="month" value={month} />
            <div class="space-y-7 md:space-y-8">
                {@render moveCategoryPicker('from_category_id', 'From')}
                {@render moveCategoryPicker('to_category_id', 'To')}
                <div class="grid gap-4">
                    <Label for="move-amount" class="text-lg font-semibold md:text-[22px]">Amount</Label>
                    <div class="relative">
                        <span class="pointer-events-none absolute top-1/2 left-6 -translate-y-1/2 text-2xl">$</span>
                        <Input id="move-amount" type="number" min="0" step="0.01" bind:value={moveForm.amount} class="h-[72px] rounded-[16px] border-neutral-300 pl-12 text-2xl shadow-none" />
                    </div>
                    <InputError message={moveErrors.amount} />
                </div>
            </div>
            <div class="mt-auto flex flex-col gap-3 pt-7 md:pt-12">
                {#if moveMemoOpen}
                    <div class="grid gap-2">
                        <Label for="move-memo">Memo</Label>
                        <Input id="move-memo" bind:value={moveForm.memo} />
                        <InputError message={moveErrors.memo} />
                    </div>
                {:else}
                    <button type="button" class="self-start text-sm font-medium text-muted-foreground underline-offset-4 hover:underline" onclick={() => (moveMemoOpen = true)} data-test="add-move-memo">Add a note</button>
                {/if}
                <div class="flex justify-end">
                    <Button type="submit" class="h-14 rounded-xl bg-emerald-600 px-8 text-lg font-semibold text-white hover:bg-emerald-700 disabled:opacity-50 md:h-[74px] md:px-9 md:text-2xl" disabled={!moveReady} data-test="submit-move-money">Save</Button>
                </div>
            </div>
        </form>
    </div>
{/if}

{#snippet moveCategoryPicker(field: MoveCategoryField, fieldLabel: string)}
    {@const fieldKey = field === 'from_category_id' ? 'from' : 'to'}
    {@const selectedCategory = selectedMoveCategory(field)}
    <div class="grid gap-4">
        <Label for={`move-${fieldKey}-picker`} class="text-lg font-semibold md:text-[22px]">{fieldLabel}</Label>
        <div class="relative">
            <button
                id={`move-${fieldKey}-picker`}
                type="button"
                class="flex h-[74px] w-full items-center gap-4 rounded-[16px] border border-neutral-300 bg-background px-5 text-left shadow-none hover:bg-muted/30 md:px-6"
                aria-haspopup="listbox"
                aria-expanded={movePicker === field}
                aria-controls={`move-${fieldKey}-options`}
                data-test={`move-${fieldKey}-picker`}
                onclick={() => toggleMovePicker(field)}
            >
                <span class="grid size-9 shrink-0 place-items-center text-[26px]" aria-hidden="true">{selectedCategory?.icon ?? ''}</span>
                <span class="min-w-0 flex-1 truncate text-lg font-medium md:text-2xl">{selectedCategory?.name ?? 'Choose a category'}</span>
                {#if selectedCategory}
                    <span class="shrink-0 text-sm font-medium tabular-nums md:text-xl {balanceClass(selectedCategory.id)}">{formatCents(selectedCategory.available_cents)} left</span>
                {/if}
                <ChevronDown class="size-5 shrink-0 text-muted-foreground" aria-hidden="true" />
            </button>
            {#if movePicker === field}
                <div id={`move-${fieldKey}-options`} class="absolute top-[calc(100%+8px)] right-0 left-0 z-10 max-h-72 overflow-y-auto rounded-xl border bg-background p-2 shadow-xl" role="listbox" aria-label={`${fieldLabel} categories`}>
                    {#each groups as group (group.id)}
                        {@const choices = group.categories.filter((category) => category.budgetable)}
                        {#if choices.length > 0}
                            <div role="group" aria-label={group.name}>
                                <p class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{group.name}</p>
                                {#each choices as category (category.id)}
                                    <button
                                        type="button"
                                        role="option"
                                        aria-selected={moveForm[field] === category.id}
                                        class="flex w-full items-center gap-3 rounded-lg px-3 py-3 text-left hover:bg-muted"
                                        data-test={`move-${fieldKey}-option-${category.id}`}
                                        onclick={() => chooseMoveCategory(field, category.id)}
                                    >
                                        <span class="grid size-8 shrink-0 place-items-center text-xl" aria-hidden="true">{category.icon ?? ''}</span>
                                        <span class="min-w-0 flex-1 truncate font-medium">{category.name}</span>
                                        <span class="shrink-0 text-sm tabular-nums {balanceClass(category.id)}">{formatCents(category.available_cents)} left</span>
                                    </button>
                                {/each}
                            </div>
                        {/if}
                    {/each}
                </div>
            {/if}
        </div>
        <InputError message={moveErrors[field]} />
    </div>
{/snippet}

<Dialog bind:open={categoryOpen}>
    <DialogContent>
        <DialogTitle>Add a category</DialogTitle>
        <DialogDescription>Categories are the only budget units. Choose a group to organize this category.</DialogDescription>
        <form class="mt-5 space-y-4" onsubmit={(event) => { event.preventDefault(); createCategory(); }}>
            <input type="hidden" name="year" value={year} />
            <input type="hidden" name="month" value={month} />
            <div class="grid gap-2"><Label for="category-name">Name</Label><Input id="category-name" bind:value={categoryForm.name} /> <InputError message={categoryErrors.name} /></div>
            <div class="grid gap-2">
                <Label for="category-group">Group</Label>
                <select id="category-group" class={selectClass} bind:value={categoryForm.category_group_id}>
                    {#each groupsForSelect as group (group.id)}<option value={group.id}>{group.name} · {group.direction}</option>{/each}
                </select>
                <InputError message={categoryErrors.category_group_id} />
            </div>
            <div class="grid gap-2 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="category-starting-month">Starting month</Label>
                    <Input id="category-starting-month" type="month" bind:value={categoryForm.starting_month} />
                    <InputError message={categoryErrors.starting_month} />
                </div>
                <div class="grid gap-2">
                    <Label for="category-starting-balance">Opening balance</Label>
                    <Input id="category-starting-balance" type="number" step="0.01" bind:value={categoryForm.starting_balance} />
                    <InputError message={categoryErrors.starting_balance} />
                </div>
            </div>
            <p class="text-xs text-muted-foreground">Opening balance carries into the starting month. Rollover can carry a positive or negative available balance into later months.</p>
            <DialogFooter>
                <Button type="button" variant="ghost" onclick={() => (categoryOpen = false)}>Cancel</Button>
                <Button type="submit" class="bg-emerald-600 text-white hover:bg-emerald-700" disabled={groupsForSelect.length === 0} data-test="submit-create-category">Create category</Button>
            </DialogFooter>
        </form>
    </DialogContent>
</Dialog>

<Dialog bind:open={groupOpen}>
    <DialogContent>
        <DialogTitle>Add a group</DialogTitle>
        <DialogDescription>Groups organize categories. They do not have their own budgets.</DialogDescription>
        <form class="mt-5 space-y-4" onsubmit={(event) => { event.preventDefault(); createGroup(); }}>
            <div class="grid gap-2"><Label for="group-name">Group name</Label><Input id="group-name" bind:value={groupName} /><InputError message={groupErrors.name} /></div>
            <div class="grid gap-2">
                <Label for="group-direction">Type</Label>
                <select id="group-direction" class={selectClass} bind:value={groupDirection}>
                    <option value="expense">Expense</option>
                    <option value="income">Income</option>
                    <option value="transfer">Transfer</option>
                </select>
                <InputError message={groupErrors.direction} />
            </div>
            <DialogFooter>
                <Button type="button" variant="ghost" onclick={() => (groupOpen = false)}>Cancel</Button>
                <Button type="submit" class="bg-emerald-600 text-white hover:bg-emerald-700" data-test="submit-create-group">Create group</Button>
            </DialogFooter>
        </form>
    </DialogContent>
</Dialog>
