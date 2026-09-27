/* global sitelemetryAuditData */
( function () {
	'use strict';

	var data = window.sitelemetryAuditData || {};
	var i18n = data.i18n || {};

	function formatElapsed( seconds ) {
		var minutes = Math.floor( seconds / 60 );
		var rest = seconds % 60;
		return minutes + ':' + ( rest < 10 ? '0' : '' ) + rest;
	}

	/* A delay Sitelemetry asked for (retryAfterMs, or Retry-After on a 429) is
	   waited in full: polling earlier than the service allows only produces more
	   429s and burns the retry budget. The ceiling applies to the delays this
	   script picks itself (the backoff after a failed progress request), and the
	   floor always applies so the loop cannot hammer admin-ajax. A server delay
	   is additionally bounded by what is left of the job's time budget, so the
	   loop still ends with the job. */
	function clampInterval( ms, serverRequested, remainingMs ) {
		var min = parseInt( data.minIntervalMs, 10 ) || 2000;
		var max = parseInt( data.maxIntervalMs, 10 ) || 30000;
		var value = parseInt( ms, 10 );
		if ( isNaN( value ) || value < 0 ) {
			value = min;
		}
		if ( ! serverRequested ) {
			value = Math.min( max, value );
		} else if ( remainingMs >= 0 ) {
			value = Math.min( value, remainingMs );
		}
		return Math.max( min, value );
	}

	/* Poll loop: one admin-ajax request per step; the server performs one
	   tools/call and answers with the progress. Stops when the job is done. */
	function initProgress() {
		var box = document.getElementById( 'sitelemetry-audit-progress' );
		if ( ! box || box.getAttribute( 'data-state' ) !== 'running' || ! data.ajaxUrl ) {
			return;
		}
		var phaseEl = box.querySelector( '.sitelemetry-audit-phase' );
		var elapsedEl = box.querySelector( '.sitelemetry-audit-elapsed' );
		var errorEl = box.querySelector( '.sitelemetry-audit-progress-error' );
		var elapsed = parseInt( box.getAttribute( 'data-elapsed' ), 10 ) || 0;
		var budget = parseInt( box.getAttribute( 'data-budget' ), 10 ) || 0;
		var failures = 0;
		var stopped = false;
		var ticker = window.setInterval( function () {
			elapsed += 1;
			if ( elapsedEl && i18n.elapsed ) {
				elapsedEl.textContent = i18n.elapsed.replace( '%s', formatElapsed( elapsed ) );
			}
		}, 1000 );

		function stop() {
			stopped = true;
			window.clearInterval( ticker );
		}

		/* Milliseconds left of the job's time budget, or -1 when it is unknown. */
		function remainingMs() {
			return budget > 0 ? Math.max( 0, ( budget - elapsed ) * 1000 ) : -1;
		}

		function schedule( ms, serverRequested ) {
			if ( ! stopped ) {
				window.setTimeout( poll, clampInterval( ms, serverRequested, remainingMs() ) );
			}
		}

		function fail() {
			failures += 1;
			if ( failures >= 5 ) {
				stop();
				if ( errorEl ) {
					errorEl.textContent = i18n.error || '';
					errorEl.hidden = false;
				}
				return;
			}
			schedule( ( parseInt( data.minIntervalMs, 10 ) || 2000 ) * Math.pow( 2, failures ), false );
		}

		function poll() {
			if ( stopped ) {
				return;
			}
			var body = new window.FormData();
			body.append( 'action', 'sitelemetry_audit_poll' );
			body.append( 'nonce', data.nonce || '' );
			window.fetch( data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( response ) {
					if ( ! response.ok ) {
						throw new Error( String( response.status ) );
					}
					return response.json();
				} )
				.then( function ( json ) {
					if ( ! json || ! json.success || ! json.data ) {
						throw new Error( 'invalid' );
					}
					var progress = json.data;
					failures = 0;
					if ( errorEl ) {
						errorEl.hidden = true;
					}
					if ( progress.state === 'done' || progress.state === 'idle' ) {
						stop();
						if ( phaseEl ) {
							phaseEl.textContent = i18n.finished || '';
						}
						window.location.assign( progress.redirect || window.location.href );
						return;
					}
					if ( phaseEl && progress.phase ) {
						phaseEl.textContent = progress.phase;
					}
					if ( typeof progress.elapsed === 'number' ) {
						elapsed = progress.elapsed;
					}
					if ( typeof progress.budget === 'number' ) {
						budget = progress.budget;
					}
					/* retry_after_ms is what Sitelemetry asked for: wait all of it. */
					schedule( progress.retry_after_ms, true );
				} )
				.catch( fail );
		}

		poll();
	}

	/* Severity filter for the findings table (client-side, no request). */
	function initFilter() {
		var select = document.getElementById( 'sitelemetry-audit-severity-filter' );
		var table = document.getElementById( 'sitelemetry-audit-findings' );
		if ( ! select || ! table ) {
			return;
		}
		var empty = document.querySelector( '.sitelemetry-audit-no-matches' );
		select.addEventListener( 'change', function () {
			var wanted = select.value;
			var rows = table.querySelectorAll( 'tbody tr' );
			var visible = 0;
			Array.prototype.forEach.call( rows, function ( row ) {
				var show = ! wanted || row.getAttribute( 'data-severity' ) === wanted;
				row.hidden = ! show;
				if ( show ) {
					visible += 1;
				}
			} );
			if ( empty ) {
				empty.hidden = visible > 0;
			}
		} );
	}

	/* Prevent double submits of the Run audit forms. */
	function initRunForms() {
		var forms = document.querySelectorAll( '.sitelemetry-audit-run' );
		Array.prototype.forEach.call( forms, function ( form ) {
			form.addEventListener( 'submit', function () {
				var buttons = form.querySelectorAll( 'input[type="submit"], button' );
				Array.prototype.forEach.call( buttons, function ( button ) {
					button.disabled = true;
					if ( i18n.running ) {
						button.value = i18n.running;
					}
				} );
			} );
		} );
	}

	/* One-click verification takes a few seconds (Sitelemetry loads the file):
	   the button says so and cannot be pressed twice. */
	function initVerifyForm() {
		var forms = document.querySelectorAll( '.sitelemetry-audit-one-click-form' );
		Array.prototype.forEach.call( forms, function ( form ) {
			form.addEventListener( 'submit', function () {
				var buttons = form.querySelectorAll( 'input[type="submit"], button' );
				Array.prototype.forEach.call( buttons, function ( button ) {
					button.disabled = true;
					if ( i18n.verifying ) {
						button.value = i18n.verifying;
					}
				} );
				form.setAttribute( 'aria-busy', 'true' );
			} );
		} );
	}

	/* Disable the key input when "Remove the stored key" is checked. */
	function initRemoveKey() {
		var checkbox = document.getElementById( 'sitelemetry-audit-remove-key' );
		var input = document.getElementById( 'sitelemetry-audit-api-key' );
		if ( ! checkbox || ! input ) {
			return;
		}
		checkbox.addEventListener( 'change', function () {
			input.disabled = checkbox.checked;
			if ( checkbox.checked ) {
				input.value = '';
			}
		} );
	}

	/* Last resort when the async clipboard API is unavailable (http admin) or
	   refused: a selected, off-screen textarea and the copy command. */
	function copyWithSelection( text ) {
		var area = document.createElement( 'textarea' );
		var previous = document.activeElement;
		var copied = false;
		area.value = text;
		area.setAttribute( 'readonly', '' );
		area.setAttribute( 'aria-hidden', 'true' );
		area.style.position = 'fixed';
		area.style.top = '-1000px';
		area.style.opacity = '0';
		document.body.appendChild( area );
		area.select();
		try {
			copied = document.execCommand( 'copy' );
		} catch ( e ) {
			copied = false;
		}
		document.body.removeChild( area );
		if ( previous && previous.focus ) {
			previous.focus();
		}
		return copied;
	}

	/* "Copy AI fix prompt": the prompt was built by WordPress from the stored
	   result and is already on the page (read-only textarea); the click only
	   copies it to the clipboard. Nothing is sent anywhere. When copying fails,
	   the text is shown and selected so it can be copied by hand. */
	function initFixPrompt() {
		var button = document.getElementById( 'sitelemetry-audit-copy-prompt' );
		var area = document.getElementById( 'sitelemetry-audit-prompt-text' );
		var status = document.getElementById( 'sitelemetry-audit-prompt-status' );
		var details = document.getElementById( 'sitelemetry-audit-prompt-details' );
		if ( ! button || ! area ) {
			return;
		}
		button.hidden = false;

		function done( copied ) {
			if ( status ) {
				status.textContent = copied ? ( i18n.copied || '' ) : ( i18n.copyFail || '' );
				status.classList.toggle( 'is-error', ! copied );
			}
			if ( ! copied ) {
				if ( details ) {
					details.open = true;
				}
				area.focus();
				area.select();
			}
		}

		button.addEventListener( 'click', function () {
			var text = area.value;
			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( text ).then(
					function () {
						done( true );
					},
					function () {
						done( copyWithSelection( text ) );
					}
				);
				return;
			}
			done( copyWithSelection( text ) );
		} );
	}

	function init() {
		initProgress();
		initFilter();
		initRunForms();
		initVerifyForm();
		initRemoveKey();
		initFixPrompt();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
