<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Resources\Insights;

/**
 * Gängige Page-Metriken. Nicht aufgeführte Metriken können als String übergeben werden.
 */
enum PageMetric: string
{
    case MediaView = 'page_media_view';
    case TotalMediaViewUnique = 'page_total_media_view_unique';
    case ImpressionsUnique = 'page_impressions_unique';
    case PostEngagements = 'page_post_engagements';
    case Follows = 'page_follows';
    case DailyFollows = 'page_daily_follows';
    case DailyFollowsUnique = 'page_daily_follows_unique';
    case DailyUnfollowsUnique = 'page_daily_unfollows_unique';
    case ViewsTotal = 'page_views_total';
    case VideoViews = 'page_video_views';
    case ActionsPostReactionsTotal = 'page_actions_post_reactions_total';
}
