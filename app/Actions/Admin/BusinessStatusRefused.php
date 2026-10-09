<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use RuntimeException;

/** A business's status can't change as asked; the message says why, translated. */
final class BusinessStatusRefused extends RuntimeException {}
