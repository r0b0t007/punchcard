/** A business or network the user may work in (DescribeTenants, CHW-147). */
export type TenantEntry = {
    /** As SetTenant keeps it: "business:7" or "org:5" (a network). */
    choice: string;
    name: string;
    /** The user's role there, translated. */
    role: string;
    /** The organization's logo, if it has one. */
    logoUrl: string | null;
    initial: string;
    current: boolean;
};

/** The tenant switcher (CHW-147): where the user works now, and everything they may choose, by organization. */
export type Tenants = {
    current: TenantEntry | null;
    groups: { organization: string; entries: TenantEntry[] }[];
} | null;
