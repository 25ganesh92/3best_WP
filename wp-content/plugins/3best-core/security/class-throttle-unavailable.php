<?php
/**
 * Thrown when a throttle counter cannot be read or written.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Security;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Signals that the rate limiter itself is broken.
 *
 * It exists so a store failure is distinguishable from "this caller is over
 * the limit". Those two need opposite handling, and a store that returned 0 on
 * failure would quietly report every caller as being well within budget — the
 * exact failure mode that turns a storage hiccup into an open SMS endpoint.
 *
 * Throttle catches this and applies the bucket's declared failure mode.
 */
final class Throttle_Unavailable extends RuntimeException {
}
