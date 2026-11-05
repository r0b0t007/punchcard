<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What starts an automation message (CHW-46, B11). When several are due for
 * a customer, the weekly cap keeps the first of them in this order.
 */
enum AutomationTrigger: string
{
    /** A reward unlocked here and not used after the automation's days. */
    case RewardReminder = 'reward_reminder';

    /** The customer's birthday, local, with the automation's bonus stamps. */
    case Birthday = 'birthday';

    /** The customer goes quiet here (BusinessCustomer, CustomerSegment::Quiet), once per quiet spell. */
    case WinBack = 'win_back';
}
