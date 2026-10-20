<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

// Moderators can look at a member's journey (and help them along) from the
// member's profile. Changing the journey itself stays with admins.
return Migration::addPermissions([
    'onramp.viewMembers' => Group::MODERATOR_ID,
]);
