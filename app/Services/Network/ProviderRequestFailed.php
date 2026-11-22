<?php

namespace App\Services\Network;

use RuntimeException;

/** A provider call failed; the message is safe to show to IT staff. */
class ProviderRequestFailed extends RuntimeException {}
