<?php

namespace App\Dto\Dashboard;

use App\Dto\FeedItem;
use App\Services\DashboardAssembler;
use Illuminate\Support\Collection;

/**
 * The blended community feed for an established Dashboard: friends' activity
 * plus trending nearby games (shown when the friends list is short).
 *
 * Built by the Dashboard assembler from the `feed` and `trending` Dashboard sections.
 *
 * @see DashboardAssembler
 */
final readonly class CommunityFeed
{
    /**
     * @param  Collection<int, FeedItem>  $friends  Max 10 items from the social circle.
     * @param  Collection<int, FeedItem>  $trending  Max 5 trending items; non-empty only when $showTrending.
     */
    public function __construct(
        public Collection $friends,
        public Collection $trending,
        public bool $showTrending,
    ) {}
}
