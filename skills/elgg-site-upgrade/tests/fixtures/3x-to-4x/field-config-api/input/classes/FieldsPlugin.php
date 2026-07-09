<?php

class FieldsPlugin {

    public function getPageFields(): array {
        $fields = elgg_get_config('pages');
        return $fields ?? [];
    }

    public function getGroupFields(): array {
        $groupFields = elgg_get_config("group");
        return $groupFields ?? [];
    }

    public function getProfileFields(): array {
        return elgg_get_config('profile_fields') ?: [];
    }

    public function getUnrelatedConfig(): string {
        // This should NOT be replaced
        return elgg_get_config('site_name');
    }
}
