# Complete Lists of Removed/Changed Functions Per Version

This file contains the exhaustive lists extracted from Elgg upgrade notes.
Use this as the definitive reference for building automated migration checks.

---

## 1.x to 2.0: Removed Functions

- `blog_get_page_content_friends`
- `blog_get_page_content_read`
- `count_unread_messages()`
- `delete_entities()`
- `delete_object_entity()`
- `delete_user_entity()`
- `elgg_get_view_location()`
- `elgg_validate_action_url()`
- `execute_delayed_query()`
- `extend_view()`
- `get_db_error()`
- `get_db_link()`
- `get_entities()`
- `get_entities_from_access_id()`
- `get_entities_from_access_collection()`
- `get_entities_from_annotations()`
- `get_entities_from_metadata()`
- `get_entities_from_metadata_multi()`
- `get_entities_from_relationship()`
- `get_filetype_cloud()`
- `get_library_files()`
- `get_views()`
- `is_ip_in_array()`
- `list_entities()`
- `list_entities_from_annotations()`
- `list_group_search()`
- `list_registered_entities()`
- `list_user_search()`
- `load_plugins()`
- `menu_item()`
- `make_register_object()`
- `remove_blacklist()`
- `search_for_group()`
- `search_for_object()`
- `search_for_site()`
- `search_for_user()`
- `search_list_objects_by_name()`
- `search_list_groups_by_name()`
- `search_list_users_by_name()`
- `set_template_handler()`
- `test_ip()`

## 1.x to 2.0: Removed Methods

- `ElggCache::set_variable()`
- `ElggCache::get_variable()`
- `ElggData::initialise_attributes()`
- `ElggData::getObjectOwnerGUID()`
- `ElggDiskFilestore::make_directory_root()`
- `ElggDiskFilestore::make_file_matrix()`
- `ElggDiskFilestore::user_file_matrix()`
- `ElggDiskFilestore::mb_str_split()`
- `ElggEntity::clearMetadata()`
- `ElggEntity::clearRelationships()`
- `ElggEntity::clearAnnotations()`
- `ElggEntity::getOwner()`
- `ElggEntity::setContainer()`
- `ElggEntity::getContainer()`
- `ElggEntity::getIcon()`
- `ElggEntity::setIcon()`
- `ElggExtender::getOwner()`
- `ElggFileCache::create_file()`
- `ElggObject::addToSite()`
- `ElggObject::getSites()`
- `ElggSite::getCollections()`
- `ElggUser::addToSite()`
- `ElggUser::getCollections()`
- `ElggUser::getOwner()`
- `ElggUser::getSites()`
- `ElggUser::listFriends()`
- `ElggUser::listGroups()`
- `ElggUser::removeFromSite()`

## 1.x to 2.0: Removed Classes

- `ElggInspector`
- `Notable`
- `FilePluginFile` -> use `ElggFile`

---

## 2.x to 3.0: Removed Functions

- `elgg_register_library`
- `elgg_load_library`
- `activity_profile_menu`
- `can_write_to_container` -> `ElggEntity->canWriteToContainer()`
- `create_metadata_from_array`
- `metadata_array_to_values`
- `datalist_get`
- `datalist_set`
- `detect_extender_valuetype`
- `developers_setup_menu`
- `elgg_disable_metadata`
- `elgg_enable_metadata`
- `elgg_get_class_loader`
- `elgg_get_metastring_id`
- `elgg_get_metastring_map`
- `elgg_register_class`
- `elgg_register_classes`
- `elgg_register_viewtype`
- `elgg_is_registered_viewtype`
- `file_delete` -> `ElggFile->deleteIcon()`
- `file_get_type_cloud`
- `file_type_cloud_get_url`
- `get_default_filestore`
- `get_site_entity_as_row`
- `get_group_entity_as_row`
- `get_missing_language_keys`
- `get_object_entity_as_row`
- `get_user_entity_as_row`
- `update_river_access_by_object`
- `garbagecollector_orphaned_metastrings`
- `groups_access_collection_override`
- `groups_get_group_tool_options` -> `elgg()->group_tools->all()`
- `groups_join_group` -> `ElggGroup::join`
- `groups_prepare_profile_buttons` -> `register, menu:title` hook
- `groups_register_profile_buttons` -> `register, menu:title` hook
- `groups_setup_sidebar_menus`
- `groups_set_icon_url`
- `messages_notification_msg`
- `set_default_filestore`
- `generate_user_password` -> `ElggUser::setPassword`
- `row_to_elggrelationship`
- `run_function_once` -> `Elgg\Upgrade\Batch`
- `system_messages`
- `notifications_plugin_pagesetup`
- `elgg_format_url`
- `get_site_by_url`
- `elgg_override_permissions`
- `elgg_check_access_overrides`
- `add_subtype` -> `elgg_set_entity_class`
- `update_subtype` -> `elgg_set_entity_class`
- `remove_subtype`
- `get_subtype_id`
- `get_subtype_from_id`
- `get_subtype_class` -> `elgg_get_entity_class`
- `get_subtype_class_from_id`
- `is_memcache_available`
- `_elgg_get_memcache`
- `_elgg_invalidate_memcache_for_entity`
- `search_get_where_sql`
- `search_get_ft_min_max`
- `search_get_order_by_sql`
- `search_consolidate_substrings`
- `search_remove_ignored_words`
- `search_get_highlighted_relevant_substrings`
- `search_highlight_words`
- `search_get_search_view`
- All `search_*_hook` functions
- All `members_list_*` and `members_nav_*` functions
- `uservalidationbyemail_generate_code`
- `profile_pagesetup`
- `pages_can_delete_page` -> `$entity->canDelete()`
- `pages_search_pages`
- `pages_is_page` -> `$entity instanceof ElggPage`
- All `discussion_*` reply functions

## 2.x to 3.0: Removed Methods

- `Application::loadSettings`
- `ElggEntity::addToSite`
- `ElggEntity::disableMetadata`
- `ElggEntity::enableMetadata`
- `ElggEntity::getSites`
- `ElggEntity::removeFromSite`
- `ElggEntity::isFullyLoaded`
- `ElggEntity::clearAllFiles`
- `ElggPlugin::getFriendlyName` -> `getDisplayName()`
- `ElggPlugin::setID`
- `ElggPlugin::unsetAllUsersSettings`
- `ElggFile::setFilestore`
- `ElggFile::size` -> `getSize`
- `ElggDiskFilestore::makeFileMatrix`
- `ElggData::get`
- `ElggData::getClassName` -> `get_class()`
- `ElggData::set`
- `ElggEntity::setURL`
- `ElggMenuBuilder::compareByWeight` -> `compareByPriority`
- `ElggMenuItem::getWeight` -> `getPriority`
- `ElggMenuItem::getContent` -> `elgg_view_menu_item()`
- `ElggMenuItem::setWeight` -> `setPriority`
- `ElggRiverItem::getPostedTime` -> `getTimePosted`
- `ElggSite::addEntity/Object/User`
- `ElggSite::getEntities/Members/Objects` -> `elgg_get_entities()`
- `ElggSite::getExportableValues` -> `toObject`
- `ElggSite::listMembers` -> `elgg_list_entities()`
- `ElggSite::removeEntity/Object/User`
- `ElggSite::isPublicPage`
- `ElggSite::checkWalledGarden`
- `ElggUser::countObjects` -> `elgg_get_entities()`
- `Logger::getClassName` -> `get_class()`
- `Elgg\Application\Database::getTablePrefix`
- `elgg_view_access_collections()`

## 2.x to 3.0: Removed Classes

- `FilePluginFile` -> `ElggFile`
- `Elgg_Notifications_Notification`
- `Elgg\Database\MetastringsTable`
- `Elgg\Database\SubtypeTable`
- `Exportable`, `ExportException`, `Importable`, `ImportException`
- `ODD` and all `ODD*` classes
- `XmlElement`
- `ElggMemcache`, `ElggFileCache`, `ElggStaticVariableCache`, `ElggSharedMemoryCache`
- `Elgg\Cache\Pool` and all extending classes
- `ElggDiscussionReply` -> `ElggComment`

## 2.x to 3.0: Deprecated Functions (removed in 4.0+)

- `ban_user` -> `ElggUser->ban()`
- `create_metadata` -> `ElggEntity->setMetadata()`
- `create_annotation` -> `ElggEntity->annotate()`
- `elgg_get_user_validation_status` -> `ElggUser->isValidated()`
- `make_user_admin` -> `ElggUser->makeAdmin()`
- `remove_user_admin` -> `ElggUser->removeAdmin()`
- `unban_user` -> `ElggUser->unban()`
- `elgg_get_entities_from_metadata` -> `elgg_get_entities()`
- `elgg_get_entities_from_relationship` -> `elgg_get_entities()`
- `elgg_get_entities_from_private_settings` -> `elgg_get_entities()`
- `elgg_get_entities_from_access_id` -> `elgg_get_entities()`
- `elgg_list_entities_from_metadata` -> `elgg_list_entities()`
- `elgg_list_entities_from_relationship` -> `elgg_list_entities()`
- `elgg_list_entities_from_private_settings` -> `elgg_list_entities()`
- `elgg_list_entities_from_access_id` -> `elgg_list_entities()`
- `elgg_list_registered_entities` -> `elgg_list_entities()`
- `elgg_group_gatekeeper` -> `elgg_entity_gatekeeper()`
- `get_entity_dates` -> `elgg_get_entity_dates()`

---

## 3.x to 4.0: Removed Functions

- All `_elgg_*` procedural callback functions (replaced by class-based handlers)
- `elgg_get_filter_tabs()`
- `elgg_register_tag_metadata_name()`
- `elgg_get_registered_tag_metadata_names()`
- `elgg_unregister_tag_metadata_name()`
- `create_api_user()`, `get_api_user()`, `remove_api_user()`
- `create_user_token()`, `get_user_tokens()`, `validate_user_token()`
- `remove_user_token()`, `remove_expired_user_tokens()`
- `send_api_call()`, `send_api_get_call()`, `send_api_post_call()`
- `service_handler()`, `ws_page_handler()`, `ws_rest_handler()`
- `pam_auth_session()`
- `get_standard_api_key_array()`

## 3.x to 4.0: Removed Hooks/Events

- `search:format, entity`
- `filter_tabs, <context>` -> `register, menu:filter:filter`

---

## 4.x to 5.0: Removed Functions

- `blog_prepare_form_vars`
- `bookmarks_prepare_form_vars`
- `discussion_prepare_form_vars`
- `file_prepare_form_vars`
- `groups_prepare_form_vars`
- `messages_prepare_form_vars`
- `pages_prepare_form_vars`
- `thewire_latest_guid`
- `elgg_get_breadcrumbs`
- `elgg_pop_breadcrumb`
- `elgg_set_email_transport` -> `_elgg_services()->set('mailer', ...)`
- `elgg_trigger_deprecated_plugin_hook`
- `elgg_ws_expose_function` -> `elgg-plugin.php` or `register, api_methods` event
- `get_user_by_email` -> `elgg_get_user_by_email`
- `get_user_by_username` -> `elgg_get_user_by_username`
- `ElggWidget::saveSettings()`

## 4.x to 5.0: Deprecated APIs (removed in 6.0+)

- `elgg_clear_plugin_hook_handlers` -> `elgg_clear_event_handlers`
- `elgg_register_plugin_hook_handler` -> `elgg_register_event_handler`
- `elgg_trigger_plugin_hook` -> `elgg_trigger_event_results`
- `elgg_unregister_plugin_hook_handler` -> `elgg_unregister_event_handler`

## 4.x to 5.0: Removed Events

- `access:collections:addcollection, collection` -> `create, access_collection`
- `access:collections:deletecollection, collection` -> `delete, access_collection`
- `prepare, breadcrumbs` -> `register, menu:breadcrumbs`
- `widget_settings, <widget_handler>`

## 4.x to 5.0: Removed Exceptions

- `\Elgg\Exceptions\InvalidParameterException`

## 4.x to 5.0: Moved Classes

- `ElggAutoP` -> `Elgg\Views\AutoParagraph`
- `ElggCache` -> `Elgg\Cache\BaseCache`
- `ElggDiskFilestore` -> `Elgg\Filesystem\Filestore\DiskFilestore`
- `ElggFilestore` -> `Elgg\Filesystem\Filestore`
- `ElggRewriteTester` -> `Elgg\Router\RewriteTester`
- `ElggTempDiskFilestore` -> `Elgg\Filesystem\Filestore\TempDiskFilestore`
- `Elgg\Database\SiteSecret` -> `Elgg\Security\SiteSecret`

## 4.x to 5.0: Removed JS Functions

- `elgg.is_in_object_array`
- `elgg.is_instant_hook`
- `elgg.is_triggered_hook`
- `elgg.push_to_object_array`
- `elgg.register_hook_handler` -> `elgg/hooks` module `register`
- `elgg.register_instant_hook`
- `elgg.set_triggered_hook`
- `elgg.trigger_hook` -> `elgg/hooks` module `trigger`

---

## 5.x to 6.0: Removed Functions

- `elgg_define_js()` -> `elgg_register_esm()`
- `elgg_require_js()` -> `elgg_import_esm()`
- `elgg_unrequire_js()`
- `elgg_disable_annotations()`
- `elgg_enable_annotations()`
- `elgg_set_view_location()`
- `elgg_strrchr()`
- `elgg_strripos()`
- `elgg_unrequire_css()` -> `elgg_unregister_external_file('css', $view)`

## 5.x to 6.0: Removed Class Functions

- `ElggAnnotation->enable()`
- `ElggAnnotation->disable()`
- `ElggEntity->disableAnnotations()`
- `ElggEntity->enableAnnotations()`
- `ElggEntity->getTags()` -> `elgg_get_metadata()`

## 5.x to 6.0: Removed Events

- `config, amd`
- `elgg.data, site` -> `elgg.data, page`

## 5.x to 6.0: Removed Interfaces

- `\Elgg\EntityIcon` -> `\Elgg\Traits\Entity\Icons`

## 5.x to 6.0: Removed Metadata

- `x1`, `x2`, `y1`, `y2` (icon cropping) -> `ElggEntity::getIconCoordinates()`
- `icontime` -> `ElggEntity::hasIcon()`

---

## 6.x to 7.0: Removed Classes

- `\Elgg\Email\Address` -> `\Symfony\Component\Mime\Address`
- `\Elgg\Email\HtmlPart` -> removed
- `\Elgg\Email\PlainTextPart` -> removed

## 6.x to 7.0: Renamed Notification Classes

- `Elgg\Notifications\CreateCommentEventHandler` -> `Elgg\Notifications\Handlers\CreateComment`
- `Elgg\Notifications\MakeAdminUserEventHandler` -> `Elgg\Notifications\Handlers\MakeAdminUser`
- `Elgg\Notifications\MentionsEventHandler` -> `Elgg\Notifications\Handlers\Mentions`
- `Elgg\Notifications\RemoveAdminUserEventHandler` -> `Elgg\Notifications\Handlers\RemoveAdminUser`
- `Elgg\Notifications\UnbanUserEventHandler` -> `Elgg\Notifications\Handlers\UnbanUser`
- `Elgg\Notifications\CreateContentEventHandler` -> `Elgg\Notifications\Events\CreateContent`
- `Elgg\Notifications\EnqueueEventHandler` -> `Elgg\Notifications\Events\Enqueue`
- `Elgg\Notifications\MentionsEnqueueEventHandler` -> `Elgg\Notifications\Events\MentionsEnqueue`

## 6.x to 7.0: Removed Events

- `vars:compiler, css` (CSS Crush event)
- `zend:message, system:email` -> `message, system:email`
- `ajax_response` -> `ajax_results`
- Legacy `forward` event

## 6.x to 7.0: Removed Config Options

- `css_compiler_options`
- `emailer_sendmail_settings`
- `emailer_smtp_settings`
- `memcache`
- `memcache_namespace_prefix`
- `memcache_servers`
- `redis`
- `redis_options`
- `redis_servers`

## 6.x to 7.0: Removed CSS Classes

- `elgg-button-special`
- `elgg-button-action-done`

## 6.x to 7.0: Renamed Actions/Forms

- `forms/blog/save` -> `forms/blog/edit`
- `forms/bookmarks/save` -> `forms/bookmarks/edit`
- `forms/discussion/save` -> `forms/discussion/edit`
- `forms/file/upload` -> `forms/file/edit`
- `action/blog/save` -> `action/blog/edit`
- `action/bookmarks/save` -> `action/bookmarks/edit`
- `action/discussion/save` -> `action/discussion/edit`
- `action/file/upload` -> `action/file/edit`
- `admin/site/flush_cache` -> `admin/site/cache/clear`

## 6.x to 7.0: Renamed Routes

- `collection:user:user` -> `collection:user:user:all`
- `search:user:user` -> `collection:user:user:search`

## 6.x to 7.0: Changed Function Signatures

- `elgg_register_notification_event()` changed `array $actions` to `string $action`
- `elgg_unregister_notification_event()` now requires `$handler` parameter
- `elgg_register_external_file()` now returns void
- `elgg_register_pam_handler()` now returns void
- `elgg_register_route()` now returns void
- `elgg_unregister_external_file()` now returns void
- `elgg_unregister_menu_item()` now returns void
