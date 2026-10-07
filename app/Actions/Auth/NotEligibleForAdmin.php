<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use RuntimeException;

/** The account can't be a platform admin yet; the message says what to do first (SetPlatformAdmin). */
final class NotEligibleForAdmin extends RuntimeException {}
