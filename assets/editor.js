/**
 * Content Copy editor UI.
 *
 * Plain script, no build step: registers a block-editor sidebar panel and
 * drives the classic-editor metabox buttons. Both talk to the plugin's own
 * source-side REST routes; the server holds the destination credentials.
 */
( function () {
	const { createElement: el, useState, useEffect } = wp.element;
	const apiFetch = wp.apiFetch;

	function messages( result ) {
		const out = [];

		if ( result && result.message ) {
			out.push( result.message );
		}

		for ( const warning of result.warnings || [] ) {
			out.push( warning );
		}

		return out;
	}

	async function pushPost( postId, force ) {
		return apiFetch( {
			path: '/contentcopy/v1/local/push',
			method: 'POST',
			data: { post_id: postId, force: !! force },
		} );
	}

	async function fetchStatus( postId ) {
		return apiFetch( {
			path: '/contentcopy/v1/local/status?post_id=' + encodeURIComponent( postId ),
			method: 'GET',
		} );
	}

	function usePush( postId ) {
		const [ state, setState ] = useState( { phase: 'idle', notes: [], conflict: false } );

		const run = async ( force ) => {
			setState( { phase: 'working', notes: [], conflict: false } );

			try {
				const result = await pushPost( postId, force );
				setState( {
					phase: 'done',
					notes: messages( result ),
					conflict: false,
					link: result.link || null,
				} );
			} catch ( error ) {
				const code = error && error.code;
				const data = ( error && error.data ) || {};

				if ( 'contentcopy_conflict' === code && ! force ) {
					setState( {
						phase: 'done',
						notes: [ error.message ],
						conflict: true,
					} );
					return;
				}

				const notes = [ ( error && error.message ) || 'Push failed.' ];

				for ( const line of ( data.log || [] ).filter( ( r ) => r.level === 'WARNING' || r.level === 'ERROR' ) ) {
					notes.push( line.message );
				}

				setState( { phase: 'done', notes, conflict: false } );
			}
		};

		return [ state, run ];
	}

	function Panel() {
		const postId = wp.data.select( 'core/editor' ).getCurrentPostId();
		const [ status, setStatus ] = useState( null );
		const [ push, run ] = usePush( postId );

		useEffect( () => {
			let live = true;

			fetchStatus( postId )
				.then( ( result ) => {
					if ( live ) {
						setStatus( result );
					}
				} )
				.catch( () => {} );

			return () => {
				live = false;
			};
		}, [ postId ] );

		if ( ! postId ) {
			return null;
		}

		const rows = [];

		if ( status && ! status.configured ) {
			rows.push(
				el( 'p', { key: 'cfg' }, 'No destination is configured yet.' )
			);
		}

		if ( status && status.destination ) {
			rows.push(
				el( 'p', { key: 'dest', className: 'description' }, 'Pushes to ' + status.destination + '.' )
			);
		}

		if ( status && status.last_push && status.last_push.time ) {
			rows.push(
				el(
					'p',
					{ key: 'last', className: 'description' },
					'Last pushed ' + new Date( status.last_push.time * 1000 ).toLocaleString() + '.'
				)
			);
		}

		rows.push(
			el(
				wp.components.Button,
				{
					key: 'push',
					variant: 'secondary',
					isBusy: 'working' === push.phase,
					disabled: 'working' === push.phase || ( status && ! status.configured ),
					onClick: () => run( false ),
				},
				'working' === push.phase ? 'Pushing…' : 'Push to destination'
			)
		);

		if ( push.conflict ) {
			rows.push(
				el(
					wp.components.Button,
					{
						key: 'force',
						variant: 'link',
						isDestructive: true,
						onClick: () => run( true ),
					},
					'The destination copy changed — click to overwrite it'
				)
			);
		}

		for ( let i = 0; i < push.notes.length; i++ ) {
			rows.push( el( 'p', { key: 'note-' + i }, push.notes[ i ] ) );
		}

		if ( push.link ) {
			rows.push(
				el( 'p', { key: 'link' }, el( 'a', { href: push.link, target: '_blank', rel: 'noreferrer' }, 'View on destination' ) )
			);
		}

		return el(
			wp.editPost.PluginDocumentSettingPanel,
			{ name: 'contentcopy-push', title: 'Content Copy', className: 'contentcopy-panel' },
			rows
		);
	}

	// Block editor panel.
	if ( wp.plugins && wp.editPost && wp.editPost.PluginDocumentSettingPanel ) {
		wp.plugins.registerPlugin( 'contentcopy-push', { render: Panel } );
	}

	// Classic-editor metabox buttons.
	function bindClassic( root ) {
		root.querySelectorAll( '.contentcopy-push-button' ).forEach( ( button ) => {
			if ( button.dataset.contentcopyBound ) {
				return;
			}

			button.dataset.contentcopyBound = '1';

			button.addEventListener( 'click', async () => {
				const postId = parseInt( button.dataset.postId, 10 );
				const box = button.closest( '.contentcopy-box' );
				const result = box ? box.querySelector( '.contentcopy-push-result' ) : null;
				const forceButton = box ? box.querySelector( '.contentcopy-push-force' ) : null;

				button.disabled = true;

				if ( result ) {
					result.textContent = 'Pushing…';
				}

				if ( forceButton ) {
					forceButton.style.display = 'none';
				}

				try {
					const response = await pushPost( postId, false );

					if ( result ) {
						result.textContent = messages( response ).join( '\n' );
					}
				} catch ( error ) {
					if ( result ) {
						result.textContent = ( error && error.message ) || 'Push failed.';
					}

					if ( error && 'contentcopy_conflict' === error.code && forceButton ) {
						forceButton.style.display = '';
					}
				} finally {
					button.disabled = false;
				}
			} );
		} );

		root.querySelectorAll( '.contentcopy-push-force' ).forEach( ( button ) => {
			if ( button.dataset.contentcopyBound ) {
				return;
			}

			button.dataset.contentcopyBound = '1';

			button.addEventListener( 'click', async () => {
				const postId = parseInt( button.dataset.postId, 10 );
				const box = button.closest( '.contentcopy-box' );
				const result = box ? box.querySelector( '.contentcopy-push-result' ) : null;
				const main = box ? box.querySelector( '.contentcopy-push-button' ) : null;

				button.disabled = true;

				try {
					const response = await pushPost( postId, true );

					if ( result ) {
						result.textContent = messages( response ).join( '\n' );
					}

					button.style.display = 'none';
				} catch ( error ) {
					if ( result ) {
						result.textContent = ( error && error.message ) || 'Push failed.';
					}
				} finally {
					button.disabled = false;

					if ( main ) {
						main.disabled = false;
					}
				}
			} );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', () => bindClassic( document ) );
	} else {
		bindClassic( document );
	}
} )();
