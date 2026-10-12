/** The installable app a page belongs to, and its <head> tags (UsePwa::describe, CHW-35). */
export type Pwa = {
    app: 'customer' | 'business';
    manifest: string;
    meta: Record<string, string>;
} | null;
