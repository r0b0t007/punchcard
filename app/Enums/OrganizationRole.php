<?php

declare(strict_types=1);

namespace App\Enums;

/** A user's role inside one organization (organization_user). */
enum OrganizationRole: string
{
    /** Franchise HQ, or the owner of an independent café: card program, brand, org-wide campaigns. */
    case OrgAdmin = 'org_admin';
}
