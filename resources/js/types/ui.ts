export type Appearance = 'light' | 'dark' | 'system';
export type ResolvedAppearance = 'light' | 'dark';

export type AppVariant = 'header' | 'sidebar';

export type FlashToast = {
    type: 'success' | 'info' | 'warning' | 'error';
    message: string;
};

export type PlanToolbar = {
    monthLabel: string;
    previousUrl: string;
    nextUrl: string;
    todayUrl: string;
    onAddGroup: () => void;
    onAddCategory: () => void;
};
