-- Finish the job \Elgg\Discussions\Upgrades\MigrateDiscussionReply and
-- MigrateDiscussionReplyRiver were supposed to do.
--
-- Elgg 3 replaced the `discussion_reply` object subtype with plain comments. Those
-- two upgrades perform the conversion — but their CLASSES were deleted from Elgg
-- upstream by 7.0, and UpgradeService::getPendingUpgrades() silently drops an
-- upgrade whose batch cannot be instantiated. So the ElggUpgrade entities sit
-- "pending" forever and the conversion never happens.
--
-- The damage on bodyology: 17 replies stranded on an unregistered subtype (they
-- load as ElggUndefinedObject and never render), and ZERO comments on any
-- discussion. Every discussion thread had lost its replies while 2.x showed them.
--
-- A discussion reply and a comment are the same shape: an object contained by the
-- discussion. Only the subtype and the river view/action differ.
--
-- Idempotent: once no discussion_reply rows remain, every statement is a no-op.

UPDATE elgg_entities SET subtype = 'comment' WHERE subtype = 'discussion_reply';

-- The river entry that announced the reply. `reply` is not a river action_type in
-- Elgg 7; comments announce themselves as `comment`.
UPDATE elgg_river
SET view = 'river/object/comment/create',
    action_type = 'comment'
WHERE view = 'river/object/discussion_reply/create';

-- \Elgg\Likes\Upgrades\PublicLikesAnnotations, likewise deleted from Elgg 7 before
-- it ever ran. Elgg 3 made `likes` annotations publicly readable so that like
-- COUNTS are visible to everyone, not just to users inside the liker's access
-- collection. 80 of bodyology's 780 likes were still restricted.
UPDATE elgg_annotations SET access_id = 2 WHERE name = 'likes' AND access_id <> 2;
