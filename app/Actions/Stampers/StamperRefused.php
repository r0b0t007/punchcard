<?php

declare(strict_types=1);

namespace App\Actions\Stampers;

use RuntimeException;

/** A tag can't be registered, moved or changed as asked; the message says why, and never carries key material. */
final class StamperRefused extends RuntimeException {}
