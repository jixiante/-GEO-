<?php

namespace App\Events\Admin;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AiExposureOverviewUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array{
     *     metrics:array{active_monitors:int,sample_count:int,mentioned_count:int,cited_count:int,citation_rate:float},
     *     platforms:array<string,array{sample_count:int,mentioned_count:int,cited_count:int,last_checked_at:mixed}>,
     *     monitors:array<int,array{run_count:int,mentioned_count:int,cited_count:int}>
     * }  $overview
     */
    public function __construct(public array $overview) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('admin.ai-exposure');
    }

    public function broadcastAs(): string
    {
        return 'ai-exposure.overview.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->overview;
    }
}
