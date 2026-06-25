<?php
namespace Acme\Interactions;

class Comment extends \ElggComment {
	public function canComment($user_guid = 0, $default = null) {
		return parent::canComment($user_guid);
	}
}
