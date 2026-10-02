<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use RuntimeException;

/** The organization or business a queued job was dispatched for no longer exists. */
final class TenantUnavailable extends RuntimeException {}
