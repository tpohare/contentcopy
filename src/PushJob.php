<?php

declare(strict_types=1);

namespace ContentCopy;

defined( 'ABSPATH' ) || exit;

/**
 * The passable that travels through the push pipeline.
 *
 * In Laravel terms this is the object handed to `Pipeline::send()`: every
 * stage reads what earlier stages discovered and adds its own findings, so
 * no stage needs more than this one argument. The post and the force flag are
 * fixed at creation; everything else is filled in as the push progresses.
 *
 * It is intentionally a plain mutable bag, not a readonly value object. A
 * pipeline passable exists to accumulate state, and fighting that with
 * clone-and-modify ceremony would just hide what is happening.
 */
final class PushJob {

	/**
	 * The post being pushed. Fixed for the lifetime of the job.
	 *
	 * @var \WP_Post
	 */
	public readonly \WP_Post $post;

	/**
	 * Whether an overwrite of a changed destination copy was confirmed.
	 *
	 * @var bool
	 */
	public readonly bool $force;

	/**
	 * This item's cross-site UUID, assigned in the `loadIdentity` stage.
	 *
	 * @var string
	 */
	public string $uuid = '';

	/**
	 * Destination site URL the job is pushing to.
	 *
	 * @var string
	 */
	public string $destination = '';

	/**
	 * Diagnostic payload from the destination's `remote/info` route.
	 *
	 * @var array<string, mixed>
	 */
	public array $info = [];

	/**
	 * Source attachment IDs the post depends on.
	 *
	 * @var list<int>
	 */
	public array $mediaIds = [];

	/**
	 * Synced media keyed by source attachment ID.
	 *
	 * @var array<int, MediaDescriptor>
	 */
	public array $media = [];

	/**
	 * Source post ID to destination post ID, for ACF post-reference fields.
	 *
	 * @var array<int, int>
	 */
	public array $postReferences = [];

	/**
	 * The destination's current record for this UUID (`remote/lookup`).
	 *
	 * Empty when the destination has never seen this item.
	 *
	 * @var array<string, mixed>
	 */
	public array $remote = [];

	/**
	 * The remapper, built once media and references are known.
	 *
	 * @var Remapper|null
	 */
	public ?Remapper $remapper = null;

	/**
	 * The fully remapped payload, ready to send.
	 *
	 * @var PushPayload|null
	 */
	public ?PushPayload $payload = null;

	/**
	 * Start a job for one post.
	 *
	 * @param \WP_Post $post  The source post to push.
	 * @param bool     $force Push even when the destination copy changed since the last push.
	 */
	public function __construct( \WP_Post $post, bool $force = false ) {
		$this->post  = $post;
		$this->force = $force;
	}
}
