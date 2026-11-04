<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Resources\Insights;

enum InsightPeriod: string
{
    case Day = 'day';
    case Week = 'week';
    case Days28 = 'days_28';
    case Month = 'month';
    case Lifetime = 'lifetime';
    case TotalOverRange = 'total_over_range';
}
