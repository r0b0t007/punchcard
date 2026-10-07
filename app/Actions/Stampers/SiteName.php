<?php

declare(strict_types=1);

namespace App\Actions\Stampers;

use App\Models\Location;

/** How stamper messages name a location: "Terrace (#12)", the id being what the commands take. */
final class SiteName
{
    public static function of(Location $location): string
    {
        return "{$location->name} (#{$location->id})";
    }
}
