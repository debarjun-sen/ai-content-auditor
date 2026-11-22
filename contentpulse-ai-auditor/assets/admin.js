/**
 * ContentPulse AI Auditor — admin dashboard.
 *
 * Vanilla JS, no build step. All untrusted strings (post titles, findings,
 * model output) are inserted with textContent; the summary renderer escapes
 * before applying its tiny Markdown subset.
 */
( function () {
	'use strict';

	const cfg = window.aicaConfig || {};
	const apiFetch = window.wp.apiFetch;
	const { __, _n, sprintf } = window.wp.i18n;
	const root = document.getElementById( 'aica-app' );
	if ( ! root || ! apiFetch ) {
		return;
	}

	/* ------------------------------------------------------------------ */
	/* Utilities                                                          */
	/* ------------------------------------------------------------------ */

	const ICONS = {
		search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
		accessibility: '<circle cx="12" cy="4.5" r="1.8"/><path d="M5 8.5l7 1.5 7-1.5M12 10v4.5M12 14.5 8.5 21M12 14.5l3.5 6.5"/>',
		clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		shield: '<path d="M12 3 5 6v6c0 4.5 3 7.5 7 9 4-1.5 7-4.5 7-9V6l-7-3Z"/><path d="m9 12 2 2 4-4"/>',
		high: '<path d="M8 3h8l5 5v8l-5 5H8l-5-5V8l5-5Z"/><path d="M12 8v5M12 16.5h.01"/>',
		medium: '<path d="M12 4 2.5 20h19L12 4Z"/><path d="M12 10v4M12 17h.01"/>',
		low: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
		sparkle: '<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9L12 3Z"/><path d="M19 15.5l.8 1.7 1.7.8-1.7.8-.8 1.7-.8-1.7-1.7-.8 1.7-.8.8-1.7Z"/>',
		bolt: '<path d="M13 2 4 14h7l-1 8 9-12h-7l1-8Z"/>',
		x: '<path d="M6 6l12 12M18 6 6 18"/>',
		check: '<path d="m5 12 5 5 9-10"/>',
		undo: '<path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/>',
		external: '<path d="M14 4h6v6M20 4l-9 9"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
		copy: '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1"/>',
		chevron: '<path d="m6 9 6 6 6-6"/>',
		file: '<path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8l-5-5Z"/><path d="M14 3v5h5"/>',
		tool: '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.5 2.5-2.4-.6-.6-2.4 2.5-2.5Z"/>',
		flag: '<path d="M5 21V4"/><path d="M5 4h11l-2 4 2 4H5"/>',
		edit: '<path d="M4 20h4L19 9l-4-4L4 16v4Z"/>',
		plug: '<path d="M9 2v6M15 2v6"/><path d="M6 8h12v3a6 6 0 0 1-12 0V8Z"/><path d="M12 17v5"/>',
		stop: '<rect x="6" y="6" width="12" height="12" rx="2"/>',
		refresh: '<path d="M20 11a8 8 0 0 0-14.9-3.5L4 9"/><path d="M4 4v5h5"/><path d="M4 13a8 8 0 0 0 14.9 3.5L20 15"/><path d="M20 20v-5h-5"/>',
		message: '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12Z"/>',
		info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
		dismiss: '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6M15 9l-6 6"/>',
		list: '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
		coins: '<ellipse cx="12" cy="6" rx="7" ry="3"/><path d="M5 6v6c0 1.7 3.1 3 7 3s7-1.3 7-3V6"/><path d="M5 12v6c0 1.7 3.1 3 7 3s7-1.3 7-3v-6"/>',
	};

	function icon( name, cls ) {
		const svg = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
		svg.setAttribute( 'viewBox', '0 0 24 24' );
		svg.setAttribute( 'fill', 'none' );
		svg.setAttribute( 'stroke', 'currentColor' );
		svg.setAttribute( 'stroke-width', '2' );
		svg.setAttribute( 'stroke-linecap', 'round' );
		svg.setAttribute( 'stroke-linejoin', 'round' );
		svg.setAttribute( 'aria-hidden', 'true' );
		svg.setAttribute( 'focusable', 'false' );
		svg.setAttribute( 'class', 'aica-icon' + ( cls ? ' ' + cls : '' ) );
		svg.innerHTML = ICONS[ name ] || ICONS.info; // Static, trusted markup.
		return svg;
	}

	/**
	 * Tiny hyperscript helper. Strings become text nodes (never HTML).
	 *
	 * @param {string} tag
	 * @param {Object} attrs
	 * @param {...*}   children
	 * @return {HTMLElement}
	 */
	function h( tag, attrs, ...children ) {
		const el = document.createElement( tag );
		for ( const [ k, v ] of Object.entries( attrs || {} ) ) {
			if ( v === null || v === undefined || v === false ) {
				continue;
			}
			if ( k === 'class' ) {
				el.className = v;
			} else if ( k.startsWith( 'on' ) && typeof v === 'function' ) {
				el.addEventListener( k.slice( 2 ).toLowerCase(), v );
			} else if ( k === 'dataset' ) {
				Object.assign( el.dataset, v );
			} else if ( v === true ) {
				el.setAttribute( k, '' );
			} else {
				el.setAttribute( k, String( v ) );
			}
		}
		append( el, children );
		return el;
	}

	function append( el, children ) {
		for ( const c of children.flat( Infinity ) ) {
			if ( c === null || c === undefined || c === false ) {
				continue;
			}
			el.appendChild( c instanceof Node ? c : document.createTextNode( String( c ) ) );
		}
		return el;
	}

	function clear( el ) {
		while ( el.firstChild ) {
			el.removeChild( el.firstChild );
		}
		return el;
	}

	function escapeHtml( s ) {
		return String( s ).replace( /[&<>"']/g, ( c ) => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ] ) );
	}

	/** Minimal, safe Markdown: escape first, then a few inline/block rules. */
	function markdown( src ) {
		const lines = escapeHtml( src || '' ).split( /\r?\n/ );
		let html = '';
		let list = null;
		const inline = ( s ) =>
			s
				.replace( /`([^`]+)`/g, '<code>$1</code>' )
				.replace( /\*\*([^*]+)\*\*/g, '<strong>$1</strong>' )
				.replace( /(^|[\s(])[_*]([^_*]+)[_*](?=[\s).,!?:;]|$)/g, '$1<em>$2</em>' );
		const close = () => {
			if ( list ) {
				html += '</' + list + '>';
				list = null;
			}
		};
		for ( const raw of lines ) {
			const line = raw.trim();
			let m;
			if ( ! line ) {
				close();
			} else if ( ( m = line.match( /^#{1,4}\s+(.*)$/ ) ) ) {
				close();
				html += '<h4>' + inline( m[ 1 ] ) + '</h4>';
			} else if ( ( m = line.match( /^[-*]\s+(.*)$/ ) ) ) {
				if ( list !== 'ul' ) {
					close();
					html += '<ul>';
					list = 'ul';
				}
				html += '<li>' + inline( m[ 1 ] ) + '</li>';
			} else if ( ( m = line.match( /^\d+[.)]\s+(.*)$/ ) ) ) {
				if ( list !== 'ol' ) {
					close();
					html += '<ol>';
					list = 'ol';
				}
				html += '<li>' + inline( m[ 1 ] ) + '</li>';
			} else {
				close();
				html += '<p>' + inline( line ) + '</p>';
			}
		}
		close();
		const div = h( 'div', { class: 'aica-summary' } );
		div.innerHTML = html; // Safe: every character of input was escaped above.
		return div;
	}

	const rtf = typeof Intl !== 'undefined' && Intl.RelativeTimeFormat ? new Intl.RelativeTimeFormat( undefined, { numeric: 'auto' } ) : null;
	function ago( iso ) {
		if ( ! iso ) {
			return '';
		}
		const diff = ( new Date( iso ).getTime() - Date.now() ) / 1000;
		const abs = Math.abs( diff );
		if ( ! rtf ) {
			return new Date( iso ).toLocaleString();
		}
		if ( abs < 60 ) {
			return rtf.format( Math.round( diff ), 'second' );
		}
		if ( abs < 3600 ) {
			return rtf.format( Math.round( diff / 60 ), 'minute' );
		}
		if ( abs < 86400 ) {
			return rtf.format( Math.round( diff / 3600 ), 'hour' );
		}
		return rtf.format( Math.round( diff / 86400 ), 'day' );
	}
	function clock( mysql ) {
		const d = new Date( String( mysql ).replace( ' ', 'T' ) + 'Z' );
		return isNaN( d ) ? '' : d.toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit', second: '2-digit' } );
	}
	const num = ( n ) => Number( n || 0 ).toLocaleString();
	const money = ( n ) => '$' + Number( n || 0 ).toFixed( Number( n ) < 1 ? 3 : 2 );
	const sleep = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

	function api( path, opts ) {
		return apiFetch( Object.assign( { path: cfg.restPath + path }, opts || {} ) );
	}

	const LABELS = {
		seo: __( 'SEO', 'contentpulse-ai-auditor' ),
		accessibility: __( 'Accessibility', 'contentpulse-ai-auditor' ),
		freshness: __( 'Freshness', 'contentpulse-ai-auditor' ),
		security: __( 'Security', 'contentpulse-ai-auditor' ),
		high: __( 'High', 'contentpulse-ai-auditor' ),
		medium: __( 'Medium', 'contentpulse-ai-auditor' ),
		low: __( 'Low', 'contentpulse-ai-auditor' ),
		open: __( 'Open', 'contentpulse-ai-auditor' ),
		fixed: __( 'Fixed', 'contentpulse-ai-auditor' ),
		dismissed: __( 'Dismissed', 'contentpulse-ai-auditor' ),
		running: __( 'Running', 'contentpulse-ai-auditor' ),
		completed: __( 'Completed', 'contentpulse-ai-auditor' ),
		failed: __( 'Failed', 'contentpulse-ai-auditor' ),
		cancelled: __( 'Cancelled', 'contentpulse-ai-auditor' ),
		budget_exhausted: __( 'Budget reached', 'contentpulse-ai-auditor' ),
		ai: __( 'AI audit', 'contentpulse-ai-auditor' ),
		quick: __( 'Quick scan', 'contentpulse-ai-auditor' ),
		meta_description: __( 'Meta description', 'contentpulse-ai-auditor' ),
		excerpt: __( 'Excerpt', 'contentpulse-ai-auditor' ),
		image_alt: __( 'Image alt text', 'contentpulse-ai-auditor' ),
	};
	const CAT_ICON = { seo: 'search', accessibility: 'accessibility', freshness: 'clock', security: 'shield' };
	const CATS = [ 'seo', 'accessibility', 'freshness', 'security' ];

	function badge( kind, text, iconName ) {
		return h( 'span', { class: 'aica-badge aica-badge--' + kind }, iconName ? icon( iconName, 'aica-icon--sm' ) : null, text );
	}
	const sevBadge = ( s ) => badge( s, LABELS[ s ] || s, s );
	const catBadge = ( c ) => badge( c, LABELS[ c ] || c, CAT_ICON[ c ] );
	const statusBadge = ( s ) => badge( s, LABELS[ s ] || s, s === 'running' ? 'refresh' : s === 'completed' ? 'check' : 'info' );
	function sourceBadge( s ) {
		if ( s === 'ai' ) {
			return badge( 'ai', __( 'Claude', 'contentpulse-ai-auditor' ), 'sparkle' );
		}
		return badge( s, s === 'mcp' ? __( 'MCP agent', 'contentpulse-ai-auditor' ) : __( 'Rule', 'contentpulse-ai-auditor' ), s === 'mcp' ? 'plug' : 'list' );
	}

	function button( label, opts ) {
		const o = opts || {};
		return h(
			'button',
			{
				type: 'button',
				class: 'aica-btn' + ( o.variant ? ' aica-btn--' + o.variant : '' ) + ( o.size ? ' aica-btn--' + o.size : '' ),
				onClick: o.onClick,
				disabled: o.disabled,
				'aria-label': o.ariaLabel,
				'aria-describedby': o.describedBy,
			},
			o.icon ? icon( o.icon ) : null,
			label
		);
	}

	async function busy( btn, fn ) {
		const original = Array.from( btn.childNodes );
		btn.disabled = true;
		btn.setAttribute( 'aria-busy', 'true' );
		clear( btn ).append( h( 'span', { class: 'aica-spinner', 'aria-hidden': 'true' } ), original.filter( ( n ) => n.nodeType === 3 ).map( ( n ) => n.textContent ).join( '' ) );
		try {
			return await fn();
		} finally {
			if ( btn.isConnected ) {
				clear( btn ).append( ...original );
				btn.disabled = false;
				btn.removeAttribute( 'aria-busy' );
			}
		}
	}

	const live = document.getElementById( 'aica-live' );
	function announce( msg ) {
		live.textContent = '';
		window.setTimeout( () => ( live.textContent = msg ), 50 );
	}

	const toasts = document.getElementById( 'aica-toasts' );
	function toast( msg, opts ) {
		const o = opts || {};
		const t = h( 'div', { class: 'aica-toast' + ( o.error ? ' aica-toast--error' : '' ), role: o.error ? 'alert' : 'status' }, icon( o.error ? 'high' : 'check' ), h( 'span', { class: 'aica-toast__msg' }, msg ) );
		if ( o.action ) {
			t.append(
				button( o.action.label, {
					onClick: () => {
						t.remove();
						o.action.run();
					},
				} )
			);
		}
		t.append( button( '', { variant: 'ghost', icon: 'x', ariaLabel: __( 'Dismiss notification', 'contentpulse-ai-auditor' ), onClick: () => t.remove() } ) );
		toasts.append( t );
		window.setTimeout( () => t.remove(), o.error ? 9000 : 6000 );
	}

	function errorMessage( e ) {
		return ( e && e.message ) || __( 'Something went wrong.', 'contentpulse-ai-auditor' );
	}

	/* ------------------------------------------------------------------ */
	/* State                                                              */
	/* ------------------------------------------------------------------ */

	const state = {
		dashboard: null,
		running: null, // { scan, events, cursor, stats }
		driving: false,
		filters: { category: '', severity: '', status: 'open', search: '', scan_id: 0 },
		findings: { items: [], total: 0, page: 1, scan: null },
		expanded: new Set(),
		activityScan: 0,
	};

	const panels = {
		dashboard: document.getElementById( 'aica-panel-dashboard' ),
		findings: document.getElementById( 'aica-panel-findings' ),
		activity: document.getElementById( 'aica-panel-activity' ),
		connect: document.getElementById( 'aica-panel-connect' ),
		settings: document.getElementById( 'aica-panel-settings' ),
	};

	/* ------------------------------------------------------------------ */
	/* Tabs (WAI-ARIA tabs pattern, arrow-key navigation)                 */
	/* ------------------------------------------------------------------ */

	const tabs = Array.from( root.querySelectorAll( '.aica-tab' ) );
	function selectTab( name, focus ) {
		tabs.forEach( ( t ) => {
			const on = t.dataset.tab === name;
			t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			t.tabIndex = on ? 0 : -1;
			if ( on && focus ) {
				t.focus();
			}
		} );
		Object.entries( panels ).forEach( ( [ k, p ] ) => ( p.hidden = k !== name ) );
		const url = new URL( window.location.href );
		url.searchParams.set( 'tab', name );
		url.searchParams.delete( 'settings-updated' );
		window.history.replaceState( null, '', url );
		// After saving, options.php redirects to this referer: keep the user on the Settings tab.
		const referer = root.querySelector( 'input[name="_wp_http_referer"]' );
		if ( referer ) {
			referer.value = url.pathname + url.search;
		}
		if ( name === 'findings' ) {
			loadFindings( true );
		} else if ( name === 'activity' ) {
			renderActivity();
		} else if ( name === 'connect' ) {
			renderConnect();
		} else if ( name === 'dashboard' ) {
			renderDashboard();
		}
	}
	tabs.forEach( ( t, i ) => {
		t.addEventListener( 'click', () => selectTab( t.dataset.tab ) );
		t.addEventListener( 'keydown', ( e ) => {
			let next = null;
			if ( e.key === 'ArrowRight' ) {
				next = tabs[ ( i + 1 ) % tabs.length ];
			} else if ( e.key === 'ArrowLeft' ) {
				next = tabs[ ( i - 1 + tabs.length ) % tabs.length ];
			} else if ( e.key === 'Home' ) {
				next = tabs[ 0 ];
			} else if ( e.key === 'End' ) {
				next = tabs[ tabs.length - 1 ];
			}
			if ( next ) {
				e.preventDefault();
				selectTab( next.dataset.tab, true );
			}
		} );
	} );

	/* ------------------------------------------------------------------ */
	/* Header actions & scan driver                                       */
	/* ------------------------------------------------------------------ */

	const headerActions = document.getElementById( 'aica-header-actions' );
	function renderHeader() {
		clear( headerActions );
		const running = !! state.running;
		headerActions.append(
			button( __( 'Quick scan', 'contentpulse-ai-auditor' ), { icon: 'bolt', disabled: running, onClick: ( e ) => startScan( 'quick', e.currentTarget ) } ),
			button( __( 'Run AI audit', 'contentpulse-ai-auditor' ), { icon: 'sparkle', variant: 'primary', disabled: running, onClick: ( e ) => startScan( 'ai', e.currentTarget ) } )
		);
	}

	async function startScan( mode, btn ) {
		if ( mode === 'ai' && ! cfg.hasKey ) {
			toast( __( 'Add your Claude API key in Settings first — or try a Quick scan, which needs no key.', 'contentpulse-ai-auditor' ), {
				error: true,
				action: { label: __( 'Open Settings', 'contentpulse-ai-auditor' ), run: () => selectTab( 'settings', true ) },
			} );
			return;
		}
		await busy( btn, async () => {
			try {
				const scan = await api( '/scans', { method: 'POST', data: { mode } } );
				state.running = { scan, events: [], cursor: 0, stats: null, errors: 0 };
				announce( mode === 'ai' ? __( 'AI audit started.', 'contentpulse-ai-auditor' ) : __( 'Quick scan started.', 'contentpulse-ai-auditor' ) );
				selectTab( 'dashboard' );
				drive();
			} catch ( e ) {
				toast( errorMessage( e ), { error: true } );
			}
		} );
		renderHeader();
	}

	async function drive() {
		if ( state.driving || ! state.running ) {
			return;
		}
		state.driving = true;
		renderHeader();
		try {
			while ( state.running && ! state.running.scan.is_terminal ) {
				if ( state.running.paused ) {
					break;
				}
				let res;
				try {
					res = await api( '/scans/' + state.running.scan.id + '/step', { method: 'POST', data: { after: state.running.cursor } } );
				} catch ( e ) {
					state.running.errors++;
					state.running.lastError = errorMessage( e );
					if ( state.running.errors >= 3 ) {
						state.running.paused = true;
					}
					renderRunCard();
					await sleep( 4000 );
					continue;
				}
				ingest( res );
				if ( res.step_error ) {
					state.running.lastError = res.step_error.message;
					if ( res.step_error.retryable ) {
						state.running.errors++;
						if ( state.running.errors >= 5 ) {
							state.running.paused = true;
						}
						renderRunCard();
						await sleep( 6000 );
						continue;
					}
				} else {
					state.running.errors = 0;
					state.running.lastError = '';
				}
				renderRunCard();
				await sleep( state.running.scan.mode === 'quick' ? 150 : 400 );
			}
		} finally {
			state.driving = false;
		}
		if ( state.running && state.running.scan.is_terminal ) {
			const s = state.running.scan;
			announce( sprintf( __( 'Scan finished: %s.', 'contentpulse-ai-auditor' ), LABELS[ s.status ] || s.status ) );
			toast( s.status === 'completed' ? __( 'Audit complete — your report is ready.', 'contentpulse-ai-auditor' ) : s.error || LABELS[ s.status ], { error: s.status === 'failed' } );
			state.running = null;
			state.filters.scan_id = 0;
			await loadDashboard();
		}
		renderHeader();
	}

	function ingest( res ) {
		const r = state.running;
		r.scan = res.scan;
		r.stats = res.stats;
		for ( const e of res.events || [] ) {
			e.isNew = true;
			r.events.push( e );
			r.cursor = Math.max( r.cursor, e.id );
		}
		if ( r.events.length > 400 ) {
			r.events = r.events.slice( -400 );
		}
	}

	async function cancelScan( btn ) {
		if ( ! state.running ) {
			return;
		}
		await busy( btn, async () => {
			try {
				const res = await api( '/scans/' + state.running.scan.id + '/cancel', { method: 'POST' } );
				ingest( res );
			} catch ( e ) {
				toast( errorMessage( e ), { error: true } );
			}
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Dashboard                                                          */
	/* ------------------------------------------------------------------ */

	async function loadDashboard() {
		try {
			state.dashboard = await api( '/dashboard' );
		} catch ( e ) {
			clear( panels.dashboard ).append( h( 'div', { class: 'aica-callout aica-callout--error', role: 'alert' }, icon( 'high' ), h( 'p', null, errorMessage( e ) ) ) );
			return;
		}
		cfg.hasKey = state.dashboard.config.has_key;
		const active = state.dashboard.active_scan;
		if ( active && ! active.is_terminal && ! state.running ) {
			const res = await api( '/scans/' + active.id ).catch( () => null );
			if ( res ) {
				state.running = { scan: res.scan, events: [], cursor: 0, stats: res.stats, errors: 0 };
				ingest( res );
				state.running.events.forEach( ( e ) => ( e.isNew = false ) );
				drive();
			}
		}
		updateFindingsCount();
		renderHeader();
		if ( ! panels.dashboard.hidden ) {
			renderDashboard();
		}
	}

	function updateFindingsCount() {
		const el = document.getElementById( 'aica-findings-count' );
		const latest = state.dashboard && state.dashboard.latest;
		const open = latest && latest.stats ? latest.stats.totals.open : 0;
		el.hidden = ! open;
		el.textContent = open ? String( open ) : '';
		el.setAttribute( 'aria-label', sprintf( _n( '%d open finding', '%d open findings', open, 'contentpulse-ai-auditor' ), open ) );
	}

	function renderDashboard() {
		const d = state.dashboard;
		if ( ! d ) {
			return;
		}
		const p = clear( panels.dashboard );
		const wrap = h( 'div', { class: 'aica-stack' } );
		p.append( wrap );

		if ( state.running ) {
			wrap.append( h( 'div', { id: 'aica-run-slot' } ) );
		}

		if ( ! d.latest ) {
			if ( ! state.running ) {
				wrap.append( emptyState() );
			}
			renderRunCard();
			return;
		}

		const latest = d.latest;
		const prev = d.history.find( ( s ) => s.id !== latest.id && s.status === 'completed' );
		wrap.append( heroCard( latest, prev ) );

		const cats = h( 'div', { class: 'aica-grid aica-grid--4' } );
		CATS.forEach( ( c ) => {
			const v = latest.stats.categories[ c ];
			if ( c === 'security' && ! v.total ) {
				cats.append( securityOkCard() );
				return;
			}
			cats.append( categoryCard( c, v ) );
		} );
		wrap.append( cats );

		const two = h( 'div', { class: 'aica-grid aica-grid--2' } );
		two.append( topIssuesCard( d.top, latest ), historyCard( d.history ) );
		wrap.append( two );
		renderRunCard();
	}

	function emptyState() {
		return h(
			'div',
			{ class: 'aica-card aica-empty' },
			h(
				'div',
				null,
				h( 'h2', null, __( 'Audit your content in minutes', 'contentpulse-ai-auditor' ) ),
				h( 'p', null, __( 'Claude reads your posts and pages with a set of audit tools, confirms real problems, and writes ready-to-approve fixes. Nothing changes on your site until you click Apply.', 'contentpulse-ai-auditor' ) ),
				h(
					'ol',
					{ class: 'aica-steps' },
					h( 'li', null, h( 'strong', null, __( 'Quick scan', 'contentpulse-ai-auditor' ) ), ' — ', __( 'instant rules-based check, no API key needed.', 'contentpulse-ai-auditor' ) ),
					h( 'li', null, h( 'strong', null, __( 'AI audit', 'contentpulse-ai-auditor' ) ), ' — ', __( 'Claude verifies, prioritizes and drafts fixes. You watch it work live.', 'contentpulse-ai-auditor' ) ),
					h( 'li', null, h( 'strong', null, __( 'Review & apply', 'contentpulse-ai-auditor' ) ), ' — ', __( 'approve fixes one by one, with undo.', 'contentpulse-ai-auditor' ) )
				),
				h(
					'div',
					{ class: 'aica-filters' },
					button( __( 'Run AI audit', 'contentpulse-ai-auditor' ), { icon: 'sparkle', variant: 'primary', size: 'lg', onClick: ( e ) => startScan( 'ai', e.currentTarget ) } ),
					button( __( 'Quick scan', 'contentpulse-ai-auditor' ), { icon: 'bolt', size: 'lg', onClick: ( e ) => startScan( 'quick', e.currentTarget ) } )
				),
				cfg.demoActive ? h( 'p', { class: 'aica-small' }, __( 'Trying it out? ', 'contentpulse-ai-auditor' ), h( 'a', { href: cfg.demoUrl }, __( 'Load demo content with deliberate issues', 'contentpulse-ai-auditor' ) ), '.' ) : null
			),
			emptyArt()
		);
	}

	function emptyArt() {
		const div = h( 'div', { class: 'aica-empty__art', 'aria-hidden': 'true' } );
		// Static decorative illustration (trusted markup).
		div.innerHTML =
			'<svg viewBox="0 0 320 220" fill="none"><rect x="20" y="20" width="200" height="170" rx="14" fill="#fff" stroke="#dcdfe5" stroke-width="2"/><rect x="40" y="44" width="120" height="10" rx="5" fill="#e3e8fc"/><rect x="40" y="66" width="160" height="8" rx="4" fill="#eef0f2"/><rect x="40" y="82" width="140" height="8" rx="4" fill="#eef0f2"/><rect x="40" y="106" width="70" height="56" rx="8" fill="#f2eefc"/><rect x="120" y="106" width="80" height="8" rx="4" fill="#eef0f2"/><rect x="120" y="122" width="64" height="8" rx="4" fill="#eef0f2"/><rect x="120" y="138" width="72" height="8" rx="4" fill="#eef0f2"/><circle cx="232" cy="120" r="54" fill="#eef1fd" stroke="#3858e9" stroke-width="6"/><path d="m270 158 28 28" stroke="#3858e9" stroke-width="10" stroke-linecap="round"/><path d="m210 120 14 14 26-28" stroke="#007a3d" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"/><path d="M270 34l4.5 12 12 4.5-12 4.5-4.5 12-4.5-12-12-4.5 12-4.5 4.5-12Z" fill="#6c3fd4"/></svg>';
		return div;
	}

	function gauge( score, grade ) {
		const r = 60;
		const c = 2 * Math.PI * r;
		const color = score >= 75 ? '#007a3d' : score >= 50 ? '#c27a00' : '#b32d2e';
		const wrap = h( 'div', { class: 'aica-gauge', role: 'img', 'aria-label': sprintf( __( 'Content health score %1$d out of 100, grade %2$s', 'contentpulse-ai-auditor' ), score, grade ) } );
		wrap.innerHTML =
			'<svg viewBox="0 0 148 148" aria-hidden="true"><circle class="aica-gauge__track" cx="74" cy="74" r="' + r + '" fill="none" stroke-width="12"/>' +
			'<circle class="aica-gauge__value" cx="74" cy="74" r="' + r + '" fill="none" stroke="' + color + '" stroke-width="12" stroke-linecap="round" stroke-dasharray="' + c.toFixed( 1 ) + '" stroke-dashoffset="' + c.toFixed( 1 ) + '"/></svg>';
		wrap.append( h( 'div', { class: 'aica-gauge__label', 'aria-hidden': 'true' }, h( 'span', { class: 'aica-gauge__score' }, String( score ) ), h( 'span', { class: 'aica-gauge__grade' }, sprintf( __( 'Grade %s', 'contentpulse-ai-auditor' ), grade ) ) ) );
		window.requestAnimationFrame( () => {
			const v = wrap.querySelector( '.aica-gauge__value' );
			if ( v ) {
				v.style.strokeDashoffset = String( c * ( 1 - score / 100 ) );
			}
		} );
		return wrap;
	}

	function heroCard( latest, prev ) {
		const st = latest.stats;
		const delta = prev ? st.score - prev.score : null;
		const meta = h(
			'div',
			{ class: 'aica-hero__meta' },
			h( 'span', null, icon( 'clock', 'aica-icon--sm' ), sprintf( __( 'Audited %s', 'contentpulse-ai-auditor' ), ago( latest.finished_at || latest.created_at ) ) ),
			h( 'span', null, icon( latest.mode === 'ai' ? 'sparkle' : 'bolt', 'aica-icon--sm' ), latest.mode === 'ai' ? latest.model_label : LABELS.quick ),
			h( 'span', null, icon( 'file', 'aica-icon--sm' ), sprintf( _n( '%d item', '%d items', latest.posts_scanned, 'contentpulse-ai-auditor' ), latest.posts_scanned ) ),
			h( 'span', null, icon( 'flag', 'aica-icon--sm' ), sprintf( __( '%1$d open · %2$d fixed', 'contentpulse-ai-auditor' ), st.totals.open, st.totals.fixed ) ),
			latest.mode === 'ai' ? h( 'span', null, icon( 'coins', 'aica-icon--sm' ), sprintf( __( '%1$s · %2$s tokens', 'contentpulse-ai-auditor' ), money( latest.cost_usd ), num( latest.tokens ) ) ) : null
		);
		const headline = st.score >= 90 ? __( 'Your content is in great shape', 'contentpulse-ai-auditor' ) : st.score >= 70 ? __( 'Good, with a few things to fix', 'contentpulse-ai-auditor' ) : st.score >= 45 ? __( 'Several issues need attention', 'contentpulse-ai-auditor' ) : __( 'Significant issues found', 'contentpulse-ai-auditor' );
		return h(
			'div',
			{ class: 'aica-card aica-hero' },
			gauge( st.score, st.grade ),
			h(
				'div',
				null,
				h(
					'h2',
					null,
					headline,
					' ',
					delta !== null && delta !== 0 ? h( 'span', { class: 'aica-delta aica-delta--' + ( delta > 0 ? 'up' : 'down' ) }, ( delta > 0 ? '▲ ' : '▼ ' ) + Math.abs( delta ), h( 'span', { class: 'screen-reader-text' }, __( ' points since the previous audit', 'contentpulse-ai-auditor' ) ) ) : null
				),
				meta,
				latest.summary ? markdown( latest.summary ) : null,
				latest.error && latest.status !== 'completed' ? h( 'div', { class: 'aica-callout aica-callout--warn' }, icon( 'medium' ), h( 'p', null, latest.error ) ) : null,
				h( 'div', { class: 'aica-filters' }, button( sprintf( __( 'Review %d open findings', 'contentpulse-ai-auditor' ), st.totals.open ), { variant: 'primary', icon: 'list', onClick: () => openFindings( {} ) } ) )
			)
		);
	}

	function categoryCard( c, v ) {
		const card = h(
			'button',
			{ type: 'button', class: 'aica-card aica-cat aica-cat--' + c, onClick: () => openFindings( { category: c } ), 'aria-label': sprintf( __( '%1$s: score %2$d, %3$d open findings. Show findings.', 'contentpulse-ai-auditor' ), LABELS[ c ], v.score, v.open ) },
			h( 'div', { class: 'aica-cat__top' }, h( 'span', { class: 'aica-cat__icon' }, icon( CAT_ICON[ c ] ) ), h( 'span', { class: 'aica-cat__score' }, String( v.score ) ) ),
			h( 'span', { class: 'aica-cat__name' }, LABELS[ c ] ),
			h( 'div', { class: 'aica-bar', 'aria-hidden': 'true' }, h( 'span', { style: 'width:' + v.score + '%' } ) ),
			h(
				'div',
				{ class: 'aica-cat__counts' },
				v.open === 0 ? badge( 'fixed', __( 'All clear', 'contentpulse-ai-auditor' ), 'check' ) : null,
				v.high ? badge( 'high', sprintf( __( '%d high', 'contentpulse-ai-auditor' ), v.high ), 'high' ) : null,
				v.medium ? badge( 'medium', sprintf( __( '%d medium', 'contentpulse-ai-auditor' ), v.medium ), 'medium' ) : null,
				v.low ? badge( 'low', sprintf( __( '%d low', 'contentpulse-ai-auditor' ), v.low ), 'low' ) : null
			)
		);
		return card;
	}

	function securityOkCard() {
		return h(
			'div',
			{ class: 'aica-card aica-cat aica-cat--security', style: 'cursor:default' },
			h( 'div', { class: 'aica-cat__top' }, h( 'span', { class: 'aica-cat__icon' }, icon( 'shield' ) ), h( 'span', { class: 'aica-cat__score' }, '✓' ) ),
			h( 'span', { class: 'aica-cat__name' }, __( 'Injection guard', 'contentpulse-ai-auditor' ) ),
			h( 'span', { class: 'aica-small aica-muted' }, __( 'No instructions aimed at AI systems were found in your content.', 'contentpulse-ai-auditor' ) )
		);
	}

	function topIssuesCard( top ) {
		const card = h( 'div', { class: 'aica-card' }, h( 'div', { class: 'aica-card__head' }, h( 'h3', { class: 'aica-card__title' }, icon( 'flag' ), __( 'Top priorities', 'contentpulse-ai-auditor' ) ), h( 'button', { type: 'button', class: 'aica-link-btn', onClick: () => openFindings( {} ) }, __( 'See all', 'contentpulse-ai-auditor' ) ) ) );
		if ( ! top.length ) {
			card.append( h( 'div', { class: 'aica-empty--small' }, icon( 'check' ), h( 'p', null, __( 'No open findings. Nice work!', 'contentpulse-ai-auditor' ) ) ) );
			return card;
		}
		const ul = h( 'ul', { class: 'aica-issues' } );
		top.forEach( ( f ) =>
			ul.append(
				h(
					'li',
					null,
					h(
						'button',
						{ type: 'button', class: 'aica-issue-row', onClick: () => openFindings( {}, f.id ) },
						sevBadge( f.severity ),
						h( 'span', null, h( 'span', { class: 'aica-issue-row__title' }, f.title ), h( 'span', { class: 'aica-issue-row__post' }, LABELS[ f.category ] + ' · ' + f.post_title ) ),
						f.fix ? badge( 'ai', __( 'Fix ready', 'contentpulse-ai-auditor' ), 'sparkle' ) : icon( 'chevron' )
					)
				)
			)
		);
		card.append( ul );
		return card;
	}

	function historyCard( history ) {
		const card = h( 'div', { class: 'aica-card' }, h( 'div', { class: 'aica-card__head' }, h( 'h3', { class: 'aica-card__title' }, icon( 'clock' ), __( 'Audit history', 'contentpulse-ai-auditor' ) ) ) );
		const tbody = h( 'tbody' );
		history.forEach( ( s ) =>
			tbody.append(
				h(
					'tr',
					null,
					h( 'td', null, h( 'button', { type: 'button', class: 'aica-link-btn', onClick: () => openFindings( { scan_id: s.id } ), 'aria-label': sprintf( __( 'Show findings of scan %d', 'contentpulse-ai-auditor' ), s.id ) }, ago( s.created_at ) ) ),
					h( 'td', null, s.mode === 'ai' ? s.model_label : LABELS.quick ),
					h( 'td', null, statusBadge( s.status ) ),
					h( 'td', { class: 'num' }, String( s.findings ) ),
					h( 'td', { class: 'num' }, h( 'strong', null, String( s.score ) ) )
				)
			)
		);
		card.append(
			h(
				'div',
				{ class: 'aica-table-wrap' },
				h( 'table', { class: 'aica-table' }, h( 'thead', null, h( 'tr', null, h( 'th', { scope: 'col' }, __( 'When', 'contentpulse-ai-auditor' ) ), h( 'th', { scope: 'col' }, __( 'Type', 'contentpulse-ai-auditor' ) ), h( 'th', { scope: 'col' }, __( 'Status', 'contentpulse-ai-auditor' ) ), h( 'th', { scope: 'col', class: 'num' }, __( 'Findings', 'contentpulse-ai-auditor' ) ), h( 'th', { scope: 'col', class: 'num' }, __( 'Score', 'contentpulse-ai-auditor' ) ) ) ), tbody )
			)
		);
		return card;
	}

	/* ------------------------------------------------------------------ */
	/* Live run card                                                      */
	/* ------------------------------------------------------------------ */

	function renderRunCard() {
		const slot = document.getElementById( 'aica-run-slot' );
		if ( ! slot ) {
			// Render into the (possibly hidden) dashboard so the run card and live activity stay current.
			if ( state.running && state.dashboard ) {
				renderDashboard();
			}
			return;
		}
		const r = state.running;
		if ( ! r ) {
			slot.remove();
			return;
		}
		const s = r.scan;
		const isAi = s.mode === 'ai';
		const pct = isAi ? Math.min( 100, Math.round( ( 100 * s.iterations ) / Math.max( 1, s.max_iterations ) ) ) : Math.min( 100, Math.round( ( 100 * s.posts_scanned ) / Math.max( 1, s.max_posts ) ) );
		const found = r.stats ? r.stats.totals.total : 0;
		const stat = ( value, label ) => h( 'div', { class: 'aica-stat' }, h( 'span', { class: 'aica-stat__value' }, value ), h( 'span', { class: 'aica-stat__label' }, label ) );

		const card = h(
			'div',
			{ class: 'aica-card aica-run', 'aria-busy': s.is_terminal ? 'false' : 'true' },
			h(
				'div',
				{ class: 'aica-card__head' },
				h( 'h2', { class: 'aica-card__title' }, s.is_terminal ? icon( 'check' ) : h( 'span', { class: 'aica-spinner', 'aria-hidden': 'true', style: 'color:#3858e9' } ), isAi ? sprintf( __( 'Claude is auditing your site (%s)', 'contentpulse-ai-auditor' ), s.model_label ) : __( 'Running quick scan', 'contentpulse-ai-auditor' ) ),
				s.is_terminal ? statusBadge( s.status ) : button( __( 'Cancel', 'contentpulse-ai-auditor' ), { icon: 'stop', variant: 'danger', size: 'sm', onClick: ( e ) => cancelScan( e.currentTarget ) } )
			),
			h( 'div', { class: 'aica-progress' + ( isAi && ! s.is_terminal ? ' aica-progress--indeterminate' : '' ), role: 'progressbar', 'aria-label': __( 'Scan progress', 'contentpulse-ai-auditor' ), 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': isAi ? null : String( pct ) }, h( 'span', { style: 'width:' + pct + '%' } ) ),
			h(
				'div',
				{ class: 'aica-run__stats' },
				isAi ? stat( s.iterations + ' / ' + s.max_iterations, __( 'Agent turns', 'contentpulse-ai-auditor' ) ) : stat( String( s.posts_scanned ), __( 'Items checked', 'contentpulse-ai-auditor' ) ),
				isAi ? stat( s.posts_scanned + ' / ' + s.max_posts, __( 'Posts read', 'contentpulse-ai-auditor' ) ) : stat( String( s.max_posts ), __( 'Max items', 'contentpulse-ai-auditor' ) ),
				stat( String( found ), __( 'Findings', 'contentpulse-ai-auditor' ) ),
				isAi ? stat( money( s.cost_usd ), sprintf( __( '%s tokens', 'contentpulse-ai-auditor' ), num( s.tokens ) ) ) : stat( '$0', __( 'No API cost', 'contentpulse-ai-auditor' ) )
			),
			r.lastError ? h( 'div', { class: 'aica-callout aica-callout--warn', role: 'alert' }, icon( 'medium' ), h( 'p', null, r.lastError, r.paused ? ' ' : '', r.paused ? h( 'button', { type: 'button', class: 'aica-link-btn', onClick: () => { r.paused = false; r.errors = 0; drive(); } }, __( 'Resume', 'contentpulse-ai-auditor' ) ) : null ) ) : null,
			timeline( r.events.slice( -8 ), true )
		);
		clear( slot ).append( card );
		r.events.forEach( ( e ) => ( e.isNew = false ) );
		// Keep the Activity tab live too.
		if ( ! panels.activity.hidden && ( ! state.activityScan || state.activityScan === s.id ) ) {
			renderActivity();
		}
	}

	const EVENT_ICON = { tool_call: 'tool', tool_result: 'check', tool_error: 'high', finding: 'flag', assistant: 'message', safety: 'shield', error: 'high', info: 'info', done: 'check', fix: 'edit' };

	function timeline( events, compact ) {
		if ( ! events.length ) {
			return h( 'p', { class: 'aica-muted' }, __( 'Waiting for the first step…', 'contentpulse-ai-auditor' ) );
		}
		const ol = h( 'ol', { class: 'aica-timeline', 'aria-label': __( 'Agent activity', 'contentpulse-ai-auditor' ) } );
		events.forEach( ( e ) => {
			const body = h( 'div', { class: 'aica-event__body' }, e.summary );
			if ( ! compact && e.type === 'tool_call' && e.payload && e.payload.input ) {
				body.append( h( 'details', null, h( 'summary', null, sprintf( __( 'Tool input · %s', 'contentpulse-ai-auditor' ), e.tool ) ), h( 'pre', null, JSON.stringify( e.payload.input, null, 2 ) ) ) );
			}
			ol.append( h( 'li', { class: 'aica-event aica-event--' + e.type + ( e.isNew ? ' aica-event--new' : '' ) }, h( 'span', { class: 'aica-event__dot' }, icon( EVENT_ICON[ e.type ] || 'info', 'aica-icon--sm' ) ), body, h( 'time', { class: 'aica-event__time' }, clock( e.created_at ) ) ) );
		} );
		return ol;
	}

	/* ------------------------------------------------------------------ */
	/* Findings                                                           */
	/* ------------------------------------------------------------------ */

	function openFindings( filters, focusId ) {
		state.filters = Object.assign( { category: '', severity: '', status: 'open', search: '', scan_id: state.filters.scan_id || 0 }, filters );
		if ( focusId ) {
			state.expanded.add( focusId );
			state.focusFinding = focusId;
			state.filters.status = '';
		}
		selectTab( 'findings', false );
	}

	let searchTimer = 0;
	async function loadFindings( reset ) {
		if ( reset ) {
			state.findings.page = 1;
		}
		const f = state.filters;
		const q = new URLSearchParams( { page: String( state.findings.page ), per_page: '30' } );
		[ 'category', 'severity', 'status', 'search' ].forEach( ( k ) => f[ k ] && q.set( k, f[ k ] ) );
		if ( f.scan_id ) {
			q.set( 'scan_id', String( f.scan_id ) );
		}
		if ( reset && ! state.findings.items.length ) {
			clear( panels.findings ).append( h( 'div', { class: 'aica-skeleton', 'aria-hidden': 'true' }, h( 'span' ), h( 'span' ) ) );
		}
		try {
			const res = await api( '/findings?' + q.toString() );
			state.findings.scan = res.scan;
			state.findings.total = res.total;
			state.findings.items = reset ? res.items : state.findings.items.concat( res.items );
			renderFindings();
		} catch ( e ) {
			clear( panels.findings ).append( h( 'div', { class: 'aica-callout aica-callout--error', role: 'alert' }, icon( 'high' ), h( 'p', null, errorMessage( e ) ) ) );
		}
	}

	function renderFindings() {
		const p = clear( panels.findings );
		const scan = state.findings.scan;
		if ( ! scan ) {
			p.append( h( 'div', { class: 'aica-card aica-empty--small' }, icon( 'search' ), h( 'p', null, __( 'No audits yet. Run a Quick scan or an AI audit to see findings here.', 'contentpulse-ai-auditor' ) ) ) );
			return;
		}
		const cats = scan.stats.categories;
		const f = state.filters;

		const seg = h( 'div', { class: 'aica-segmented', role: 'group', 'aria-label': __( 'Filter by category', 'contentpulse-ai-auditor' ) } );
		[ '', ...CATS ].forEach( ( c ) => {
			const count = c ? cats[ c ].total : scan.stats.totals.total;
			if ( c === 'security' && ! count ) {
				return;
			}
			seg.append(
				h(
					'button',
					{ type: 'button', 'aria-pressed': f.category === c ? 'true' : 'false', onClick: () => { f.category = c; loadFindings( true ); } },
					c ? icon( CAT_ICON[ c ], 'aica-icon--sm' ) : null,
					c ? LABELS[ c ] : __( 'All', 'contentpulse-ai-auditor' ),
					h( 'span', { class: 'aica-count' }, String( count ) )
				)
			);
		} );

		const select = ( id, label, key, options ) => {
			const s = h( 'select', { id, onChange: ( e ) => { f[ key ] = e.target.value; loadFindings( true ); } } );
			options.forEach( ( [ v, t ] ) => s.append( h( 'option', { value: v, selected: f[ key ] === v } , t ) ) );
			return h( 'div', { class: 'aica-field' }, h( 'label', { for: id }, label ), s );
		};
		const search = h( 'input', {
			type: 'search',
			id: 'aica-f-search',
			value: f.search,
			placeholder: __( 'Search findings', 'contentpulse-ai-auditor' ),
			onInput: ( e ) => {
				window.clearTimeout( searchTimer );
				searchTimer = window.setTimeout( () => { f.search = e.target.value; loadFindings( true ).then( () => { const el = document.getElementById( 'aica-f-search' ); if ( el ) { el.focus(); el.setSelectionRange( el.value.length, el.value.length ); } } ); }, 350 );
			},
		} );

		p.append(
			h(
				'div',
				{ class: 'aica-toolbar' },
				seg,
				h(
					'div',
					{ class: 'aica-filters' },
					select( 'aica-f-sev', __( 'Severity', 'contentpulse-ai-auditor' ), 'severity', [ [ '', __( 'Any severity', 'contentpulse-ai-auditor' ) ], [ 'high', LABELS.high ], [ 'medium', LABELS.medium ], [ 'low', LABELS.low ] ] ),
					select( 'aica-f-status', __( 'Status', 'contentpulse-ai-auditor' ), 'status', [ [ 'open', LABELS.open ], [ 'fixed', LABELS.fixed ], [ 'dismissed', LABELS.dismissed ], [ '', __( 'Any status', 'contentpulse-ai-auditor' ) ] ] ),
					h( 'div', { class: 'aica-field' }, h( 'label', { for: 'aica-f-search' }, __( 'Search', 'contentpulse-ai-auditor' ) ), search )
				)
			),
			h(
				'p',
				{ class: 'aica-muted aica-small', role: 'status' },
				sprintf( _n( '%d finding', '%d findings', state.findings.total, 'contentpulse-ai-auditor' ), state.findings.total ),
				' · ',
				sprintf( __( '%1$s from %2$s', 'contentpulse-ai-auditor' ), scan.mode === 'ai' ? scan.model_label : LABELS[ scan.mode ] || scan.mode, ago( scan.created_at ) ),
				f.scan_id ? [ ' · ', h( 'button', { type: 'button', class: 'aica-link-btn', onClick: () => { f.scan_id = 0; loadFindings( true ); } }, __( 'Show latest audit', 'contentpulse-ai-auditor' ) ) ] : null
			)
		);

		if ( ! state.findings.items.length ) {
			p.append( h( 'div', { class: 'aica-card aica-empty--small' }, icon( 'check' ), h( 'p', null, f.status === 'open' ? __( 'No open findings match these filters.', 'contentpulse-ai-auditor' ) : __( 'No findings match these filters.', 'contentpulse-ai-auditor' ) ) ) );
			return;
		}

		const list = h( 'div', { class: 'aica-findings' } );
		state.findings.items.forEach( ( item ) => list.append( findingCard( item ) ) );
		p.append( list );

		if ( state.findings.items.length < state.findings.total ) {
			p.append( h( 'div', { style: 'text-align:center;margin-top:16px' }, button( __( 'Load more', 'contentpulse-ai-auditor' ), { onClick: ( e ) => busy( e.currentTarget, () => { state.findings.page++; return loadFindings( false ); } ) } ) ) );
		}

		if ( state.focusFinding ) {
			const el = document.getElementById( 'aica-finding-' + state.focusFinding + '-head' );
			state.focusFinding = 0;
			if ( el ) {
				el.scrollIntoView( { block: 'center', behavior: window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ? 'auto' : 'smooth' } );
				el.focus( { preventScroll: true } );
			}
		}
	}

	function findingCard( item ) {
		const open = state.expanded.has( item.id );
		const bodyId = 'aica-finding-' + item.id + '-body';
		const head = h(
			'button',
			{
				type: 'button',
				class: 'aica-finding__head',
				id: 'aica-finding-' + item.id + '-head',
				'aria-expanded': open ? 'true' : 'false',
				'aria-controls': bodyId,
				onClick: () => {
					if ( state.expanded.has( item.id ) ) {
						state.expanded.delete( item.id );
					} else {
						state.expanded.add( item.id );
					}
					const card = findingCard( item );
					article.replaceWith( card );
					card.querySelector( '.aica-finding__head' ).focus();
				},
			},
			h(
				'div',
				null,
				h( 'div', { class: 'aica-finding__badges' }, sevBadge( item.severity ), catBadge( item.category ), sourceBadge( item.source ), item.status !== 'open' ? badge( item.status, LABELS[ item.status ], item.status === 'fixed' ? 'check' : 'dismiss' ) : null, item.fix && item.status === 'open' ? badge( 'ai', __( 'Fix ready', 'contentpulse-ai-auditor' ), 'sparkle' ) : null ),
				h( 'h3', { class: 'aica-finding__title' }, item.title ),
				h( 'p', { class: 'aica-finding__post' }, ( item.post_type ? item.post_type + ': ' : '' ) + item.post_title )
			),
			h( 'span', { class: 'aica-finding__chev' }, icon( 'chevron' ) )
		);
		const article = h( 'article', { class: 'aica-card aica-finding aica-finding--' + ( item.status === 'open' ? item.severity : item.status ) }, h( 'h2', { class: 'screen-reader-text' }, item.title ), head );
		if ( ! open ) {
			return article;
		}

		const body = h( 'div', { class: 'aica-finding__body', id: bodyId } );
		if ( item.detail ) {
			body.append( h( 'h4', null, __( 'What we found', 'contentpulse-ai-auditor' ) ), h( 'p', null, item.detail ) );
		}
		if ( item.recommendation ) {
			body.append( h( 'h4', null, __( 'How to fix it', 'contentpulse-ai-auditor' ) ), h( 'p', null, item.recommendation ) );
		}

		let editor = null;
		if ( item.fix ) {
			body.append( h( 'h4', null, sprintf( __( 'Suggested fix · %s', 'contentpulse-ai-auditor' ), LABELS[ item.fix.field ] || item.fix.field ) ) );
			const before = item.fix.current ? h( 'span', null, item.fix.current ) : h( 'span', { class: 'aica-diff__empty' }, __( '(empty)', 'contentpulse-ai-auditor' ) );
			let after;
			if ( item.status === 'open' ) {
				const max = item.fix.field === 'image_alt' ? 250 : item.fix.field === 'excerpt' ? 600 : 320;
				const counter = h( 'span', { class: 'aica-charcount', 'aria-live': 'polite' } );
				editor = h( 'textarea', { id: 'aica-fix-' + item.id, maxlength: String( max ), 'aria-describedby': 'aica-fix-' + item.id + '-count', onInput: () => ( counter.textContent = sprintf( __( '%d characters', 'contentpulse-ai-auditor' ), editor.value.length ) ) } );
				editor.value = item.fix.value;
				counter.id = 'aica-fix-' + item.id + '-count';
				counter.textContent = sprintf( __( '%d characters', 'contentpulse-ai-auditor' ), item.fix.value.length );
				after = h( 'div', null, h( 'label', { class: 'screen-reader-text', for: 'aica-fix-' + item.id }, __( 'Suggested value (editable)', 'contentpulse-ai-auditor' ) ), editor, counter );
			} else {
				after = h( 'span', null, item.fix.value );
			}
			body.append(
				h(
					'div',
					{ class: 'aica-diff' },
					h( 'div', { class: 'aica-diff__side aica-diff__side--before' }, h( 'div', { class: 'aica-diff__label' }, icon( 'x', 'aica-icon--sm' ), __( 'Current', 'contentpulse-ai-auditor' ) ), before ),
					h( 'div', { class: 'aica-diff__side aica-diff__side--after' }, h( 'div', { class: 'aica-diff__label' }, icon( 'check', 'aica-icon--sm' ), item.status === 'open' ? __( 'Suggested — you can edit before applying', 'contentpulse-ai-auditor' ) : __( 'Applied value', 'contentpulse-ai-auditor' ) ), after )
				)
			);
			if ( item.fix.field === 'image_alt' && item.fix.target ) {
				body.append( h( 'p', { class: 'aica-small aica-muted', style: 'margin-top:6px' }, sprintf( __( 'Image: %s', 'contentpulse-ai-auditor' ), item.fix.target.split( '/' ).pop() ) ) );
			}
		}

		const actions = h( 'div', { class: 'aica-finding__actions' } );
		if ( item.status === 'open' && item.fix ) {
			actions.append( button( __( 'Apply fix', 'contentpulse-ai-auditor' ), { icon: 'check', variant: 'primary', onClick: ( e ) => confirmApply( item, editor ? editor.value : item.fix.value, e.currentTarget ) } ) );
		}
		if ( item.status === 'fixed' && item.can_undo ) {
			actions.append( button( __( 'Undo fix', 'contentpulse-ai-auditor' ), { icon: 'undo', onClick: ( e ) => findingAction( item, 'undo', e.currentTarget ) } ) );
		}
		if ( item.status === 'open' ) {
			actions.append( button( __( 'Dismiss', 'contentpulse-ai-auditor' ), { icon: 'dismiss', onClick: ( e ) => findingAction( item, 'dismiss', e.currentTarget ) } ) );
		}
		if ( item.status === 'dismissed' ) {
			actions.append( button( __( 'Reopen', 'contentpulse-ai-auditor' ), { icon: 'refresh', onClick: ( e ) => findingAction( item, 'reopen', e.currentTarget ) } ) );
		}
		if ( item.edit_url ) {
			actions.append( h( 'a', { class: 'aica-btn aica-btn--ghost', href: item.edit_url }, icon( 'edit' ), __( 'Edit post', 'contentpulse-ai-auditor' ) ) );
		}
		if ( item.view_url ) {
			actions.append( h( 'a', { class: 'aica-btn aica-btn--ghost', href: item.view_url, target: '_blank', rel: 'noopener' }, icon( 'external' ), __( 'View', 'contentpulse-ai-auditor' ), h( 'span', { class: 'screen-reader-text' }, __( '(opens in a new tab)', 'contentpulse-ai-auditor' ) ) ) );
		}
		body.append( actions );
		article.append( body );
		return article;
	}

	function confirmApply( item, value, btn ) {
		const dlg = h( 'dialog', { class: 'aica-dialog', 'aria-labelledby': 'aica-dlg-title' } );
		const close = () => {
			dlg.close();
			dlg.remove();
			btn.focus();
		};
		dlg.append(
			h(
				'div',
				{ class: 'aica-dialog__inner' },
				h( 'h2', { id: 'aica-dlg-title' }, __( 'Apply this fix?', 'contentpulse-ai-auditor' ) ),
				h( 'p', null, sprintf( __( 'This updates the %1$s of “%2$s”. A revision is saved and you can undo it from this screen.', 'contentpulse-ai-auditor' ), ( LABELS[ item.fix.field ] || item.fix.field ).toLowerCase(), item.post_title ) ),
				h( 'div', { class: 'aica-diff' }, h( 'div', { class: 'aica-diff__side aica-diff__side--before' }, h( 'div', { class: 'aica-diff__label' }, __( 'Current', 'contentpulse-ai-auditor' ) ), item.fix.current || h( 'span', { class: 'aica-diff__empty' }, __( '(empty)', 'contentpulse-ai-auditor' ) ) ), h( 'div', { class: 'aica-diff__side aica-diff__side--after' }, h( 'div', { class: 'aica-diff__label' }, __( 'New', 'contentpulse-ai-auditor' ) ), value ) ),
				h(
					'div',
					{ class: 'aica-dialog__actions' },
					button( __( 'Cancel', 'contentpulse-ai-auditor' ), { onClick: close } ),
					button( __( 'Apply fix', 'contentpulse-ai-auditor' ), {
						variant: 'primary',
						icon: 'check',
						onClick: async ( e ) => {
							await busy( e.currentTarget, () => findingAction( item, 'apply', null, { value } ) );
							close();
						},
					} )
				)
			)
		);
		dlg.addEventListener( 'cancel', ( e ) => {
			e.preventDefault();
			close();
		} );
		root.append( dlg );
		dlg.showModal();
	}

	async function findingAction( item, action, btn, data ) {
		const run = async () => {
			try {
				const res = await api( '/findings/' + item.id + '/' + action, { method: 'POST', data: data || {} } );
				const idx = state.findings.items.findIndex( ( x ) => x.id === item.id );
				if ( idx >= 0 ) {
					state.findings.items[ idx ] = res.finding;
				}
				if ( state.findings.scan ) {
					state.findings.scan.stats = res.stats;
				}
				const msgs = { apply: __( 'Fix applied.', 'contentpulse-ai-auditor' ), undo: __( 'Fix undone.', 'contentpulse-ai-auditor' ), dismiss: __( 'Finding dismissed.', 'contentpulse-ai-auditor' ), reopen: __( 'Finding reopened.', 'contentpulse-ai-auditor' ) };
				toast( msgs[ action ], action === 'apply' ? { action: { label: __( 'Undo', 'contentpulse-ai-auditor' ), run: () => findingAction( res.finding, 'undo' ) } } : action === 'dismiss' ? { action: { label: __( 'Undo', 'contentpulse-ai-auditor' ), run: () => findingAction( res.finding, 'reopen' ) } } : {} );
				announce( msgs[ action ] + ' ' + sprintf( __( 'Health score is now %d.', 'contentpulse-ai-auditor' ), res.stats.score ) );
				renderFindings();
				const head = document.getElementById( 'aica-finding-' + item.id + '-head' );
				if ( head ) {
					head.focus();
				}
				loadDashboard();
			} catch ( e ) {
				toast( errorMessage( e ), { error: true } );
			}
		};
		return btn ? busy( btn, run ) : run();
	}

	/* ------------------------------------------------------------------ */
	/* Activity                                                           */
	/* ------------------------------------------------------------------ */

	let activityCache = { id: 0, events: [], scan: null };
	async function renderActivity() {
		const p = panels.activity;
		const history = ( state.dashboard && state.dashboard.history ) || [];
		const id = state.activityScan || ( state.running ? state.running.scan.id : history[ 0 ] && history[ 0 ].id );
		if ( ! id ) {
			clear( p ).append( h( 'div', { class: 'aica-card aica-empty--small' }, icon( 'message' ), h( 'p', null, __( 'Agent activity appears here once you run an audit — every tool call Claude makes, in order.', 'contentpulse-ai-auditor' ) ) ) );
			return;
		}
		let events;
		let scan;
		if ( state.running && state.running.scan.id === id ) {
			events = state.running.events;
			scan = state.running.scan;
		} else {
			if ( activityCache.id !== id ) {
				try {
					const res = await api( '/scans/' + id );
					activityCache = { id, events: res.events, scan: res.scan };
				} catch ( e ) {
					clear( p ).append( h( 'p', { role: 'alert' }, errorMessage( e ) ) );
					return;
				}
			}
			events = activityCache.events;
			scan = activityCache.scan;
		}
		const picker = h( 'select', { id: 'aica-activity-scan', onChange: ( e ) => { state.activityScan = Number( e.target.value ); renderActivity(); } } );
		history.forEach( ( s ) => picker.append( h( 'option', { value: String( s.id ), selected: s.id === id }, sprintf( '#%1$d · %2$s · %3$s', s.id, s.mode === 'ai' ? s.model_label : LABELS.quick, ago( s.created_at ) ) ) ) );
		const calls = events.filter( ( e ) => e.type === 'tool_call' ).length;
		clear( p ).append(
			h(
				'div',
				{ class: 'aica-card' },
				h(
					'div',
					{ class: 'aica-card__head' },
					h( 'h2', { class: 'aica-card__title' }, icon( 'tool' ), __( 'Agent activity', 'contentpulse-ai-auditor' ), ' ', statusBadge( scan.status ) ),
					h( 'div', { class: 'aica-field' }, h( 'label', { for: 'aica-activity-scan' }, __( 'Scan', 'contentpulse-ai-auditor' ) ), picker )
				),
				h( 'p', { class: 'aica-muted aica-small' }, scan.mode === 'ai' ? sprintf( __( '%1$d tool calls over %2$d turns · %3$s tokens (%4$s cached) · %5$s', 'contentpulse-ai-auditor' ), calls, scan.iterations, num( scan.tokens ), num( scan.cache_read ), money( scan.cost_usd ) ) : sprintf( __( '%d items checked by rules', 'contentpulse-ai-auditor' ), scan.posts_scanned ) ),
				timeline( events, false )
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Connect (MCP)                                                      */
	/* ------------------------------------------------------------------ */

	function codeBlock( text, label ) {
		const pre = h( 'pre', { tabindex: '0', 'aria-label': label }, text );
		const copy = button( __( 'Copy', 'contentpulse-ai-auditor' ), {
			size: 'sm',
			icon: 'copy',
			onClick: async ( e ) => {
				try {
					await navigator.clipboard.writeText( pre.textContent );
					e.currentTarget.lastChild.textContent = __( 'Copied', 'contentpulse-ai-auditor' );
					announce( __( 'Copied to clipboard.', 'contentpulse-ai-auditor' ) );
				} catch ( err ) {
					toast( __( 'Copy failed — select the text manually.', 'contentpulse-ai-auditor' ), { error: true } );
				}
			},
		} );
		return h( 'div', { class: 'aica-code' }, pre, copy );
	}

	function renderConnect() {
		const p = clear( panels.connect );
		const auth = { value: '<BASE64 of username:application-password>' };
		const snippets = h( 'div', { class: 'aica-stack' } );
		const renderSnippets = () => {
			clear( snippets ).append(
				h( 'h3', null, __( 'Claude Code', 'contentpulse-ai-auditor' ) ),
				codeBlock( 'claude mcp add --transport http content-auditor ' + cfg.mcpUrl + ' \\\n  --header "Authorization: Basic ' + auth.value + '"', __( 'Claude Code command', 'contentpulse-ai-auditor' ) ),
				h( 'h3', null, __( 'Claude Desktop (claude_desktop_config.json)', 'contentpulse-ai-auditor' ) ),
				codeBlock(
					JSON.stringify( { mcpServers: { 'content-auditor': { command: 'npx', args: [ '-y', 'mcp-remote', cfg.mcpUrl, '--header', 'Authorization:${AUTH}' ], env: { AUTH: 'Basic ' + auth.value } } } }, null, 2 ),
					__( 'Claude Desktop configuration', 'contentpulse-ai-auditor' )
				),
				h( 'p', { class: 'aica-small aica-muted' }, __( 'Then ask Claude: “Audit my site for accessibility issues and record what you find.” Findings from external agents show up in your Findings tab.', 'contentpulse-ai-auditor' ) )
			);
		};
		renderSnippets();

		const appPw = h( 'input', { type: 'password', id: 'aica-app-pw', autocomplete: 'off', spellcheck: 'false', class: 'regular-text', placeholder: 'xxxx xxxx xxxx xxxx xxxx xxxx' } );
		const user = h( 'input', { type: 'text', id: 'aica-app-user', value: cfg.userLogin, autocomplete: 'off', class: 'regular-text' } );

		const tbody = h( 'tbody' );
		cfg.tools.forEach( ( t ) =>
			tbody.append(
				h(
					'tr',
					null,
					h( 'td', null, h( 'code', null, t.name ) ),
					h( 'td', null, t.description ),
					h( 'td', null, t.destructive ? badge( cfg.mcpAllowFixes ? 'medium' : 'dismissed', cfg.mcpAllowFixes ? __( 'Writes content', 'contentpulse-ai-auditor' ) : __( 'Disabled', 'contentpulse-ai-auditor' ), 'edit' ) : t.readonly ? badge( 'fixed', __( 'Read-only', 'contentpulse-ai-auditor' ), 'check' ) : badge( 'low', __( 'Writes findings only', 'contentpulse-ai-auditor' ), 'flag' ) )
				)
			)
		);

		p.append(
			h(
				'div',
				{ class: 'aica-grid aica-grid--2' },
				h(
					'div',
					{ class: 'aica-card aica-stack' },
					h( 'h2', { class: 'aica-card__title' }, icon( 'plug' ), __( 'Use these audit tools from any MCP client', 'contentpulse-ai-auditor' ) ),
					h( 'p', null, h( 'span', { class: 'aica-status-dot' + ( cfg.mcpEnabled ? ' aica-status-dot--on' : '' ), 'aria-hidden': 'true' } ), ' ', cfg.mcpEnabled ? __( 'MCP endpoint is enabled.', 'contentpulse-ai-auditor' ) : __( 'MCP endpoint is disabled.', 'contentpulse-ai-auditor' ), ' ', ! cfg.mcpEnabled ? h( 'a', { href: cfg.settingsUrl }, __( 'Enable it in Settings', 'contentpulse-ai-auditor' ) ) : null ),
					codeBlock( cfg.mcpUrl, __( 'MCP endpoint URL', 'contentpulse-ai-auditor' ) ),
					h(
						'ol',
						{ class: 'aica-steps' },
						h( 'li', null, h( 'a', { href: cfg.appPasswordsUrl }, __( 'Create an Application Password', 'contentpulse-ai-auditor' ) ), ' ', __( 'for an administrator account.', 'contentpulse-ai-auditor' ) ),
						h( 'li', null, __( 'Optionally build the auth header here — it is computed in your browser and never sent anywhere:', 'contentpulse-ai-auditor' ) ),
						h( 'li', null, __( 'Paste a config below into your client.', 'contentpulse-ai-auditor' ) )
					),
					h(
						'div',
						{ class: 'aica-inline-form' },
						h( 'div', { class: 'aica-field' }, h( 'label', { for: 'aica-app-user' }, __( 'Username', 'contentpulse-ai-auditor' ) ), user ),
						h( 'div', { class: 'aica-field' }, h( 'label', { for: 'aica-app-pw' }, __( 'Application Password', 'contentpulse-ai-auditor' ) ), appPw ),
						button( __( 'Fill snippets', 'contentpulse-ai-auditor' ), {
							onClick: () => {
								const raw = user.value.trim() + ':' + appPw.value.trim();
								auth.value = window.btoa( unescape( encodeURIComponent( raw ) ) );
								appPw.value = '';
								renderSnippets();
								announce( __( 'Snippets updated with your credentials.', 'contentpulse-ai-auditor' ) );
							},
						} )
					),
					snippets
				),
				h(
					'div',
					{ class: 'aica-card' },
					h( 'h2', { class: 'aica-card__title' }, icon( 'tool' ), __( 'Tools exposed', 'contentpulse-ai-auditor' ) ),
					h( 'p', { class: 'aica-muted aica-small' }, __( 'The same tools power the built-in Claude agent. Every call is schema-validated and capability-checked.', 'contentpulse-ai-auditor' ), cfg.abilitiesApi ? ' ' + __( 'They are also registered as WordPress Abilities (contentpulse-ai-auditor/*), so the official MCP Adapter can serve them too.', 'contentpulse-ai-auditor' ) : '' ),
					h( 'div', { class: 'aica-table-wrap' }, h( 'table', { class: 'aica-table' }, h( 'thead', null, h( 'tr', null, h( 'th', { scope: 'col' }, __( 'Tool', 'contentpulse-ai-auditor' ) ), h( 'th', { scope: 'col' }, __( 'What it does', 'contentpulse-ai-auditor' ) ), h( 'th', { scope: 'col' }, __( 'Access', 'contentpulse-ai-auditor' ) ) ) ), tbody ) )
				)
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Settings: Test connection                                          */
	/* ------------------------------------------------------------------ */

	const testBtn = document.getElementById( 'aica-test-connection' );
	if ( testBtn ) {
		const out = document.getElementById( 'aica-test-result' );
		testBtn.addEventListener( 'click', () =>
			busy( testBtn, async () => {
				out.className = '';
				out.textContent = __( 'Testing…', 'contentpulse-ai-auditor' );
				try {
					const r = await api( '/test-connection', { method: 'POST' } );
					out.className = 'is-ok';
					out.textContent = sprintf( __( 'Connected ✓ (%s)', 'contentpulse-ai-auditor' ), r.model );
				} catch ( e ) {
					out.className = 'is-error';
					out.textContent = errorMessage( e );
				}
			} )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Boot                                                               */
	/* ------------------------------------------------------------------ */

	renderHeader();
	const initial = root.dataset.initialTab || 'dashboard';
	loadDashboard().then( () => {
		if ( initial !== 'dashboard' && initial !== 'settings' ) {
			selectTab( initial );
		}
	} );
} )();
