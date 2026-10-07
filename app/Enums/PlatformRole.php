<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The one role spatie/laravel-permission holds (CHW-22): punchcard's own
 * operators. Business roles live in business_user (owner, staff) and org
 * roles in organization_user (org_admin); a customer is any user, not a role.
 */
enum PlatformRole: string
{
    /** Runs the Filament panel at /admin: verification, tags, kits, moderation. No rights in the app itself. */
    case Admin = 'admin';
}
