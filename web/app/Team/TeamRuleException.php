<?php

namespace App\Team;

use RuntimeException;

/** A team change that isn't allowed; the message is safe to show to the user. */
class TeamRuleException extends RuntimeException {}
