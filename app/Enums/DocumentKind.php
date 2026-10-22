<?php

declare(strict_types=1);

namespace App\Enums;

/** What a business verification document proves (CHW-145). */
enum DocumentKind: string
{
    /** The owner's ID card or passport. */
    case IdDocument = 'id_document';

    /** A bill or statement from the last 3 months, with the business's address. */
    case ProofOfAddress = 'proof_of_address';

    public function label(): string
    {
        return match ($this) {
            self::IdDocument => __('ID card or passport'),
            self::ProofOfAddress => __('Proof of address'),
        };
    }
}
