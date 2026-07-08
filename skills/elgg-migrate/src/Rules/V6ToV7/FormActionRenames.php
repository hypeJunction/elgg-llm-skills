<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V6ToV7;

use ElggMigrate\Rules\Shared\DataDrivenStringLiteralRenames;

/**
 * Rewrites core form/view/action path string literals renamed in Elgg 7.0
 * (rules 007 + 011): the blog/bookmarks/discussion "save" and file "upload"
 * form + action paths become "edit", and admin/site/flush_cache becomes
 * admin/site/cache/clear. Matches whole string-literal values only (via the AST
 * base), so a substring like 'myplugin/blog/save' or a comment is never touched.
 *
 * View OVERRIDE files (a views/default/forms/blog/save.php on disk) are file
 * renames, not string literals, and stay LLM-guided in manifest rule 007.
 */
final class FormActionRenames extends DataDrivenStringLiteralRenames
{
    protected function targetMajor(): string
    {
        return '7.x';
    }

    public function getId(): string
    {
        return 'form-action-renames-7x';
    }
}
