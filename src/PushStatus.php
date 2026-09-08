<?php

declare(strict_types=1);

namespace ContentCopy;

defined( 'ABSPATH' ) || exit;

/**
 * How a push resolved on the destination.
 *
 * The destination reports one of these per push, and the source records it
 * alongside the last-push fingerprint. The editor shows the human label, so
 * the person pushing always knows whether they created something new or
 * updated something that was already there.
 */
enum PushStatus: string {

	/**
	 * No item with this UUID existed; one was created.
	 */
	case Created = 'created';

	/**
	 * An item with this UUID already existed; it was updated in place.
	 */
	case Updated = 'updated';

	/**
	 * Human-readable outcome for the editor panel and the logs.
	 *
	 * @return string Label describing what happened.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Created => __( 'Created on the destination site.', 'contentcopy' ),
			self::Updated => __( 'Updated the existing copy on the destination site.', 'contentcopy' ),
		};
	}
}
