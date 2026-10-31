import { Link, router, usePage } from '@inertiajs/react';
import { LogOut, Settings } from 'lucide-react';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { useTranslation } from '@/hooks/use-translation';
import { forgetOfflineCards } from '@/lib/offline-cards';
import { forgetThisPhone } from '@/lib/push';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import type { User } from '@/types';

type Props = {
    user: User;
};

export function UserMenuContent({ user }: Props) {
    const cleanup = useMobileNavigation();
    const { t } = useTranslation();
    // Signed in as an owner (CHW-144), logging out goes back to admin instead.
    const { impersonating } = usePage().props;

    const handleLogout = () => {
        cleanup();
        router.flushAll();
        // Nothing of this customer's stays on the phone, not even in Back's history (CHW-35).
        router.clearHistory();
        void forgetOfflineCards();
        // Nor its notifications: the next account on this phone never gets them (CHW-41).
        void forgetThisPhone();
    };

    return (
        <>
            <div className="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                <UserInfo user={user} showEmail={true} />
            </div>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                <DropdownMenuItem asChild>
                    <Link
                        className="block w-full cursor-pointer"
                        href={edit()}
                        prefetch
                        onClick={cleanup}
                    >
                        <Settings className="me-2" />
                        {t('Settings')}
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuItem asChild>
                <Link
                    className="block w-full cursor-pointer"
                    href={logout()}
                    as="button"
                    onClick={handleLogout}
                    data-test="logout-button"
                >
                    <LogOut className="me-2" />
                    {impersonating ? t('Back to admin') : t('Log out')}
                </Link>
            </DropdownMenuItem>
        </>
    );
}
