<?php namespace ProcessWire;

/**
 * ProcessCompass
 *
 * Admin UI for Compass heatmap analytics.
 * Two-panel layout: page list (sidebar) + iframe with canvas overlay (main).
 *
 * @author Maxim Semenov <maxim@smnv.org> (smnv.org)
 * @version 1.1.0
 */
class ProcessCompass extends Process implements Module {

	public static function getModuleInfo(): array {
		return [
			'title'    => 'Compass',
			'summary'  => 'Heatmap viewer — clicks, scroll depth, rage clicks, mouse movement.',
			'version'  => 110,
			'author'   => 'Maxim Semenov',
			'href'     => 'https://smnv.org',
			'icon'     => 'crosshairs',
			'singular' => true,
			'autoload' => false,
			'requires' => ['Compass'],
			'page'     => [
				'name'   => 'compass',
				'title'  => 'Compass',
				'parent' => 'setup',
			],
		];
	}

	public function init(): void {
		parent::init();
	}

	// -----------------------------------------------------------------------
	// Main execute
	// -----------------------------------------------------------------------

	public function ___execute(): string {
		return $this->renderViewer();
	}

	public function renderViewer(): string {
		if(!$this->wire->user->isSuperuser()) {
			throw new WirePermissionException($this->_('Access denied.'));
		}

		$urls = $this->wire->config->urls->siteModules . 'Compass/';

		$cfg = json_encode([
			'dataUrl'  => $this->wire->config->urls->root . Compass::ENDPOINT_DATA,
			'nonce'    => $this->wire->session->CSRF->getTokenValue(),
			'nonceKey' => $this->wire->session->CSRF->getTokenName(),
		], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);

		$v          = Compass::getModuleInfo()['version'];
		$heatmapUrl = $urls . 'lib/heatmap.min.js';
		$viewerUrl  = $urls . 'js/viewer.js?v=' . $v;
		$cssUrl     = $urls . 'css/viewer.css?v=' . $v;

		$this->wire->config->styles->add($cssUrl);

		$html  = $this->renderLayout($this->getTrackedPages());

		// Inline scripts at end of output — guaranteed to run after viewer markup exists
		// $config->scripts->add() runs in <head> before PW AJAX injects content
		$html .= <<<HTML
<script>window.__compassViewer = {$cfg};</script>
<script src="{$heatmapUrl}"></script>
<script src="{$viewerUrl}"></script>
HTML;

		return $html;
	}

	// -----------------------------------------------------------------------
	// Layout
	// -----------------------------------------------------------------------

	protected function renderLayout(array $pages): string {
		$pageList = $this->renderPageList($pages);
		$toolbar  = $this->renderToolbar();

		return <<<HTML
<div id="compass-wrap">

  <aside id="compass-sidebar">
	<div class="compass-sidebar-head">
	  <span class="compass-logo">
		<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
		  stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
		  <circle cx="12" cy="12" r="10"/>
		  <polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/>
		</svg>
	  </span>
	  <span class="compass-logo-text">Compass</span>
	</div>

	<div class="compass-sidebar-search">
	  <input type="search" id="compass-search" placeholder="Filter pages…" autocomplete="off">
	</div>

	<nav id="compass-page-list">
	  {$pageList}
	</nav>
  </aside>

  <main id="compass-main">

	<div id="compass-empty-state">
	  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
		stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
		<circle cx="12" cy="12" r="10"/>
		<polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/>
	  </svg>
	  <p>Select a page to view its heatmap</p>
	</div>

	<div id="compass-viewer" style="display:none">
	  {$toolbar}

	  <div id="compass-iframe-wrap">
		<iframe id="compass-iframe" src="about:blank"></iframe>
		<canvas id="compass-canvas"></canvas>

		<div id="compass-scroll-bar" style="display:none">
		  <div class="csb-label">Scroll depth</div>
		  <div class="csb-track"><div class="csb-fill" id="compass-scroll-fill"></div></div>
		  <div class="csb-pct" id="compass-scroll-pct">0%</div>
		</div>

		<div id="compass-loading"><div class="compass-spinner"></div></div>
	  </div>

	  <div id="compass-stats-bar">
		<span id="cstat-total">— events</span>
		<span id="cstat-sessions">— sessions</span>
		<span id="cstat-devices" class="cstat-devices" title=""></span>
		<span id="cstat-period"></span>
		<div id="compass-export-btn" class="ui-button ui-state-default">
		  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
			stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
			<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
			<polyline points="7 10 12 15 17 10"/>
			<line x1="12" y1="15" x2="12" y2="3"/>
		  </svg>
		  Export PNG
		</div>
	  </div>
	</div>

  </main>
</div>
HTML;
	}

	// -----------------------------------------------------------------------
	// Toolbar
	// -----------------------------------------------------------------------

	protected function renderToolbar(): string {
		return <<<HTML
<div id="compass-toolbar">

  <div class="ct-group">
	<label class="ct-label">Type</label>
	<div class="ct-tabs" id="compass-type-tabs">
	  <div class="ct-tab active" data-type="click">Clicks</div>
	  <div class="ct-tab" data-type="move">Movement</div>
	  <div class="ct-tab" data-type="rage">Rage</div>
	  <div class="ct-tab" data-type="scroll">Scroll</div>
	</div>
  </div>

  <div class="ct-group">
	<label class="ct-label">Device</label>
	<select id="compass-device" class="ui-state-default">
	  <option value="">All devices</option>
	  <option value="desktop">Desktop</option>
	  <option value="mobile">Mobile</option>
	  <option value="tablet">Tablet</option>
	</select>
  </div>

  <div class="ct-group">
	<label class="ct-label">Period</label>
	<select id="compass-days" class="ui-state-default">
	  <option value="7">Last 7 days</option>
	  <option value="30" selected>Last 30 days</option>
	  <option value="90">Last 90 days</option>
	  <option value="365">Last year</option>
	</select>
  </div>

  <div class="ct-group ct-group--right">
	<span id="compass-page-url" class="ct-url"></span>
  </div>

</div>
HTML;
	}

	// -----------------------------------------------------------------------
	// Page list
	// -----------------------------------------------------------------------

	protected function renderPageList(array $pages): string {
		if(empty($pages)) {
			return '<div class="compass-no-data">No tracking data yet.</div>';
		}

		$out = '';
		foreach($pages as $p) {
			$id      = (int) $p['page_id'];
			$url     = $this->wire->sanitizer->entities($p['url']);
			$title   = $this->wire->sanitizer->entities($p['title'] ?: $p['url']);
			$display = number_format((int) $p['total']);

			$out .= <<<HTML
<div class="compass-page-item" data-page-id="{$id}" data-url="{$url}" title="{$url}">
  <span class="cpi-title">{$title}</span>
  <span class="cpi-url">{$url}</span>
  <span class="cpi-count">{$display}</span>
</div>
HTML;
		}

		return $out;
	}

	// -----------------------------------------------------------------------
	// Data: pages with event counts
	// -----------------------------------------------------------------------

	protected function getTrackedPages(): array {
		$db    = $this->wire->database;
		$table = Compass::TABLE;

		$stmt = $db->prepare("
			SELECT page_id, COUNT(*) as total
			FROM `{$table}`
			GROUP BY page_id
			ORDER BY total DESC
			LIMIT 200
		");
		$stmt->execute();
		$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

		if(empty($rows)) return [];

		// Batch-load all pages in one query instead of one-per-row
		$ids     = implode('|', array_map('intval', array_column($rows, 'page_id')));
		$objects = $this->wire->pages->find("id=$ids, include=all");

		$pagesById = [];
		foreach($objects as $p) {
			$pagesById[$p->id] = $p;
		}

		$result = [];
		foreach($rows as $row) {
			$page = $pagesById[(int) $row['page_id']] ?? null;
			if(!$page || !$page->id) continue;
			$result[] = [
				'page_id' => $row['page_id'],
				'total'   => $row['total'],
				'title'   => $page->title ?: $page->name,
				'url'     => $page->httpUrl,
			];
		}

		return $result;
	}

	public function ___install(): void {
		// Clean up any compass-N duplicates left by failed previous installs
		$dupes = $this->wire->pages->find('template=admin, name^=compass, parent.name=setup');
		foreach($dupes as $dupe) {
			if($dupe->name === 'compass') continue;
			$this->wire->pages->delete($dupe);
		}

		parent::___install();
	}

	public function ___uninstall(): void {
		// Explicitly delete the admin page so reinstall starts clean
		$page = $this->wire->pages->get('template=admin, name=compass, parent.name=setup');
		if($page->id) {
			$this->wire->pages->delete($page);
		}

		parent::___uninstall();
	}
}
