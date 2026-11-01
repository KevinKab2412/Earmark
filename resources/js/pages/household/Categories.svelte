<script module lang="ts">
    import { index } from '@/routes/household/categories';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Categories',
                href: index(),
            },
        ],
    };
</script>

<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import CategoryController from '@/actions/App/Http/Controllers/Household/CategoryController';
    import CategoryGroupController from '@/actions/App/Http/Controllers/Household/CategoryGroupController';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import {
        Dialog,
        DialogContent,
        DialogDescription,
        DialogFooter,
        DialogTitle,
    } from '@/components/ui/dialog';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';

    type Direction = 'income' | 'expense' | 'transfer';
    type Category = {
        id: string;
        name: string;
        icon: string | null;
        kind: string;
        budgetable: boolean;
        starting_month: string | null;
        starting_balance: string;
    };
    type CategoryGroup = {
        id: string;
        name: string;
        direction: Direction;
        categories: Category[];
    };

    let { groups }: { groups: CategoryGroup[] } = $props();
    let direction = $state<Direction>('expense');
    let groupDialogOpen = $state(false);
    let editingGroupId = $state<string | null>(null);
    let groupName = $state('');
    let groupErrors = $state<Record<string, string>>({});
    let categoryDialogOpen = $state(false);
    let editingCategoryId = $state<string | null>(null);
    let categoryForm = $state({
        name: '',
        category_group_id: '',
        icon: '',
        starting_month: new Date().toISOString().slice(0, 7),
        starting_balance: '0.00',
    });
    let categoryErrors = $state<Record<string, string>>({});

    const directions: { key: Direction; label: string }[] = [
        { key: 'income', label: 'Income' },
        { key: 'expense', label: 'Expenses' },
        { key: 'transfer', label: 'Transfers' },
    ];
    const visibleGroups = $derived(groups.filter((group) => group.direction === direction));
    const editingCategory = $derived(
        visibleGroups.flatMap((group) => group.categories).find((category) => category.id === editingCategoryId),
    );
    const availableGroups = $derived(visibleGroups);
    const isBudgetable = $derived(direction !== 'transfer' && (editingCategory?.kind ?? 'standard') === 'standard');

    function openCreateGroup(): void {
        editingGroupId = null;
        groupName = '';
        groupErrors = {};
        groupDialogOpen = true;
    }

    function openEditGroup(group: CategoryGroup): void {
        editingGroupId = group.id;
        groupName = group.name;
        groupErrors = {};
        groupDialogOpen = true;
    }

    function saveGroup(): void {
        groupErrors = {};
        const options = {
            preserveScroll: true,
            onSuccess: () => (groupDialogOpen = false),
            onError: (errors: Record<string, string>) => (groupErrors = errors),
        };

        if (editingGroupId) {
            router.patch(CategoryGroupController.update(editingGroupId).url, { name: groupName }, options);
            return;
        }

        router.post(CategoryGroupController.store.url(), { name: groupName, direction }, options);
    }

    function openCreateCategory(group: CategoryGroup): void {
        editingCategoryId = null;
        categoryErrors = {};
        categoryForm = {
            name: '',
            category_group_id: group.id,
            icon: '',
            starting_month: new Date().toISOString().slice(0, 7),
            starting_balance: '0.00',
        };
        categoryDialogOpen = true;
    }

    function openEditCategory(group: CategoryGroup, category: Category): void {
        editingCategoryId = category.id;
        categoryErrors = {};
        categoryForm = {
            name: category.name,
            category_group_id: group.id,
            icon: category.icon ?? '',
            starting_month: category.starting_month ?? new Date().toISOString().slice(0, 7),
            starting_balance: category.starting_balance,
        };
        categoryDialogOpen = true;
    }

    function saveCategory(): void {
        categoryErrors = {};
        const payload = {
            ...categoryForm,
            icon: categoryForm.icon.trim() || null,
        };
        const options = {
            preserveScroll: true,
            onSuccess: () => (categoryDialogOpen = false),
            onError: (errors: Record<string, string>) => (categoryErrors = errors),
        };

        if (editingCategoryId) {
            router.patch(CategoryController.update(editingCategoryId).url, payload, options);
            return;
        }

        router.post(CategoryController.store.url(), payload, options);
    }
</script>

<AppHead title="Categories" />

<div class="flex flex-col gap-6 px-5 py-6 sm:px-8" data-test="categories-page">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Categories</h1>
            <p class="mt-1 text-sm text-muted-foreground">Organize the categories used by your budget and transactions.</p>
        </div>
        <Button
            type="button"
            variant="ghost"
            class="text-base font-semibold text-emerald-700 hover:bg-emerald-50 hover:text-emerald-800"
            onclick={openCreateGroup}
            data-test="create-category-group"
        >
            Create group
        </Button>
    </div>

    <div class="flex flex-wrap gap-2 border-b border-neutral-200" role="tablist" aria-label="Category direction">
        {#each directions as item (item.key)}
            <button
                type="button"
                role="tab"
                aria-selected={direction === item.key}
                class="border-b-2 px-3 py-2 text-sm font-medium transition-colors {direction === item.key
                    ? 'border-emerald-600 text-emerald-700'
                    : 'border-transparent text-neutral-500 hover:text-neutral-800'}"
                onclick={() => (direction = item.key)}
                data-test="category-tab-{item.key}"
            >
                {item.label}
            </button>
        {/each}
    </div>

    {#if visibleGroups.length === 0}
        <section class="rounded-2xl border border-dashed border-neutral-300 bg-neutral-50 px-6 py-12 text-center">
            <h2 class="text-lg font-semibold">No {direction === 'expense' ? 'expense' : direction} groups yet</h2>
            <p class="mx-auto mt-2 max-w-md text-sm text-muted-foreground">Create a group to organize related categories. Budgets are set on each category.</p>
            <Button type="button" class="mt-5 bg-emerald-600 text-white hover:bg-emerald-700" onclick={openCreateGroup}>Create group</Button>
        </section>
    {:else}
        <div class="flex flex-col gap-5">
            {#each visibleGroups as group (group.id)}
                <section class="overflow-hidden rounded-2xl bg-stone-100" data-test="category-group-{group.id}">
                    <header class="flex items-center gap-3 border-b border-stone-200 px-5 py-4 sm:px-7">
                        <h2 class="text-lg font-semibold">{group.name}</h2>
                        <button
                            type="button"
                            class="text-sm font-medium text-neutral-500 hover:text-neutral-900"
                            onclick={() => openEditGroup(group)}
                            data-test="edit-group-{group.id}"
                        >
                            Edit
                        </button>
                    </header>

                    <div class="flex flex-col gap-2 px-4 py-4 sm:px-6">
                        {#each group.categories as category (category.id)}
                            <div class="flex min-h-16 items-center gap-3 rounded-xl bg-white px-4 py-3 shadow-sm">
                                <span class="w-8 shrink-0 text-center text-xl" aria-hidden="true">{category.icon ?? '◦'}</span>
                                <span class="min-w-0 flex-1 truncate text-base">{category.name}</span>
                                {#if category.kind === 'balance_adjustment'}
                                    <span class="hidden text-xs text-muted-foreground sm:inline">System category</span>
                                {/if}
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    class="text-neutral-500 hover:text-neutral-900"
                                    onclick={() => openEditCategory(group, category)}
                                    data-test="edit-category-{category.id}"
                                >
                                    Edit
                                </Button>
                            </div>
                        {:else}
                            <p class="rounded-xl bg-white px-4 py-5 text-sm text-muted-foreground">No categories in this group yet.</p>
                        {/each}
                    </div>

                    <div class="px-5 pb-4 sm:px-7">
                        <button
                            type="button"
                            class="text-sm font-semibold text-neutral-500 hover:text-emerald-700"
                            onclick={() => openCreateCategory(group)}
                            data-test="create-category-in-{group.id}"
                        >
                            Create category
                        </button>
                    </div>
                </section>
            {/each}
        </div>
    {/if}
</div>

<Dialog bind:open={groupDialogOpen}>
    <DialogContent>
        <DialogTitle>{editingGroupId ? 'Edit group' : 'Create group'}</DialogTitle>
        <DialogDescription>
            {editingGroupId ? 'Groups stay within the selected direction.' : `This group will contain ${direction === 'expense' ? 'expense' : direction} categories.`}
        </DialogDescription>
        <div class="mt-4 grid gap-2">
            <Label for="category-group-name">Group name</Label>
            <Input id="category-group-name" bind:value={groupName} maxlength={255} data-test="category-group-name" />
            <InputError message={groupErrors.name} />
        </div>
        <DialogFooter>
            <Button type="button" variant="ghost" onclick={() => (groupDialogOpen = false)}>Cancel</Button>
            <Button
                type="button"
                class="bg-emerald-600 text-white hover:bg-emerald-700"
                onclick={saveGroup}
                disabled={groupName.trim() === ''}
                data-test="submit-category-group"
            >
                Save
            </Button>
        </DialogFooter>
    </DialogContent>
</Dialog>

<Dialog bind:open={categoryDialogOpen}>
    <DialogContent class="max-h-[85vh] overflow-y-auto">
        <DialogTitle>{editingCategoryId ? 'Edit category' : 'Create category'}</DialogTitle>
        <DialogDescription>Categories are the units used for planning and transaction activity.</DialogDescription>
        <div class="mt-4 flex flex-col gap-4">
            <div class="grid gap-2">
                <Label for="category-name">Category name</Label>
                <Input id="category-name" bind:value={categoryForm.name} maxlength={255} data-test="category-name" />
                <InputError message={categoryErrors.name} />
            </div>
            <div class="grid gap-2">
                <Label for="category-icon">Icon</Label>
                <Input id="category-icon" bind:value={categoryForm.icon} maxlength={80} placeholder="Emoji or short symbol" data-test="category-icon" />
                <InputError message={categoryErrors.icon} />
            </div>
            {#if availableGroups.length > 1}
                <div class="grid gap-2">
                    <Label for="category-group">Group</Label>
                    <select id="category-group" bind:value={categoryForm.category_group_id} class="flex h-10 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm">
                        {#each availableGroups as group (group.id)}
                            <option value={group.id}>{group.name}</option>
                        {/each}
                    </select>
                    <InputError message={categoryErrors.category_group_id} />
                </div>
            {:else}
                <input type="hidden" name="category_group_id" value={categoryForm.category_group_id} />
            {/if}
            {#if isBudgetable}
                <div class="grid gap-2">
                    <Label for="category-starting-month">Starting month</Label>
                    <Input id="category-starting-month" type="month" bind:value={categoryForm.starting_month} data-test="category-starting-month" />
                    <InputError message={categoryErrors.starting_month} />
                </div>
                <div class="grid gap-2">
                    <Label for="category-starting-balance">Opening balance</Label>
                    <Input id="category-starting-balance" bind:value={categoryForm.starting_balance} inputmode="decimal" data-test="category-starting-balance" />
                    <InputError message={categoryErrors.starting_balance} />
                </div>
            {/if}
        </div>
        <DialogFooter>
            <Button type="button" variant="ghost" onclick={() => (categoryDialogOpen = false)}>Cancel</Button>
            <Button
                type="button"
                class="bg-emerald-600 text-white hover:bg-emerald-700"
                onclick={saveCategory}
                disabled={categoryForm.name.trim() === ''}
                data-test="submit-category"
            >
                Save
            </Button>
        </DialogFooter>
    </DialogContent>
</Dialog>
