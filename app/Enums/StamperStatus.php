<?php

declare(strict_types=1);

namespace App\Enums;

/** A disabled stamper (lost, stolen, replaced) rejects every tap. */
enum StamperStatus: string
{
    case Active = 'active';

    case Disabled = 'disabled';
}
