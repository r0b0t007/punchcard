<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use RuntimeException;

/** The admin can't sign in as that owner (CHW-144); the message says why, translated. */
final class ImpersonationRefused extends RuntimeException {}
