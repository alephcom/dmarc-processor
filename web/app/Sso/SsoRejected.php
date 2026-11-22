<?php

namespace App\Sso;

use RuntimeException;

/** Sign-in refused; the message is shown to the user. */
class SsoRejected extends RuntimeException {}
