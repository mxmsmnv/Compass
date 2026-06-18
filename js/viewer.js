/**
 * Compass — viewer.js
 *
 * Manages the admin heatmap viewer:
 *  - Page list selection + search filter
 *  - iframe load + scroll sync
 *  - Canvas overlay via heatmap.js
 *  - Scroll depth bar
 *  - Stats bar + PNG export
 *
 * Requires: heatmap.min.js (loaded before this file)
 * Config:   window.__compassViewer (injected by ProcessCompass)
 */
(function () {
	'use strict';

	const cfg = window.__compassViewer;
	if (!cfg) return;

	// -------------------------------------------------------------------------
	// DOM refs — resolved inside boot() after DOM is ready
	// -------------------------------------------------------------------------

	let elSearch, elPageList, elEmpty, elViewer, elIframeWrap, elIframe,
		elCanvas, elLoading, elScrollBar, elScrollFill, elScrollPct,
		elTypeTabs, elDays, elDevice, elPageUrl, elExport,
		elStatTotal, elStatSess, elStatDevices, elStatPeriod;

	function resolveRefs() {
		const $ = function (id) { return document.getElementById(id); };
		elSearch     = $('compass-search');
		elPageList   = $('compass-page-list');
		elEmpty      = $('compass-empty-state');
		elViewer     = $('compass-viewer');
		elIframeWrap = $('compass-iframe-wrap');
		elIframe     = $('compass-iframe');
		elCanvas     = $('compass-canvas');
		elLoading    = $('compass-loading');
		elScrollBar  = $('compass-scroll-bar');
		elScrollFill = $('compass-scroll-fill');
		elScrollPct  = $('compass-scroll-pct');
		elTypeTabs   = $('compass-type-tabs');
		elDays       = $('compass-days');
		elDevice     = $('compass-device');
		elPageUrl    = $('compass-page-url');
		elExport     = $('compass-export-btn');
		elStatTotal   = $('cstat-total');
		elStatSess    = $('cstat-sessions');
		elStatDevices = $('cstat-devices');
		elStatPeriod  = $('cstat-period');
	}

	// -------------------------------------------------------------------------
	// State
	// -------------------------------------------------------------------------

	let currentPageId  = null;
	let currentPageUrl = null;
	let currentType    = 'click';
	let currentDays    = 30;
	let currentDevice  = '';   // '' = all devices
	let heatmapInst    = null;
	let iframeReady    = false;
	let pendingRender  = false;
	let loadToken      = 0;   // incremented on each loadIframe() call — detects stale loads
	let fetchController = null; // AbortController for in-flight data requests

	// -------------------------------------------------------------------------
	// Page list — click + search filter
	// -------------------------------------------------------------------------

	function initPageList() {
		elPageList.addEventListener('click', function (e) {
			const btn = e.target.closest('.compass-page-item');
			if (!btn) return;

			// Active state
			elPageList.querySelectorAll('.compass-page-item').forEach(function (b) {
				b.classList.remove('active');
			});
			btn.classList.add('active');

			currentPageId  = parseInt(btn.dataset.pageId, 10);
			currentPageUrl = btn.dataset.url;

			elPageUrl.textContent = currentPageUrl;
			elPageUrl.title       = currentPageUrl;

			showViewer();
			loadIframe(currentPageUrl);
		});

		elSearch.addEventListener('input', function () {
			const q = this.value.trim().toLowerCase();
			elPageList.querySelectorAll('.compass-page-item').forEach(function (btn) {
				const text = btn.textContent.toLowerCase();
				btn.style.display = (!q || text.includes(q)) ? '' : 'none';
			});
		});
	}

	// -------------------------------------------------------------------------
	// Toolbar — type tabs + period select
	// -------------------------------------------------------------------------

	function initToolbar() {
		elTypeTabs.addEventListener('click', function (e) {
			const tab = e.target.closest('.ct-tab');
			if (!tab) return;

			elTypeTabs.querySelectorAll('.ct-tab').forEach(function (t) {
				t.classList.remove('active');
			});
			tab.classList.add('active');

			currentType = tab.dataset.type;
			if (currentPageId) fetchAndRender();
		});

		elDays.addEventListener('change', function () {
			currentDays = parseInt(this.value, 10);
			if (currentPageId) fetchAndRender();
		});

		elDevice.addEventListener('change', function () {
			currentDevice = this.value;
			if (currentPageId) fetchAndRender();
		});
	}

	// -------------------------------------------------------------------------
	// Show/hide panels
	// -------------------------------------------------------------------------

	function showViewer() {
		elEmpty.style.display  = 'none';
		elViewer.style.display = 'flex';
	}

	function setLoading(on) {
		elLoading.style.display = on ? 'flex' : 'none';
	}

	// -------------------------------------------------------------------------
	// iframe management
	// -------------------------------------------------------------------------

	function loadIframe(url) {
		iframeReady   = false;
		pendingRender = true;

		// Increment token — any onload from a previous src change is now stale
		const myToken = ++loadToken;

		setLoading(true);
		clearCanvas();

		elIframe.onload = function () {
			// Discard if a newer loadIframe() call has started
			if (myToken !== loadToken) return;

			iframeReady = true;
			syncCanvasSize();

			if (pendingRender) {
				fetchAndRender();
				pendingRender = false;
			}
		};

		elIframe.src = url;
	}

	// Resize iframe and canvas to the full page content height so the
	// wrapper handles all scrolling and the canvas stays aligned with the page.
	function syncCanvasSize() {
		try {
			const iDoc  = elIframe.contentDocument || elIframe.contentWindow.document;
			const iBody = iDoc.body;
			const iHtml = iDoc.documentElement;

			const h = Math.max(
				iBody.scrollHeight, iBody.offsetHeight,
				iHtml.scrollHeight, iHtml.offsetHeight
			);
			const w = elIframeWrap.offsetWidth;

			elIframe.style.height = h + 'px';

			elCanvas.width  = w;
			elCanvas.height = h;
			elCanvas.style.width  = w + 'px';
			elCanvas.style.height = h + 'px';
		} catch (e) {
			// cross-origin fallback: match wrapper dimensions
			elCanvas.width  = elIframeWrap.offsetWidth;
			elCanvas.height = elIframeWrap.offsetHeight;
		}
	}

	// -------------------------------------------------------------------------
	// Fetch data + render heatmap
	// -------------------------------------------------------------------------

	function fetchAndRender() {
		if (!currentPageId) return;
		if (!iframeReady) { pendingRender = true; return; }

		// Abort any in-flight request — prevents stale responses overwriting fresh data
		if (fetchController) {
			fetchController.abort();
		}
		fetchController = new AbortController();
		const signal = fetchController.signal;

		setLoading(true);

		const params = new URLSearchParams({
			page_id: currentPageId,
			type:    currentType,
			days:    currentDays,
			device:  currentDevice,
		});
		if (cfg.nonceKey && cfg.nonce) {
			params.append(cfg.nonceKey, cfg.nonce);
		}

		fetch(cfg.dataUrl + '?' + params.toString(), {
			credentials: 'same-origin',
			headers:     { 'X-Requested-With': 'XMLHttpRequest' },
			signal:      signal,
		})
			.then(function (r) {
				if (!r.ok) throw new Error('HTTP ' + r.status);
				return r.json();
			})
			.then(function (res) {
				setLoading(false);
				if (!res.ok) return;

				updateStats(res.stats, res.data.length);

				if (currentType === 'scroll') {
					renderScrollBar(res.data);
				} else {
					renderHeatmap(res.data);
				}
			})
			.catch(function (err) {
				if (err.name === 'AbortError') return; // intentional cancel — ignore silently
				setLoading(false);
				console.warn('[Compass] fetch error:', err);
			});
	}

	// -------------------------------------------------------------------------
	// Heatmap rendering (click / move / rage)
	// -------------------------------------------------------------------------

	function renderHeatmap(rows) {
		elScrollBar.style.display = 'none';
		elCanvas.style.display    = 'none'; // placeholder only — heatmap.js uses its own canvas

		syncCanvasSize();
		clearCanvas();

		if (!rows.length) return;

		const w = elCanvas.width;
		const h = elCanvas.height;

		// Normalise x coords from recorded viewport_w to current canvas width
		const points = rows.map(function (row) {
			const scale = w / (parseInt(row.viewport_w, 10) || w);
			return {
				x:     Math.round(parseInt(row.x, 10) * scale),
				y:     parseInt(row.y, 10),
				value: parseInt(row.value, 10) || 1,
			};
		});

		const maxVal = points.reduce(function (m, p) { return p.value > m ? p.value : m; }, 1);

		if (heatmapInst) {
			const prev = elIframeWrap.querySelector('canvas.heatmap-canvas:not(#compass-canvas)');
			if (prev) prev.remove();
			heatmapInst = null;
		}

		// Let heatmap.js create its own canvas, then patch its internal
		// renderer to the full page height so all points are visible.
		heatmapInst = h337.create({
			container:  elIframeWrap,
			radius:     currentType === 'move' ? 15 : 25,
			maxOpacity: currentType === 'move' ? 0.6 : 0.8,
			minOpacity: 0,
			blur:       0.85,
		});

		const r = heatmapInst._renderer;
		r._width  = w;
		r._height = h;
		r.canvas.width         = w;
		r.canvas.height        = h;
		r.canvas.style.cssText = 'position:absolute;top:0;left:0;width:' + w + 'px;height:' + h + 'px;pointer-events:none;z-index:10;';
		r.shadowCanvas.width  = w;
		r.shadowCanvas.height = h;

		heatmapInst.setData({ max: maxVal, min: 0, data: points });
	}

	// -------------------------------------------------------------------------
	// Scroll depth bar
	// -------------------------------------------------------------------------

	function renderScrollBar(rows) {
		elCanvas.style.display    = 'none';
		elScrollBar.style.display = 'flex';

		if (!rows.length) {
			elScrollFill.style.width = '0%';
			elScrollPct.textContent  = '0%';
			return;
		}

		// Find deepest bucket that has at least one event
		const maxBucket = rows.reduce(function (max, row) {
			return Math.max(max, parseInt(row.scroll_pct, 10) || 0);
		}, 0);

		elScrollFill.style.width = maxBucket + '%';
		elScrollPct.textContent  = maxBucket + '%';

		// Colour gradient: green → yellow → red based on depth
		const hue = Math.round(maxBucket * 1.2);  // 0→green, 100→red-ish
		elScrollFill.style.background =
			'linear-gradient(90deg, hsl(' + (120 - hue) + ',80%,45%), hsl(' + (120 - hue * 0.5) + ',75%,50%))';
	}

	// -------------------------------------------------------------------------
	// Stats bar
	// -------------------------------------------------------------------------

	function updateStats(stats, pointCount) {
		if (!stats) return;

		elStatTotal.textContent = formatNum(stats.total) + ' events';
		elStatSess.textContent  = formatNum(stats.sessions) + ' sessions';

		// Device breakdown badges
		if (elStatDevices && stats.desktop_cnt !== undefined) {
			const desktop = parseInt(stats.desktop_cnt, 10) || 0;
			const mobile  = parseInt(stats.mobile_cnt, 10) || 0;
			const tablet  = parseInt(stats.tablet_cnt, 10) || 0;
			const total = (desktop + mobile + tablet) || 1;
			const pct = function (n) { return Math.round((n / total) * 100); };
			elStatDevices.innerHTML =
				'<span class="cstat-dev cstat-dev--desktop" title="Desktop">🖥 ' + pct(desktop) + '%</span>' +
				'<span class="cstat-dev cstat-dev--mobile"  title="Mobile">📱 '  + pct(mobile)  + '%</span>' +
				'<span class="cstat-dev cstat-dev--tablet"  title="Tablet">📟 '  + pct(tablet)  + '%</span>';
		} else if (elStatDevices) {
			elStatDevices.innerHTML = '';
		}

		if (stats.first_seen && stats.last_seen) {
			const from = new Date(stats.first_seen * 1000).toLocaleDateString();
			const to   = new Date(stats.last_seen  * 1000).toLocaleDateString();
			elStatPeriod.textContent = from + ' – ' + to;
		}
	}

	// -------------------------------------------------------------------------
	// Export PNG
	// -------------------------------------------------------------------------

	function initExport() {
		elExport.addEventListener('click', function () {
			if (!currentPageId) return;
			const canvas  = getHeatmapCanvas() || elCanvas;
			const dataUrl = canvas.toDataURL('image/png');
			const a       = document.createElement('a');
			const slug    = (currentPageUrl || 'page').replace(/[^a-z0-9]/gi, '-').replace(/-+/g, '-');
			a.href        = dataUrl;
			a.download    = 'compass-' + slug + '-' + currentType + '.png';
			a.click();
		});
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	function clearCanvas() {
		if (heatmapInst) {
			const prev = elIframeWrap.querySelector('canvas.heatmap-canvas:not(#compass-canvas)');
			if (prev) prev.remove();
			heatmapInst = null;
		}
	}

	function getHeatmapCanvas() {
		return elIframeWrap.querySelector('canvas.heatmap-canvas:not(#compass-canvas)');
	}

	function formatNum(n) {
		return parseInt(n, 10).toLocaleString();
	}

	// Resize observer: re-sync canvas when panel resizes
	function initResizeObserver() {
		if (!window.ResizeObserver) return;
		const ro = new ResizeObserver(function () {
			if (iframeReady) syncCanvasSize();
		});
		ro.observe(elIframeWrap);
	}

	// -------------------------------------------------------------------------
	// Boot
	// -------------------------------------------------------------------------

	function boot() {
		resolveRefs();

		if (!elPageList || !elIframe) {
			console.warn('[Compass] Required DOM elements not found');
			return;
		}

		initPageList();
		initToolbar();
		initExport();
		initResizeObserver();
	}

	// Scripts are inlined after HTML in ProcessCompass::___execute()
	// so DOM is always ready — no need to wait
	boot();

})();
