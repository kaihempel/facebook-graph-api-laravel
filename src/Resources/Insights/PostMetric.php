<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Resources\Insights;

/**
 * Gängige Post-Metriken. Nicht aufgeführte Metriken können als String übergeben werden.
 */
enum PostMetric: string
{
    case MediaView = 'post_media_view';
    case TotalMediaViewUnique = 'post_total_media_view_unique';
    case ImpressionsUnique = 'post_impressions_unique';
    case Clicks = 'post_clicks';
    case ReactionsByTypeTotal = 'post_reactions_by_type_total';
    case VideoViews = 'post_video_views';
    case VideoAvgTimeWatched = 'post_video_avg_time_watched';
}
