<?php

namespace App\Services\Requests;

use RuntimeException;

/** Thrown when an action is not valid for a request's current status. */
class InvalidRequestTransition extends RuntimeException {}
