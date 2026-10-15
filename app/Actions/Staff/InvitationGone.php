<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use RuntimeException;

/** The invitation stopped working while someone joined with it (JoinStaff rolls the join back). */
final class InvitationGone extends RuntimeException {}
