<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Webhooks;

use KaiHempel\MetaGraph\Data\WebhookChange;
use KaiHempel\MetaGraph\Support\Values;

/**
 * Zerlegt eine Zustellung { object, entry[] } in WebhookChange-DTOs (pro entry und changes[]).
 */
final class WebhookPayload
{
    /**
     * @param  array<array-key, mixed>  $payload
     * @return list<WebhookChange>
     */
    public static function parse(array $payload): array
    {
        $objectType = Values::string($payload, 'object', 'unknown') ?? 'unknown';
        $changes = [];

        foreach (Values::array($payload, 'entry') as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $objectId = Values::string($entry, 'id', '') ?? '';
            $time = Values::date($entry, 'time');

            foreach (Values::array($entry, 'changes') as $change) {
                if (! is_array($change)) {
                    continue;
                }

                $value = $change['value'] ?? [];

                $changes[] = new WebhookChange(
                    objectType: $objectType,
                    objectId: $objectId,
                    field: Values::string($change, 'field', '') ?? '',
                    value: is_array($value) ? $value : ['value' => $value],
                    time: $time,
                );
            }
        }

        return $changes;
    }
}
