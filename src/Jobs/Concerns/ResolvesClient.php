<?php

declare(strict_types=1);

namespace Kuweh\MetaGraph\Jobs\Concerns;

use Kuweh\MetaGraph\Auth\AccessToken;
use Kuweh\MetaGraph\Graph\GraphClient;
use Kuweh\MetaGraph\GraphManager;

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
