<?php

declare(strict_types=1);

namespace App\Actions\Verification;

use RuntimeException;

/** A verification document can't be sent or decided as asked (CHW-145); the message says why, translated. */
final class DocumentRefused extends RuntimeException {}
