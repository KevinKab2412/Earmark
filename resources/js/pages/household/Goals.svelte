<script module lang="ts">
    import { index } from '@/routes/household/goals';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Goals',
                href: index(),
            },
        ],
    };
</script>

<script lang="ts">
    import { Form, router } from '@inertiajs/svelte';
    import GoalController from '@/actions/App/Http/Controllers/Household/GoalController';
    import ActionableEmptyState from '@/components/ActionableEmptyState.svelte';
    import AppHead from '@/components/AppHead.svelte';
    import ErrorSummary from '@/components/ErrorSummary.svelte';
    import Heading from '@/components/Heading.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogTitle } from '@/components/ui/dialog';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';

    type GoalType = 'save_up' | 'pay_down';
    type GoalStatus = 'active' | 'achieved' | 'archived';
    type Option = { id: string; name: string; group_name?: string; archived?: boolean };
    type PaymentCategory = Option & { direction: 'expense' | 'transfer' };
    type OptionGroup<T extends Option = Option> = { name: string; options: T[] };

    type Goal = {
        id: string;
        name: string;
        type: GoalType;
        status: GoalStatus;
        target_amount: number;
        target_amount_formatted: string;
        target_date: string | null;
        linked_account_id: string | null;
        linked_category_id: string | null;
        linked_position_id: string | null;
        payment_category_id: string | null;
        linked_to: string | null;
        payment_category: string | null;
        payment_category_direction?: 'expense' | 'transfer' | null;
        apr_bps: number | null;
        required_payment: number | null;
        principal: number | null;
        progress?: { current: string; remaining: string; percent: number };
        principal_formatted?: string;
        apr?: string | null;
        required_payment_formatted?: string | null;
        payoff?: { payable: boolean; months: number | null; payoff_date: string | null; total_interest: string | null };
    };

    let {
        goals,
        types,
        accounts,
        categories,
        paymentCategories,
        positions,
        statusFilter,
    }: {
        goals: Goal[];
        types: GoalType[];
        accounts: Option[];
        categories: Option[];
        paymentCategories: PaymentCategory[];
        positions: Option[];
        statusFilter: 'active' | 'archived';
    } = $props();

    const selectClass = 'flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm';
    const categoryGroups = $derived(groupOptions(categories));
    const paymentCategoryGroups = $derived(groupOptions(paymentCategories));

    let showForm = $state(false);
    let editingGoal = $state<Goal | null>(null);
    let formType = $state<GoalType>('save_up');
    let debtSource = $state<'position' | 'account'>('position');
    let selectedCategoryId = $state('');
    let selectedAccountId = $state('');
    let selectedPositionId = $state('');
    let selectedPaymentCategoryId = $state('');

    function groupOptions<T extends Option>(options: T[]): OptionGroup<T>[] {
        const groups = new Map<string, T[]>();

        for (const option of options) {
            const groupName = option.group_name ?? 'Other';
            groups.set(groupName, [...(groups.get(groupName) ?? []), option]);
        }

        return [...groups.entries()]
            .sort(([first], [second]) => first.localeCompare(second))
            .map(([name, groupedOptions]) => ({ name, options: groupedOptions }));
    }

    function centsToInput(value: number | null): string {
        return value === null ? '' : (value / 100).toFixed(2);
    }

    function amountToCents(value: unknown): number | null {
        if (value === null || value === undefined || value === '') return null;

        const amount = Number(value);
        return Number.isFinite(amount) ? Math.round(amount * 100) : null;
    }

    function percentageToBasisPoints(value: unknown): number | null {
        if (value === null || value === undefined || value === '') return null;

        const percentage = Number(value);
        return Number.isFinite(percentage) ? Math.round(percentage * 100) : null;
    }

    function transformGoalData(data: Record<string, any>): Record<string, any> {
        const { apr_percent, payment_amount, ...fields } = data;

        return {
            ...fields,
            target_amount: formType === 'save_up' ? amountToCents(data.target_amount) ?? 0 : 0,
            target_date: formType === 'save_up' ? fields.target_date ?? null : null,
            principal: formType === 'pay_down' ? editingGoal?.principal ?? null : null,
            apr_bps: formType === 'pay_down' ? percentageToBasisPoints(apr_percent) : null,
            required_payment: formType === 'pay_down' ? amountToCents(payment_amount) : null,
            status: data.status ?? editingGoal?.status ?? 'active',
        };
    }

    function startCreatingGoal(): void {
        editingGoal = null;
        formType = 'save_up';
        debtSource = 'position';
        selectedCategoryId = '';
        selectedAccountId = '';
        selectedPositionId = '';
        selectedPaymentCategoryId = '';
        showForm = true;
    }

    function startEditingGoal(goal: Goal): void {
        editingGoal = goal;
        formType = goal.type;
        debtSource = goal.linked_account_id ? 'account' : 'position';
        selectedCategoryId = goal.linked_category_id ?? '';
        selectedAccountId = goal.linked_account_id ?? '';
        selectedPositionId = goal.linked_position_id ?? '';
        selectedPaymentCategoryId = goal.payment_category_id ?? '';
        showForm = true;
    }

    function move(i: number, direction: -1 | 1): void {
        const next = i + direction;
        if (next < 0 || next >= goals.length) return;
        const ids = goals.map((goal) => goal.id);
        [ids[i], ids[next]] = [ids[next], ids[i]];
        router.post(GoalController.reorder.url(), { ids }, { preserveScroll: true });
    }

    function changeStatus(goal: Goal, status: GoalStatus): void {
        router.patch(
            GoalController.update.url(goal.id),
            {
                name: goal.name,
                type: goal.type,
                target_amount: goal.target_amount,
                target_date: goal.target_date,
                apr_bps: goal.apr_bps,
                required_payment: goal.required_payment,
                linked_account_id: goal.linked_account_id,
                linked_category_id: goal.linked_category_id,
                linked_position_id: goal.linked_position_id,
                payment_category_id: goal.payment_category_id,
                status,
            },
            {
                preserveScroll: true,
                onError: (errors) => {
                    const message = Object.values(errors).find((value) => typeof value === 'string');
                    if (message) window.alert(message);
                },
            },
        );
    }

    function remove(goal: Goal): void {
        if (confirm(`Remove goal "${goal.name}" permanently?`)) {
            router.delete(GoalController.destroy(goal.id).url, { preserveScroll: true });
        }
    }

    function setStatusFilter(filter: 'active' | 'archived'): void {
        const url = filter === 'archived'
            ? index({ query: { status: 'archived' } }).url
            : index().url;
        router.get(url, {}, { preserveScroll: true });
    }
</script>

<AppHead title="Goals" />

<div class="flex flex-col gap-4 px-6 py-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <Heading
            title="Goals"
            description="Savings goals follow a category balance. Debt goals follow a liability balance and can point to a payment category."
        />
        <Button type="button" data-test="open-create-goal" onclick={startCreatingGoal}>Add goal</Button>
    </div>

    <div class="flex items-center gap-2" aria-label="Goal status filter">
        <Button
            type="button"
            variant={statusFilter === 'active' ? 'secondary' : 'ghost'}
            data-test="show-active-goals"
            aria-pressed={statusFilter === 'active'}
            onclick={() => setStatusFilter('active')}
        >Active</Button>
        <Button
            type="button"
            variant={statusFilter === 'archived' ? 'secondary' : 'ghost'}
            data-test="show-archived-goals"
            aria-pressed={statusFilter === 'archived'}
            onclick={() => setStatusFilter('archived')}
        >Archived</Button>
    </div>

    {#if goals.length === 0 && statusFilter === 'active'}
        <ActionableEmptyState title="No goals yet" description="Link a category or debt balance and set a target.">
            {#snippet action()}
                <Button type="button" onclick={startCreatingGoal}>Add goal</Button>
            {/snippet}
        </ActionableEmptyState>
    {:else if goals.length === 0}
        <div class="rounded-xl border border-dashed p-8 text-center">
            <h2 class="font-medium">No archived goals</h2>
            <p class="mt-1 text-sm text-muted-foreground">Archived goals will appear here.</p>
        </div>
    {:else}
        <div class="flex flex-col gap-3">
            {#each goals as goal, i (goal.id)}
                <div class="rounded-xl border p-4" data-test="goal-card">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h2 class="font-medium">{goal.name}</h2>
                            <p class="text-xs text-muted-foreground">
                                {goal.type === 'save_up' ? 'Save up' : 'Pay down'}
                                {#if goal.linked_to}· linked to {goal.linked_to}{/if}
                                {#if goal.target_date}· by {goal.target_date}{/if}
                                {#if goal.status === 'achieved'}· Achieved{/if}
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-1">
                            {#if statusFilter === 'active'}
                                <Button type="button" variant="ghost" size="sm" aria-label="Move goal up" onclick={() => move(i, -1)} disabled={i === 0}>↑</Button>
                                <Button type="button" variant="ghost" size="sm" aria-label="Move goal down" onclick={() => move(i, 1)} disabled={i === goals.length - 1}>↓</Button>
                            {/if}
                            <Button type="button" variant="ghost" size="sm" data-test="edit-goal" onclick={() => startEditingGoal(goal)}>Edit</Button>
                            {#if goal.status === 'archived'}
                                <Button type="button" variant="ghost" size="sm" data-test="restore-goal" onclick={() => changeStatus(goal, 'active')}>Restore</Button>
                            {:else}
                                <Button type="button" variant="ghost" size="sm" data-test="archive-goal" onclick={() => changeStatus(goal, 'archived')}>Archive</Button>
                            {/if}
                            <Button type="button" variant="ghost" size="sm" data-test="remove-goal" onclick={() => remove(goal)}>Delete</Button>
                        </div>
                    </div>

                    {#if goal.type === 'save_up' && goal.progress}
                        <div class="mt-3">
                            <div class="h-2 w-full overflow-hidden rounded-full bg-muted">
                                <div class="h-full bg-emerald-500" style="width: {goal.progress.percent}%"></div>
                            </div>
                            <p class="mt-1 text-sm text-muted-foreground">
                                {goal.progress.current} of {goal.target_amount_formatted}
                                ({goal.progress.percent}%) · {goal.progress.remaining} to go
                            </p>
                        </div>
                    {:else if goal.type === 'pay_down'}
                        <div class="mt-3 grid gap-2 text-sm sm:grid-cols-2 lg:grid-cols-4">
                            <div><span class="text-muted-foreground">Principal</span><br />{goal.principal_formatted}</div>
                            <div><span class="text-muted-foreground">APR</span><br />{goal.apr ?? '—'}</div>
                            <div><span class="text-muted-foreground">Payment category</span><br />{goal.payment_category ?? '—'}</div>
                            <div><span class="text-muted-foreground">Required payment</span><br />{goal.required_payment_formatted ?? '—'}</div>
                            <div class="sm:col-span-2 lg:col-span-4">
                                <span class="text-muted-foreground">Projected payoff</span><br />
                                {#if goal.payoff?.payable}
                                    {goal.payoff.months} mo (by {goal.payoff.payoff_date}) · {goal.payoff.total_interest} interest
                                {:else}
                                    Payment too low to pay off
                                {/if}
                            </div>
                        </div>
                    {/if}
                </div>
            {/each}
        </div>
    {/if}
</div>

<Dialog bind:open={showForm}>
    <DialogContent class="max-h-[calc(100vh-2rem)] overflow-y-auto sm:max-w-xl">
        <div class="mb-5 flex flex-col gap-2">
            <DialogTitle>{editingGoal ? 'Edit goal' : 'Add goal'}</DialogTitle>
            <DialogDescription>
                Savings progress follows the linked expense category. Debt progress follows one liability balance.
            </DialogDescription>
        </div>

        <Form
            action={editingGoal ? GoalController.update.url(editingGoal.id) : GoalController.store.url()}
            method={editingGoal ? 'patch' : 'post'}
            transform={transformGoalData}
            onSuccess={() => (showForm = false)}
            class="space-y-4"
        >
            {#snippet children({ errors, processing })}
                <ErrorSummary errors={Object.entries(errors).map(([fieldId, message]) => ({ fieldId, message }))} />
                <input type="hidden" name="status" value={editingGoal?.status ?? 'active'} />

                <div class="grid gap-2">
                    <Label for="goal-name">Name</Label>
                    <Input id="goal-name" name="name" value={editingGoal?.name ?? ''} required />
                    <InputError message={errors.name} />
                </div>
                <div class="grid gap-2">
                    <Label for="goal-type">Type</Label>
                    <select id="goal-type" name="type" class={selectClass} bind:value={formType}>
                        {#each types as type (type)}
                            <option value={type}>{type === 'save_up' ? 'Save up' : 'Pay down'}</option>
                        {/each}
                    </select>
                </div>

                {#if formType === 'save_up'}
                    <input type="hidden" name="linked_account_id" value="" />
                    <input type="hidden" name="linked_position_id" value="" />
                    <input type="hidden" name="payment_category_id" value="" />
                    <div class="grid gap-2">
                        <Label for="goal-target">Target amount</Label>
                        <Input id="goal-target" name="target_amount" type="number" min="0.01" step="0.01" value={editingGoal ? centsToInput(editingGoal.target_amount) : ''} required />
                        <InputError message={errors.target_amount} />
                    </div>
                    <div class="grid gap-2">
                        <Label for="goal-date">Target date (optional)</Label>
                        <Input id="goal-date" name="target_date" type="date" value={editingGoal?.target_date ?? ''} />
                    </div>
                    <div class="grid gap-2">
                        <Label for="goal-category">Savings category</Label>
                        <select id="goal-category" name="linked_category_id" class={selectClass} bind:value={selectedCategoryId} required>
                            <option value="">Choose a category</option>
                            {#each categoryGroups as group (group.name)}
                                <optgroup label={group.name}>
                                    {#each group.options as category (category.id)}
                                        <option value={category.id}>{category.name}{category.archived ? ' (archived)' : ''}</option>
                                    {/each}
                                </optgroup>
                            {/each}
                        </select>
                        <InputError message={errors.linked_category_id} />
                    </div>
                {:else}
                    <input type="hidden" name="linked_category_id" value="" />
                    <input type="hidden" name="target_amount" value="0" />
                    <div class="grid gap-2">
                        <Label for="goal-apr">APR</Label>
                        <div class="relative">
                            <Input id="goal-apr" name="apr_percent" type="number" min="0" max="1000" step="0.01" value={editingGoal?.apr_bps === null || editingGoal?.apr_bps === undefined ? '' : (editingGoal.apr_bps / 100).toFixed(2)} class="pr-8" />
                            <span class="absolute inset-y-0 right-3 flex items-center text-sm text-muted-foreground">%</span>
                        </div>
                        <InputError message={errors.apr_bps} />
                    </div>
                    <div class="grid gap-2">
                        <Label for="goal-payment">Required monthly payment (optional)</Label>
                        <Input id="goal-payment" name="payment_amount" type="number" min="0" step="0.01" value={editingGoal?.required_payment === null || editingGoal?.required_payment === undefined ? '' : centsToInput(editingGoal.required_payment)} />
                        <InputError message={errors.required_payment} />
                    </div>
                    <div class="grid gap-2">
                        <Label for="goal-debt-source">Debt balance</Label>
                        <select id="goal-debt-source" class={selectClass} bind:value={debtSource}>
                            <option value="position">Liability position</option>
                            <option value="account">Debt account</option>
                        </select>
                    </div>
                    {#if debtSource === 'position'}
                        <input type="hidden" name="linked_account_id" value="" />
                        <div class="grid gap-2">
                            <Label for="goal-liability">Liability position</Label>
                            <select id="goal-liability" name="linked_position_id" class={selectClass} bind:value={selectedPositionId} required>
                                <option value="">Choose a liability position</option>
                                {#each positions as position (position.id)}<option value={position.id}>{position.name}{position.archived ? ' (archived)' : ''}</option>{/each}
                            </select>
                            <InputError message={errors.linked_position_id} />
                        </div>
                    {:else}
                        <input type="hidden" name="linked_position_id" value="" />
                        <div class="grid gap-2">
                            <Label for="goal-debt-account">Debt account</Label>
                            <select id="goal-debt-account" name="linked_account_id" class={selectClass} bind:value={selectedAccountId} required>
                                <option value="">Choose a debt account</option>
                                {#each accounts as account (account.id)}<option value={account.id}>{account.name}{account.archived ? ' (archived)' : ''}</option>{/each}
                            </select>
                            <InputError message={errors.linked_account_id} />
                        </div>
                    {/if}
                    <div class="grid gap-2">
                        <Label for="goal-payment-category">Payment category (optional)</Label>
                        <select id="goal-payment-category" name="payment_category_id" class={selectClass} bind:value={selectedPaymentCategoryId}>
                            <option value="">None</option>
                            {#each paymentCategoryGroups as group (group.name)}
                                <optgroup label={group.name}>
                                    {#each group.options as category (category.id)}
                                        <option value={category.id}>{category.name}{category.archived ? ' (archived)' : ''} · {category.direction}</option>
                                    {/each}
                                </optgroup>
                            {/each}
                        </select>
                        <p class="text-xs text-muted-foreground">Transfer payment categories track payments without counting them as expenses.</p>
                        <InputError message={errors.payment_category_id} />
                    </div>
                {/if}

                <DialogFooter>
                    <Button type="button" variant="ghost" onclick={() => (showForm = false)}>Cancel</Button>
                    <Button type="submit" disabled={processing} data-test="submit-goal">
                        {processing ? 'Saving…' : editingGoal ? 'Save changes' : 'Save goal'}
                    </Button>
                </DialogFooter>
            {/snippet}
        </Form>
    </DialogContent>
</Dialog>
