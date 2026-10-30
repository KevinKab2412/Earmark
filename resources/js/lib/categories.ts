// Category appearance follows its budgeting role across transactions and imports.
export type CategoryDirection = 'income' | 'expense' | 'transfer' | 'adjustment';

export type CategoryStyle = { icon: string; classes: string };

const STYLES: Record<CategoryDirection, CategoryStyle> = {
    income: { icon: '↗', classes: 'bg-emerald-100 text-emerald-800' },
    expense: { icon: '•', classes: 'bg-green-100 text-green-800' },
    transfer: { icon: '⇄', classes: 'bg-sky-100 text-sky-800' },
    adjustment: { icon: '⚖', classes: 'bg-slate-100 text-slate-700' },
};

const FALLBACK: CategoryStyle = {
    icon: '•',
    classes: 'bg-muted text-muted-foreground',
};

export function categoryStyle(type?: string | null): CategoryStyle {
    return (type && STYLES[type as CategoryDirection]) || FALLBACK;
}
