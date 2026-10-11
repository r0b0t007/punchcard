import type { LocalMoment } from '@/lib/local-moment';

/** A figure over the last 30 days and its change against the 30 before (null: nothing before). */
export type Kpi = { value: number; change: number | null };

/** DescribeDashboard (CHW-33): the owner dashboard of one business or location. */
export type DashboardStats = {
    kpis: {
        activeCustomers: Kpi;
        visits: Kpi;
        /** In percent; its change is in points. */
        returnRate: Kpi;
        rewardsRedeemed: Kpi;
    };
    /** The last 30 local days, oldest first. */
    visitsPerDay: { date: string; visits: number }[];
    recentStamps: {
        id: number;
        /** Null for a customer the user may not see. */
        customer: string | null;
        location: string | null;
        source: string;
        sourceLabel: string;
        qty: number;
        at: LocalMoment;
    }[];
    topCustomers: {
        id: number;
        name: string;
        visits: number;
        /** Y-m-d, local. */
        lastVisit: string;
    }[];
    locations: { id: number; name: string }[];
    location: number | null;
};
