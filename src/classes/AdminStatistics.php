<?php

declare(strict_types=1);

class AdminStatistics
{
    public function toJSON(): array
    {
        return array_merge((new CrawlStatistics()) -> toJSON(), (new ServerHealth()) -> toJSON());
    }
}
