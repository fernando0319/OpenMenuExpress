<?php

namespace App\Payments\Nwc;

use RuntimeException;

/**
 * A relay could not be reached or did not bring the answer in time: worth trying the next one.
 * Anything the wallet itself said (an answer or a refusal) is final instead.
 */
final class RelayUnavailable extends RuntimeException {}
