<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Jobs\Concerns;

use KaiHempel\MetaGraph\Auth\AccessToken;
use KaiHempel\MetaGraph\Graph\GraphClient;
use KaiHempel\MetaGraph\GraphManager;

/**
 * @property-read string|null $app
 * @property-read string $pageId
 * @property-read AccessToken|null $pageToken
 */
trait ResolvesClient
{
    protected function manager(GraphManager $manager): GraphManager
    {
        return $this->app === null ? $manager : $manager->app($this->app);
    }

    /**
     * Page-Client: übergebener Page Token oder gespeicherter Token aus dem TokenRepository.
     */
    protected function pageClient(GraphManager $manager): GraphClient
    {
        $manager = $this->manager($manager);

        return $this->pageToken === null
            ? $manager->asStoredPage($this->pageId)
            : $manager->client($this->pageToken, $this->pageId);
    }
}
