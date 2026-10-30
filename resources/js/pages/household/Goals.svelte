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
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';

    type Option = { id: string; name: string };

    type Goal = {
        id: string;
        name: string;
        type: string;
        target_amount_formatted: string;
        target_date: string | null;
        linked_to: string | null;
        payment_category: string | null;
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
        defaults,
    }: {
        goals: Goal[];
        types: string[];
        accounts: Option[];
        categories: Option[];
        paymentCategories: Option[];
        positions: Option[];
        defaults: { target_date: string };
    } = $props();

    const selectClass =
        'flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm';

    let showForm = $state(false);
    let formType = $state('save_up');

    function move(i: number, direction: -1 | 1): void {
        const next = i + direction;
        if (next < 0 || next >= goals.length) return;
        const ids = goals.map((goal) => goal.id);
        [ids[i], ids[next]] = [ids[next], ids[i]];
        router.post(GoalController.reorder.url(), { ids }, { preserveScroll: true });
    }

    function remove(goal: Goal): void {
        if (confirm(`Remove goal "${goal.name}"?`)) {
            router.delete(GoalController.destroy(goal.id).url, { preserveScroll: true });
        }
    }
</script>

<AppHead title="Goals" />

<div class="flex flex-col gap-4 px-6 py-5">
    <div class="flex items-center justify-between">
        <Heading
            title="Goals"
            description="Savings goals follow a category balance. Debt goals follow a liability balance and can point to a payment category."
        />
        <Button type="button" data-test="open-create-goal" onclick={() => (showForm = true)}>Add goal</Button>
    </div>

    {#if goals.length === 0 && !showForm}
        <ActionableEmptyState title="No goals yet" description="Link a category or debt balance and set a target.">
            {#snippet action()}
                <Button type="button" onclick={() => (showForm = true)}>Add goal</Button>
            {/snippet}
        </ActionableEmptyState>
    {:else}
        <div class="flex flex-col gap-3">
            {#each goals as goal, i (goal.id)}
                <div class="rounded-xl border p-4" data-test="goal-card">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="font-medium">{goal.name}</h2>
                            <p class="text-xs text-muted-foreground">
                                {goal.type === 'save_up' ? 'Save up' : 'Pay down'}
                                {#if goal.linked_to}· linked to {goal.linked_to}{/if}
                                {#if goal.target_date}· by {goal.target_date}{/if}
                            </p>
                        </div>
                        <div class="flex gap-1">
                            <Button type="button" variant="ghost" size="sm" onclick={() => move(i, -1)} disabled={i === 0}>↑</Button>
                            <Button type="button" variant="ghost" size="sm" onclick={() => move(i, 1)} disabled={i === goals.length - 1}>↓</Button>
                            <Button type="button" variant="ghost" size="sm" onclick={() => remove(goal)}>Remove</Button>
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
                            <div><span class="text-muted-foreground">Payment</span><br />{goal.required_payment_formatted ?? '—'}</div>
                            <div>
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

    {#if showForm}
        <Form {...GoalController.store.form()} class="max-w-lg space-y-4 rounded-xl border p-4">
            {#snippet children({ errors, processing })}
                <h2 class="font-semibold">Add goal</h2>
                <ErrorSummary errors={Object.entries(errors).map(([fieldId, message]) => ({ fieldId, message }))} />
                <div class="grid gap-2">
                    <Label for="goal-name">Name</Label>
                    <Input id="goal-name" name="name" required />
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
                    <div class="grid gap-2">
                        <Label for="goal-target">Target amount (cents)</Label>
                        <Input id="goal-target" name="target_amount" type="number" min="0" step="1" value="0" required />
                        <InputError message={errors.target_amount} />
                    </div>
                    <div class="grid gap-2">
                        <Label for="goal-date">Target date</Label>
                        <Input id="goal-date" name="target_date" type="date" value={defaults.target_date} />
                    </div>
                    <div class="grid gap-2">
                        <Label for="goal-category">Savings category</Label>
                        <select id="goal-category" name="linked_category_id" class={selectClass} required>
                            <option value="">Choose a category</option>
                            {#each categories as category (category.id)}<option value={category.id}>{category.name}</option>{/each}
                        </select>
                        <InputError message={errors.linked_category_id} />
                    </div>
                {:else}
                    <input type="hidden" name="target_amount" value="0" />
                    <div class="grid gap-2">
                        <Label for="goal-apr">APR (basis points, e.g. 500 = 5%)</Label>
                        <Input id="goal-apr" name="apr_bps" type="number" min="0" step="1" />
                        <InputError message={errors.apr_bps} />
                    </div>
                    <div class="grid gap-2">
                        <Label for="goal-payment">Required monthly payment (cents)</Label>
                        <Input id="goal-payment" name="required_payment" type="number" min="0" step="1" />
                        <InputError message={errors.required_payment} />
                    </div>
                    <div class="grid gap-2">
                        <Label for="goal-liability">Liability position</Label>
                        <select id="goal-liability" name="linked_position_id" class={selectClass}>
                            <option value="">Choose a liability position</option>
                            {#each positions as position (position.id)}<option value={position.id}>{position.name}</option>{/each}
                        </select>
                        <InputError message={errors.linked_position_id} />
                    </div>
                    <div class="grid gap-2">
                        <Label for="goal-debt-account">Or debt account</Label>
                        <select id="goal-debt-account" name="linked_account_id" class={selectClass}>
                            <option value="">Choose a debt account</option>
                            {#each accounts as account (account.id)}<option value={account.id}>{account.name}</option>{/each}
                        </select>
                        <InputError message={errors.linked_account_id} />
                    </div>
                    <div class="grid gap-2">
                        <Label for="goal-payment-category">Payment category (optional)</Label>
                        <select id="goal-payment-category" name="payment_category_id" class={selectClass}>
                            <option value="">(none)</option>
                            {#each paymentCategories as category (category.id)}<option value={category.id}>{category.name}</option>{/each}
                        </select>
                    </div>
                {/if}

                <div class="flex justify-end gap-2">
                    <Button type="button" variant="ghost" onclick={() => (showForm = false)}>Cancel</Button>
                    <Button type="submit" disabled={processing} data-test="submit-goal">Save goal</Button>
                </div>
            {/snippet}
        </Form>
    {/if}
</div>
