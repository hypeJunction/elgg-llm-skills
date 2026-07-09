<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V6ToV7;

use ElggMigrate\Rules\Shared\DataDrivenClassRenames;

/**
 * Rewrites the notification handler / event-handler classes that moved into the
 * Elgg\Notifications\Handlers and Elgg\Notifications\Events sub-namespaces in
 * Elgg 7.0 (e.g. Elgg\Notifications\CreateCommentEventHandler →
 * Elgg\Notifications\Handlers\CreateComment). The rename set lives in
 * references/class-renames.json['7.x'].
 *
 * The accompanying API/signature changes (elgg_register_notification_event()
 * array $actions → string $action, elgg_unregister_notification_event() now
 * requiring $handler) are NOT mechanical and stay LLM-guided in manifest rule
 * 006-notification-handler-renames.
 */
final class NotificationHandlerRenames extends DataDrivenClassRenames
{
    protected function targetMajor(): string
    {
        return '7.x';
    }

    public function getId(): string
    {
        return 'notification-handler-renames-7x';
    }
}
