<?php
namespace Acme\Interactions;

class Comment extends \ElggComment {
	public function canComment(int $user_guid = 0): bool {
		return parent::canComment($user_guid);
	}
}
