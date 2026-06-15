<?php
namespace Foo;

class Bar extends \ElggObject {
	public function canComment(int $user_guid = 0): bool {
		return parent::canComment($user_guid);
	}
}
