<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What kind of business it is (CHW-31): picked in the onboarding wizard, it
 * suggests the first card's reward. Stored on businesses.category; Postgres
 * refuses any other value.
 */
enum BusinessCategory: string implements HasLabel
{
    case Cafe = 'cafe';
    case Restaurant = 'restaurant';
    case Bakery = 'bakery';
    case Barber = 'barber';
    case Salon = 'salon';
    case Beauty = 'beauty';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cafe => __('Café'),
            self::Restaurant => __('Restaurant'),
            self::Bakery => __('Bakery'),
            self::Barber => __('Barber'),
            self::Salon => __('Hair salon'),
            self::Beauty => __('Beauty and spa'),
            self::Other => __('Other'),
        };
    }

    /** The first card's suggested reward, in the owner's language. */
    public function defaultReward(): string
    {
        return match ($this) {
            self::Cafe => __('Free coffee'),
            self::Restaurant => __('Free dessert'),
            self::Bakery => __('Free pastry'),
            self::Barber => __('Free haircut'),
            self::Salon, self::Beauty => __('Free treatment'),
            self::Other => __('Free gift'),
        };
    }
}
