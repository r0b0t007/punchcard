<?php

declare(strict_types=1);

namespace App\Support\Verification;

use RuntimeException;

/** DOCUMENTS_KEY isn't set, or isn't "base64:" and 32 bytes (CHW-145): a setup error, never the user's. */
final class DocumentKeyMissing extends RuntimeException {}
