/**
 * Compass — tracker.js
 *
 * Tracks clicks, scroll depth, rage clicks and mouse movement.
 * Config is injected server-side as window.__compass.
 *
 * No dependencies. ~3kb minified.
 */
(function () {
	'use strict';

	const cfg = window.__compass;
	if (!cfg || !cfg.pageId) return;

	// -------------------------------------------------------------------------
	// State
	// -------------------------------------------------------------------------

	const queue    = [];   // pending events
	let flushing   = false;
	let vpWidth    = window.innerWidth;

	// Rage click detection
	const rageBuffer = [];  // { x, y, ts }
	const RAGE_THRESHOLD = 3;
	const RAGE_RADIUS    = 30;
	const RAGE_WINDOW_MS = 700;

	// Scroll depth
	let lastScrollPct = -1;

	// Mouse move batch
	const moveBuffer = [];

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	function throttle(fn, ms) {
		let last = 0;
		return function (...args) {
			const now = Date.now();
			if (now - last < ms) return;
			last = now;
			fn.apply(this, args);
		};
	}

	function scrollPercent() {
		const el     = document.documentElement;
		const total  = el.scrollHeight - el.clientHeight;
		if (total <= 0) return 100;
		return Math.min(100, Math.round((window.scrollY / total) * 100));
	}

	function push(event) {
		queue.push(event);
	}

	// -------------------------------------------------------------------------
	// Flush — sendBeacon with fetch fallback
	// -------------------------------------------------------------------------

	function flush() {
		if (flushing || queue.length === 0) return;

		// Drain move buffer into queue before flush
		drainMoveBuffer();

		if (queue.length === 0) return;

		flushing = true;

		// Snapshot one server-sized batch — keep it until confirmed sent
		const maxBatch = cfg.maxBatch || 300;
		const events  = queue.splice(0, maxBatch);
		const payload = JSON.stringify({
			page_id:    cfg.pageId,
			viewport_w: vpWidth,
			events:     events,
		});

		const url  = cfg.endpoint;
		const blob = new Blob([payload], { type: 'application/json' });

		if (navigator.sendBeacon) {
			const sent = navigator.sendBeacon(url, blob);
			if (sent) {
				// Browser accepted the beacon — events are on their way
				flushing = false;
				return;
			}
			// sendBeacon returned false (queue full) — restore events and fall through
			queue.unshift(...events);
		}

		// Fallback to fetch (keepalive)
		fetch(url, {
			method:    'POST',
			body:      payload,
			headers:   { 'Content-Type': 'application/json' },
			keepalive: true,
		})
			.catch(function () {
				// Restore events so next flush can retry
				queue.unshift(...events);
			})
			.finally(function () { flushing = false; });
	}

	// -------------------------------------------------------------------------
	// Clicks
	// -------------------------------------------------------------------------

	function initClicks() {
		if (!cfg.trackClicks && !cfg.trackRage) return;

		document.addEventListener('mousedown', function (e) {
			const x = Math.round(e.pageX);
			const y = Math.round(e.pageY);

			if (cfg.trackClicks) {
				push({ type: 'click', x, y });
			}

			if (cfg.trackRage) {
				trackRage(x, y);
			}
		}, { passive: true });

		// Touch support
		document.addEventListener('touchstart', function (e) {
			if (!e.touches.length) return;
			const t = e.touches[0];
			const x = Math.round(t.pageX);
			const y = Math.round(t.pageY);

			if (cfg.trackClicks) {
				push({ type: 'click', x, y });
			}

			if (cfg.trackRage) {
				trackRage(x, y);
			}
		}, { passive: true });
	}

	// -------------------------------------------------------------------------
	// Rage clicks
	// -------------------------------------------------------------------------

	function trackRage(x, y) {
		const now = Date.now();

		// Prune old entries outside time window
		while (rageBuffer.length && now - rageBuffer[0].ts > RAGE_WINDOW_MS) {
			rageBuffer.shift();
		}

		rageBuffer.push({ x, y, ts: now });

		if (rageBuffer.length < RAGE_THRESHOLD) return;

		// Check if all buffered clicks are within radius of the first
		const first = rageBuffer[0];
		const allClose = rageBuffer.every(function (pt) {
			const dx = pt.x - first.x;
			const dy = pt.y - first.y;
			return Math.sqrt(dx * dx + dy * dy) <= RAGE_RADIUS;
		});

		if (allClose) {
			push({ type: 'rage', x: first.x, y: first.y });
			rageBuffer.length = 0;  // reset after detection
		}
	}

	// -------------------------------------------------------------------------
	// Scroll depth
	// -------------------------------------------------------------------------

	function initScroll() {
		if (!cfg.trackScroll) return;

		const onScroll = throttle(function () {
			const pct = scrollPercent();

			// Emit on every 10% milestone crossed
			const bucket = Math.floor(pct / 10) * 10;
			if (bucket !== lastScrollPct) {
				lastScrollPct = bucket;
				push({ type: 'scroll', scroll_pct: bucket });
			}
		}, 500);

		window.addEventListener('scroll', onScroll, { passive: true });
	}

	// -------------------------------------------------------------------------
	// Mouse movement
	// -------------------------------------------------------------------------

	function initMove() {
		if (!cfg.trackMove) return;

		const onMove = throttle(function (e) {
			moveBuffer.push({
				x: Math.round(e.pageX),
				y: Math.round(e.pageY),
			});

			if (moveBuffer.length >= cfg.moveBatch) {
				drainMoveBuffer();
			}
		}, cfg.moveThrottle || 100);

		document.addEventListener('mousemove', onMove, { passive: true });
	}

	function drainMoveBuffer() {
		if (!moveBuffer.length) return;
		const pts = moveBuffer.splice(0);
		pts.forEach(function (pt) {
			push({ type: 'move', x: pt.x, y: pt.y });
		});
	}

	// -------------------------------------------------------------------------
	// Viewport resize
	// -------------------------------------------------------------------------

	function initResize() {
		window.addEventListener('resize', throttle(function () {
			vpWidth = window.innerWidth;
		}, 300), { passive: true });
	}

	// -------------------------------------------------------------------------
	// Periodic beacon + page hide flush
	// -------------------------------------------------------------------------

	function initFlush() {
		const interval = cfg.beaconInterval || 5000;

		setInterval(flush, interval);

		// Flush on tab close / navigation
		document.addEventListener('visibilitychange', function () {
			if (document.visibilityState === 'hidden') {
				drainMoveBuffer();
				flush();
			}
		});

		window.addEventListener('pagehide', function () {
			drainMoveBuffer();
			flush();
		});
	}

	// -------------------------------------------------------------------------
	// Boot
	// -------------------------------------------------------------------------

	function boot() {
		initClicks();
		initScroll();
		initMove();
		initResize();
		initFlush();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}

})();
