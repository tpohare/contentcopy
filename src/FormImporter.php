<?php

declare(strict_types=1);

namespace ContentCopy;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Destination-side form handling: turns exported definitions into real forms.
 *
 * Forms arrive before the post that embeds them, for the same reason media
 * does: the source needs to know what ID each form ended up with here before
 * it can rewrite the shortcodes and blocks in the content. The reply is a map
 * from source form ID to destination form ID, and nothing else in the push
 * depends on how that map was produced.
 *
 * Each form is matched by UUID and updated in place, or created when this
 * site has never seen it. Updating in place is what keeps repeat pushes
 * stable: the destination form keeps its ID, so every page already embedding
 * it — including pages that were never pushed from the source — keeps
 * working, and the entries already collected against it stay attached to the
 * form that collected them.
 *
 * Entries themselves never travel. They are submitted data belonging to the
 * site that collected them, and a content copy has no business moving them.
 */
final class FormImporter {

	/**
	 * @var LoggerInterface Creates, updates and feed writes are all logged here.
	 */
	private LoggerInterface $logger;

	/**
	 * @param LoggerInterface|null $logger Where to narrate; silent when omitted.
	 */
	public function __construct( ?LoggerInterface $logger = null ) {
		$this->logger = $logger ?? new NullLogger();
	}

	/**
	 * Import a batch of exported forms and report where each one landed.
	 *
	 * @param mixed $forms Decoded `forms` list from the `remote/forms` payload.
	 *
	 * @return array{map: array<int, int>, warnings: list<string>}|WP_Error Source ID => destination ID, or why the batch was refused.
	 */
	public function import( mixed $forms ): array|WP_Error {
		if ( ! GravityFormsBridge::active() ) {
			return new WP_Error(
				'contentcopy_no_gravity_forms',
				__( 'This item embeds a Gravity Forms form, but Gravity Forms is not active on the destination site. Install and activate it there, then push again.', 'contentcopy' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! is_array( $forms ) ) {
			return new WP_Error( 'contentcopy_bad_payload', __( 'A list of forms was expected.', 'contentcopy' ), [ 'status' => 400 ] );
		}

		$map      = [];
		$warnings = [];

		foreach ( $forms as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$result = $this->importOne( $entry, $warnings );

			if ( $result instanceof WP_Error ) {
				return $result;
			}

			$sourceId = isset( $entry['source_id'] ) ? (int) $entry['source_id'] : 0;

			if ( $sourceId > 0 ) {
				$map[ $sourceId ] = $result;
			}
		}

		return [
			'map'      => $map,
			'warnings' => array_values( array_unique( $warnings ) ),
		];
	}

	/**
	 * Create or update one form, and return the ID it now has here.
	 *
	 * @param array<array-key, mixed> $entry    One exported form (`uuid`, `form`, `feeds`…).
	 * @param list<string>            $warnings Warning sink.
	 *
	 * @return int|WP_Error Destination form ID, or why it could not be written.
	 */
	private function importOne( array $entry, array &$warnings ): int|WP_Error {
		$uuid = is_string( $entry['uuid'] ?? null ) ? $entry['uuid'] : '';

		if ( ! Uuid::isValid( $uuid ) ) {
			return new WP_Error( 'contentcopy_bad_payload', __( 'A form arrived without a valid identity.', 'contentcopy' ), [ 'status' => 400 ] );
		}

		$definition = is_array( $entry['form'] ?? null ) ? $entry['form'] : null;

		if ( null === $definition ) {
			return new WP_Error( 'contentcopy_bad_payload', __( 'A form arrived without a definition.', 'contentcopy' ), [ 'status' => 400 ] );
		}

		$title = is_string( $entry['title'] ?? null ) && '' !== $entry['title']
			? $entry['title']
			: ( is_string( $definition['title'] ?? null ) ? $definition['title'] : __( 'Untitled form', 'contentcopy' ) );

		$existing = GravityFormsBridge::findLocal( $uuid );

		// Notifications and confirmations live in their own columns rather
		// than inside the definition, and are written explicitly below so
		// they land the same way on a create and on an update.
		$notifications = is_array( $definition['notifications'] ?? null ) ? $definition['notifications'] : null;
		$confirmations = is_array( $definition['confirmations'] ?? null ) ? $definition['confirmations'] : null;

		if ( $existing > 0 ) {
			$definition['id'] = $existing;

			$updated = \GFAPI::update_form( $definition, $existing );

			if ( $updated instanceof WP_Error ) {
				$this->logger->error(
					'Updating form {id} ("{title}") failed: {reason}',
					[ 'id' => $existing, 'title' => $title, 'reason' => $updated->get_error_message() ]
				);

				return $updated;
			}

			$formId = $existing;

			$this->logger->info( 'Updated form {id} ("{title}").', [ 'id' => $formId, 'title' => $title ] );
		} else {
			unset( $definition['id'] );

			$created = \GFAPI::add_form( $definition );

			if ( $created instanceof WP_Error ) {
				$this->logger->error(
					'Creating form "{title}" failed: {reason}',
					[ 'title' => $title, 'reason' => $created->get_error_message() ]
				);

				return $created;
			}

			$formId = (int) $created;

			if ( $formId <= 0 ) {
				return new WP_Error( 'contentcopy_form_failed', __( 'The destination could not create the form.', 'contentcopy' ), [ 'status' => 500 ] );
			}

			GravityFormsBridge::remember( $formId, $uuid );

			$this->logger->info( 'Created form {id} ("{title}").', [ 'id' => $formId, 'title' => $title ] );

			$warnings[] = sprintf(
				/* translators: 1: form title, 2: form ID. */
				__( 'The form "%1$s" was created on the destination as form %2$d. Check anything that depends on its ID there, such as hard-coded shortcodes in theme files.', 'contentcopy' ),
				$title,
				$formId
			);
		}

		$this->applyMeta( $formId, 'notifications', $notifications );
		$this->applyMeta( $formId, 'confirmations', $confirmations );
		$this->applyActive( $formId, $entry );
		$this->applyFeeds( $formId, $title, $entry['feeds'] ?? null, $warnings );

		return $formId;
	}

	/**
	 * Write notifications or confirmations into their own storage.
	 *
	 * Gravity Forms keeps both outside the form definition, and whether a
	 * given version's `update_form()` carries them along has changed over
	 * time. Writing them explicitly is idempotent and removes the question.
	 *
	 * @param int                       $formId Destination form ID.
	 * @param string                    $column Either `notifications` or `confirmations`.
	 * @param array<array-key, mixed>|null $value  Incoming value, or null when the source sent none.
	 *
	 * @return void
	 */
	private function applyMeta( int $formId, string $column, ?array $value ): void {
		if ( null === $value || ! class_exists( 'GFFormsModel' ) ) {
			return;
		}

		\GFFormsModel::update_form_meta( $formId, $value, $column );
	}

	/**
	 * Mirror whether the form accepts entries.
	 *
	 * A form deactivated on the source arriving active on the destination
	 * would start collecting entries nobody asked for.
	 *
	 * @param int                     $formId Destination form ID.
	 * @param array<array-key, mixed> $entry  One exported form.
	 *
	 * @return void
	 */
	private function applyActive( int $formId, array $entry ): void {
		if ( ! isset( $entry['is_active'] ) || ! class_exists( 'GFFormsModel' ) ) {
			return;
		}

		\GFFormsModel::update_form_active( $formId, (bool) $entry['is_active'] );
	}

	/**
	 * Replace the form's add-on feeds with the ones that travelled.
	 *
	 * Replace rather than merge, for the same reason custom fields are wiped
	 * and replayed: a feed deleted on the source must not survive here. The
	 * replacement is scoped to the add-ons the source actually sent, so a
	 * feed belonging to an add-on that only exists on the destination is left
	 * alone.
	 *
	 * Every write is warned about. Feed settings routinely name mailing
	 * lists, payment accounts and webhook endpoints that belong to the
	 * source's connected services — copying the settings cannot copy the
	 * account behind them, and a feed that looks fine may be pointing at
	 * something live.
	 *
	 * @param int            $formId   Destination form ID.
	 * @param string         $title    Form title, for warnings.
	 * @param mixed          $feeds    Incoming feed list.
	 * @param list<string>   $warnings Warning sink.
	 *
	 * @return void
	 */
	private function applyFeeds( int $formId, string $title, mixed $feeds, array &$warnings ): void {
		if ( ! is_array( $feeds ) || [] === $feeds ) {
			return;
		}

		$slugs = [];

		foreach ( $feeds as $feed ) {
			if ( is_array( $feed ) && is_string( $feed['addon_slug'] ?? null ) && '' !== $feed['addon_slug'] ) {
				$slugs[] = $feed['addon_slug'];
			}
		}

		$slugs = array_values( array_unique( $slugs ) );

		foreach ( $slugs as $slug ) {
			$existing = \GFAPI::get_feeds( null, $formId, $slug );

			if ( ! is_array( $existing ) ) {
				continue;
			}

			foreach ( $existing as $feed ) {
				if ( is_array( $feed ) && isset( $feed['id'] ) ) {
					\GFAPI::delete_feed( (int) $feed['id'] );
				}
			}
		}

		$written = 0;

		foreach ( $feeds as $feed ) {
			if ( ! is_array( $feed ) || ! is_string( $feed['addon_slug'] ?? null ) || '' === $feed['addon_slug'] ) {
				continue;
			}

			$created = \GFAPI::add_feed(
				$formId,
				is_array( $feed['meta'] ?? null ) ? $feed['meta'] : [],
				$feed['addon_slug']
			);

			if ( $created instanceof WP_Error ) {
				$warnings[] = sprintf(
					/* translators: 1: add-on slug, 2: form title. */
					__( 'A "%1$s" feed on the form "%2$s" could not be copied to the destination.', 'contentcopy' ),
					$feed['addon_slug'],
					$title
				);

				continue;
			}

			++$written;
		}

		if ( $written > 0 ) {
			$this->logger->info(
				'Copied {count} add-on feed(s) onto form {id} ({slugs}).',
				[ 'count' => $written, 'id' => $formId, 'slugs' => implode( ', ', $slugs ) ]
			);

			$warnings[] = sprintf(
				/* translators: 1: form title, 2: comma-separated add-on slugs. */
				__( 'Add-on feeds on the form "%1$s" were copied to the destination (%2$s). Their settings still name the source site\'s accounts, lists and endpoints — check them on the destination before the form is used.', 'contentcopy' ),
				$title,
				implode( ', ', $slugs )
			);
		}
	}
}
