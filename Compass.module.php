<?php namespace ProcessWire;

/**
 * Compass
 *
 * Heatmap analytics for ProcessWire — tracks clicks, scroll depth,
 * rage clicks and mouse movement per page.
 *
 * @author Maxim Semenov <maxim@smnv.org> (smnv.org)
 * @version 1.0.0
 * @license MIT
 */
class Compass extends WireData implements Module, ConfigurableModule {

	public static function getModuleInfo(): array {
		return [
			'title'    => 'Compass',
			'summary'  => 'Heatmap analytics: clicks, scroll depth, rage clicks and mouse movement.',
			'version'  => 100,
			'author'   => 'Maxim Semenov',
			'href'     => 'https://smnv.org',
			'singular' => true,
			'autoload' => true,
			'icon'     => 'crosshairs',
			'requires' => ['ProcessWire>=3.0.0', 'PHP>=8.0.0', 'LazyCron'],
			'installs' => ['ProcessCompass'],
		];
	}

	// -------------------------------------------------------------------------
	// Constants
	// -------------------------------------------------------------------------

	const TABLE = 'compass_events';

	const ENDPOINT_TRACK = 'compass-track';
	const ENDPOINT_DATA  = 'compass-data';

	const EVENT_CLICK  = 'click';
	const EVENT_SCROLL = 'scroll';
	const EVENT_MOVE   = 'move';
	const EVENT_RAGE   = 'rage';

	const RATE_LIMIT_WINDOW      = 60;   // seconds
	const RATE_LIMIT_MAX         = 300;  // events per window per session
	const MAX_EVENTS_PER_REQUEST = 300;

	// -------------------------------------------------------------------------
	// Default config
	// -------------------------------------------------------------------------

	public static function getDefaultConfig(): array {
		return [
			'track_clicks'  => 1,
			'track_scroll'  => 1,
			'track_move'    => 1,
			'track_rage'    => 1,
			'exclude_roles' => ['superuser'],
			'exclude_templates' => [],
			'data_retention_days' => 90,
			'move_throttle_ms'    => 100,
			'move_batch_size'     => 50,
			'beacon_interval_ms'  => 5000,
		];
	}

	// -------------------------------------------------------------------------
	// Init
	// -------------------------------------------------------------------------

	public function init(): void {
		// Load API class
		require_once __DIR__ . '/CompassAPI.php';

		// Inject tracker on frontend
		$this->addHookAfter('Page::render', $this, 'hookInjectTracker');

		// Handle AJAX endpoints
		$this->addHookBefore('ProcessPageView::execute', $this, 'hookHandleEndpoints');

		// Remove X-Frame-Options for superusers (needed for iframe viewer)
		$this->addHookAfter('ProcessPageView::execute', $this, 'hookAllowIframe');

		// Prune old events once per day
		$this->addHook('LazyCron::everyDay', $this, 'pruneOldEvents');
	}

	// -------------------------------------------------------------------------
	// Hook: inject tracker script into frontend pages
	// -------------------------------------------------------------------------

	public function hookInjectTracker(HookEvent $event): void {
		/** @var Page $page */
		$page = $event->object;

		// Skip admin, 404, trash, and excluded templates
		if($page->template->flags & Template::flagSystem) return;
		if($page->id === $this->wire->config->http404PageID) return;
		if($page->template->name === 'admin') return;
		if($page->isTrash()) return;

		$excludedTemplates = $this->parseConfigList($this->get('exclude_templates'));
		if(in_array($page->template->name, $excludedTemplates)) return;

		// Skip excluded roles
		$user = $this->wire->user;
		$excludedRoles = $this->parseConfigList($this->get('exclude_roles')) ?: ['superuser'];
		foreach($excludedRoles as $role) {
			if($user->hasRole($role)) return;
		}

		$config = $this->buildTrackerConfig($page);
		$configJson = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);

		$trackerUrl = $this->wire->config->urls->siteModules . 'Compass/js/tracker.js';

		$script = <<<HTML
<script>
window.__compass = {$configJson};
</script>
<script src="{$trackerUrl}" defer></script>
HTML;

		$event->return = str_replace('</body>', $script . '</body>', $event->return);
	}

	// -------------------------------------------------------------------------
	// Hook: handle /compass-track and /compass-data endpoints
	// -------------------------------------------------------------------------

	public function hookHandleEndpoints(HookEvent $event): void {
		$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
		$requestUri = trim($requestUri, '/');

		$root = trim($this->wire->config->urls->root, '/');
		if($root && str_starts_with($requestUri, $root . '/')) {
			$requestUri = substr($requestUri, strlen($root) + 1);
		}

		if(!in_array($requestUri, [self::ENDPOINT_TRACK, self::ENDPOINT_DATA])) return;

		$event->replace = true;
		$event->return  = (new CompassAPI($this))->dispatch($requestUri);
	}

	// -------------------------------------------------------------------------
	// Hook: remove X-Frame-Options for logged-in superusers
	// -------------------------------------------------------------------------

	public function hookAllowIframe(HookEvent $event): void {
		$user = $this->wire->user;
		if(!$user->isLoggedin()) return;
		if(!$user->hasRole('superuser')) return;

		// Only relax framing on frontend pages — admin pages need no special treatment
		$page = $this->wire->page;
		if(!$page || !$page->id || $page->template->name === 'admin') return;

		if(function_exists('header_remove')) {
			header_remove('X-Frame-Options');
		}
		header('X-Frame-Options: SAMEORIGIN');
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	protected function buildTrackerConfig(Page $page): array {
		return [
			'pageId'        => $page->id,
			'endpoint'      => $this->wire->config->urls->root . self::ENDPOINT_TRACK,
			'trackClicks'   => (bool) $this->get('track_clicks'),
			'trackScroll'   => (bool) $this->get('track_scroll'),
			'trackMove'     => (bool) $this->get('track_move'),
			'trackRage'     => (bool) $this->get('track_rage'),
			'moveThrottle'  => (int) $this->get('move_throttle_ms'),
			'moveBatch'     => (int) $this->get('move_batch_size'),
			'beaconInterval'=> (int) $this->get('beacon_interval_ms'),
			'maxBatch'      => self::MAX_EVENTS_PER_REQUEST,
		];
	}

	/**
	 * Parse a comma-separated config string or passthrough an array.
	 */
	protected function parseConfigList(mixed $value): array {
		if(is_array($value)) return array_filter(array_map('trim', $value));
		if(!is_string($value) || $value === '') return [];
		return array_filter(array_map('trim', explode(',', $value)));
	}

	// -------------------------------------------------------------------------
	// LazyCron: prune old events
	// -------------------------------------------------------------------------

	public function pruneOldEvents(HookEvent $event): void {
		$days = (int) $this->get('data_retention_days') ?: 90;

		// Safety floor — never delete less than 7 days of data
		if($days < 7) $days = 7;

		$cutoff = time() - ($days * 86400);
		$table  = self::TABLE;

		try {
			// Prepare once outside the loop — reuse for all batches
			$stmt = $this->wire->database->prepare("
				DELETE FROM `{$table}`
				WHERE created_at < :cutoff
				LIMIT 10000
			");

			// Delete in batches of 10,000 to avoid long table locks
			$totalDeleted = 0;
			do {
				$stmt->execute([':cutoff' => $cutoff]);
				$deleted = $stmt->rowCount();
				$totalDeleted += $deleted;
			} while($deleted === 10000);

			if($totalDeleted > 0) {
				$this->wire->log->message(
					"Compass: pruned events older than {$days} days ({$totalDeleted} rows)."
				);
			}
		} catch(\Exception $e) {
			$this->wire->log->error('Compass pruneOldEvents: ' . $e->getMessage());
		}
	}

	// -------------------------------------------------------------------------
	// Install / Uninstall
	// -------------------------------------------------------------------------

	public function ___install(): void {
		$this->createTable();
	}

	/**
	 * Add device_type column if upgrading from a version without it.
	 */
	public function ___upgrade(int $fromVersion, int $toVersion): void {
		$db    = $this->wire->database;
		$table = self::TABLE;

		if(!$this->columnExists($table, 'device_type')) {
			$db->exec("
				ALTER TABLE `{$table}`
				ADD COLUMN `device_type` ENUM('desktop','mobile','tablet') NOT NULL DEFAULT 'desktop'
				    AFTER `viewport_w`
			");
			$this->wire->log->message("Compass: added device_type column to {$table}.");
		}

		if(!$this->indexExists($table, 'idx_page_device')) {
			$db->exec("ALTER TABLE `{$table}` ADD INDEX `idx_page_device` (`page_id`, `device_type`)");
		}

		if(!$this->indexExists($table, 'idx_page_time')) {
			$db->exec("ALTER TABLE `{$table}` ADD INDEX `idx_page_time` (`page_id`, `created_at`)");
		}

		if(!$this->indexExists($table, 'idx_page_device_time')) {
			$db->exec("ALTER TABLE `{$table}` ADD INDEX `idx_page_device_time` (`page_id`, `device_type`, `created_at`)");
		}
	}

	public function ___uninstall(): void {
		// Table is intentionally left on uninstall to preserve data.
		// User can drop manually: DROP TABLE compass_events;
	}

	protected function createTable(): void {
		$db = $this->wire->database;
		$table = self::TABLE;

		$db->exec("
			CREATE TABLE IF NOT EXISTS `{$table}` (
				`id`          INT UNSIGNED      NOT NULL AUTO_INCREMENT,
				`page_id`     INT UNSIGNED      NOT NULL,
				`session_id`  VARCHAR(32)       NOT NULL,
				`type`        ENUM('click','scroll','move','rage') NOT NULL,
				`x`           SMALLINT UNSIGNED DEFAULT NULL,
				`y`           INT UNSIGNED      DEFAULT NULL,
				`scroll_pct`  TINYINT UNSIGNED  DEFAULT NULL,
				`viewport_w`  SMALLINT UNSIGNED DEFAULT NULL,
				`device_type` ENUM('desktop','mobile','tablet') NOT NULL DEFAULT 'desktop',
				`created_at`  INT UNSIGNED      NOT NULL,
				PRIMARY KEY (`id`),
				INDEX `idx_page_type_time` (`page_id`, `type`, `created_at`),
				INDEX `idx_page_device`    (`page_id`, `device_type`),
				INDEX `idx_page_time`      (`page_id`, `created_at`),
				INDEX `idx_page_device_time` (`page_id`, `device_type`, `created_at`),
				INDEX `idx_session`        (`session_id`),
				INDEX `idx_created`        (`created_at`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
		");
	}

	protected function columnExists(string $table, string $column): bool {
		$stmt = $this->wire->database->prepare("
			SELECT COUNT(*) FROM information_schema.COLUMNS
			WHERE TABLE_SCHEMA = DATABASE()
			  AND TABLE_NAME   = :table
			  AND COLUMN_NAME  = :column
		");
		$stmt->execute([':table' => $table, ':column' => $column]);
		return (bool) $stmt->fetchColumn();
	}

	protected function indexExists(string $table, string $index): bool {
		$stmt = $this->wire->database->prepare("
			SELECT COUNT(*) FROM information_schema.STATISTICS
			WHERE TABLE_SCHEMA = DATABASE()
			  AND TABLE_NAME   = :table
			  AND INDEX_NAME   = :index
		");
		$stmt->execute([':table' => $table, ':index' => $index]);
		return (bool) $stmt->fetchColumn();
	}

	// -------------------------------------------------------------------------
	// Module config fields
	// -------------------------------------------------------------------------

	public static function getModuleConfigInputfields(array $data): InputfieldWrapper {
		$modules  = wire('modules');
		$defaults = self::getDefaultConfig();
		$data     = array_merge($defaults, $data);

		$wrapper = new InputfieldWrapper();

		// --- Tracking section ---
		/** @var InputfieldFieldset $fs */
		$fs = $modules->get('InputfieldFieldset');
		$fs->label = __('Tracking');
		$fs->icon  = 'mouse-pointer';

		foreach([
			'track_clicks' => __('Track clicks'),
			'track_scroll' => __('Track scroll depth'),
			'track_move'   => __('Track mouse movement'),
			'track_rage'   => __('Track rage clicks'),
		] as $name => $label) {
			/** @var InputfieldCheckbox $f */
			$f = $modules->get('InputfieldCheckbox');
			$f->attr('name', $name);
			$f->attr('value', 1);
			$f->label = $label;
			$f->attr('checked', !empty($data[$name]) ? 'checked' : '');
			$fs->add($f);
		}

		$wrapper->add($fs);

		// --- Exclusions ---
		/** @var InputfieldFieldset $fs2 */
		$fs2 = $modules->get('InputfieldFieldset');
		$fs2->label = __('Exclusions');
		$fs2->icon  = 'ban';

		/** @var InputfieldText $f */
		$f = $modules->get('InputfieldText');
		$f->attr('name', 'exclude_roles');
		$f->label = __('Exclude roles (comma-separated)');
		$f->attr('value', implode(', ', (array) $data['exclude_roles']));
		$f->notes = __('Users with these roles will not be tracked. Default: superuser');
		$fs2->add($f);

		/** @var InputfieldText $f */
		$f = $modules->get('InputfieldText');
		$f->attr('name', 'exclude_templates');
		$f->label = __('Exclude templates (comma-separated)');
		$f->attr('value', implode(', ', (array) $data['exclude_templates']));
		$f->notes = __('Pages using these templates will not be tracked.');
		$fs2->add($f);

		$wrapper->add($fs2);

		// --- Data retention ---
		/** @var InputfieldFieldset $fs3 */
		$fs3 = $modules->get('InputfieldFieldset');
		$fs3->label = __('Data');
		$fs3->icon  = 'database';

		/** @var InputfieldInteger $f */
		$f = $modules->get('InputfieldInteger');
		$f->attr('name', 'data_retention_days');
		$f->label = __('Data retention (days)');
		$f->attr('value', $data['data_retention_days']);
		$f->notes = __('Events older than this will be pruned by LazyCron.');
		$fs3->add($f);

		$wrapper->add($fs3);

		// --- Performance ---
		/** @var InputfieldFieldset $fs4 */
		$fs4 = $modules->get('InputfieldFieldset');
		$fs4->label = __('Performance');
		$fs4->icon  = 'tachometer';

		/** @var InputfieldInteger $f */
		$f = $modules->get('InputfieldInteger');
		$f->attr('name', 'move_throttle_ms');
		$f->label = __('Mouse move throttle (ms)');
		$f->attr('value', $data['move_throttle_ms']);
		$fs4->add($f);

		/** @var InputfieldInteger $f */
		$f = $modules->get('InputfieldInteger');
		$f->attr('name', 'move_batch_size');
		$f->label = __('Mouse move batch size (points)');
		$f->attr('value', $data['move_batch_size']);
		$fs4->add($f);

		/** @var InputfieldInteger $f */
		$f = $modules->get('InputfieldInteger');
		$f->attr('name', 'beacon_interval_ms');
		$f->label = __('Beacon interval (ms)');
		$f->attr('value', $data['beacon_interval_ms']);
		$fs4->add($f);

		$wrapper->add($fs4);

		return $wrapper;
	}
}
