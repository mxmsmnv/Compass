<?php namespace ProcessWire;

/**
 * CompassAPI
 *
 * Handles the two AJAX endpoints for the Compass module:
 *   POST /compass-track  — receives batched events from tracker.js
 *   GET  /compass-data   — returns aggregated heatmap data for viewer.js
 *
 * Instantiated and called by Compass::hookHandleEndpoints().
 *
 * @author Maxim Semenov <maxim@smnv.org> (smnv.org)
 * @version 1.0.0
 */
class CompassAPI extends Wire {

	protected Compass $module;

	public function __construct(Compass $module) {
		parent::__construct();
		$this->setWire($module->wire());
		$this->module = $module;
	}

	// -------------------------------------------------------------------------
	// Dispatch
	// -------------------------------------------------------------------------

	/**
	 * Route to the correct handler and return a JSON string.
	 * Sets appropriate HTTP headers and status codes.
	 */
	public function dispatch(string $endpoint): string {
		// Buffer any accidental output before we send headers
		ob_start();

		try {
			$response = match($endpoint) {
				Compass::ENDPOINT_TRACK => $this->handleTrack(),
				Compass::ENDPOINT_DATA  => $this->handleData(),
				default                 => $this->jsonError(404, 'Not found'),
			};
		} catch(\Throwable $e) {
			$response = $this->jsonError(500, 'Internal error');
			$this->wire->log->error('CompassAPI: ' . $e->getMessage());
		}

		ob_end_clean();
		return $response;
	}

	// -------------------------------------------------------------------------
	// POST /compass-track
	// -------------------------------------------------------------------------

	protected function handleTrack(): string {
		header('Content-Type: application/json');

		if($_SERVER['REQUEST_METHOD'] !== 'POST') {
			return $this->jsonError(405, 'Method not allowed');
		}

		// Reject cross-origin requests to prevent analytics pollution
		$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
		if($origin !== '') {
			$scheme = $this->wire->config->https ? 'https' : 'http';
			$expectedOrigin = $scheme . '://' . $this->wire->config->httpHost;
			if($origin !== $expectedOrigin) {
				return $this->jsonError(403, 'Forbidden');
			}
		}

		$raw = file_get_contents('php://input');
		if($raw === false || $raw === '') {
			return $this->jsonError(400, 'Empty body');
		}

		$payload = json_decode($raw, true);
		if(!is_array($payload) || empty($payload['events']) || !is_array($payload['events'])) {
			return $this->jsonError(400, 'Invalid payload');
		}

		$eventCount = count($payload['events']);
		if($eventCount > Compass::MAX_EVENTS_PER_REQUEST) {
			return $this->jsonError(413, 'Too many events');
		}

		$pageId = (int) ($payload['page_id'] ?? 0);
		if(!$pageId) {
			return $this->jsonError(400, 'Missing page_id');
		}

		// Rate limit checked before any DB queries to prevent DoS
		$sessionId = $this->getSessionId();

		if(!$this->checkRateLimit($sessionId, $eventCount)) {
			return $this->jsonError(429, 'Rate limit exceeded');
		}

		// Validate that the page actually exists to prevent phantom data
		$page = $this->wire->pages->get($pageId);
		if(!$page->id) {
			return $this->jsonError(400, 'Unknown page');
		}

		$vpWidth    = max(0, min((int) ($payload['viewport_w'] ?? 0), 9999));
		$deviceType = $this->detectDevice($vpWidth);
		$inserted   = $this->insertEvents($payload['events'], $pageId, $sessionId, $vpWidth, $deviceType);

		return json_encode(['ok' => true, 'inserted' => $inserted]);
	}

	// -------------------------------------------------------------------------
	// GET /compass-data
	// -------------------------------------------------------------------------

	protected function handleData(): string {
		header('Content-Type: application/json');

		if(!$this->wire->user->isLoggedin()) {
			return $this->jsonError(403, 'Forbidden');
		}

		// Superuser only — same restriction as the viewer UI
		if(!$this->wire->user->isSuperuser()) {
			return $this->jsonError(403, 'Forbidden');
		}

		if(!$this->hasValidCsrfToken()) {
			return $this->jsonError(403, 'Invalid CSRF token');
		}

		$input  = $this->wire->input;
		$pageId = (int) $input->get('page_id');
		$days   = max(1, min((int) ((string) $input->get('days') ?: '30'), 365));
		$type   = $this->sanitizeType((string) $input->get('type'));
		$device = $this->sanitizeDevice((string) $input->get('device'));
		$since  = time() - ($days * 86400);

		if(!$pageId) {
			return $this->jsonError(400, 'Missing page_id');
		}

		$data  = $type === Compass::EVENT_SCROLL
			? $this->queryScrollDepth($pageId, $since, $device)
			: $this->queryHeatmapPoints($pageId, $type, $since, $device);

		$stats = $this->queryStats($pageId, $since, $device);

		return json_encode([
			'ok'     => true,
			'type'   => $type,
			'device' => $device,
			'data'   => $data,
			'stats'  => $stats,
		]);
	}

	// -------------------------------------------------------------------------
	// DB: insert events
	// -------------------------------------------------------------------------

	protected function insertEvents(array $events, int $pageId, string $sessionId, int $vpWidth, string $deviceType): int {
		$db = $this->wire->database;

		$allowedTypes = [
			Compass::EVENT_CLICK  => 'track_clicks',
			Compass::EVENT_SCROLL => 'track_scroll',
			Compass::EVENT_MOVE   => 'track_move',
			Compass::EVENT_RAGE   => 'track_rage',
		];

		$now      = time();
		$inserted = 0;

		$db->beginTransaction();
		try {
			$stmt = $db->prepare("
				INSERT INTO " . Compass::TABLE . "
					(page_id, session_id, type, x, y, scroll_pct, viewport_w, device_type, created_at)
				VALUES
					(:page_id, :session_id, :type, :x, :y, :scroll_pct, :viewport_w, :device_type, :created_at)
			");

			foreach($events as $ev) {
				if(!is_array($ev)) continue;

				$type = $ev['type'] ?? '';

				if(!array_key_exists($type, $allowedTypes)) continue;
				if(!$this->module->get($allowedTypes[$type])) continue;

				$stmt->execute([
					':page_id'     => $pageId,
					':session_id'  => $sessionId,
					':type'        => $type,
					':x'           => isset($ev['x']) ? max(0, min((int) $ev['x'], 9999)) : null,
					':y'           => isset($ev['y']) ? max(0, min((int) $ev['y'], 65535)) : null,
					':scroll_pct'  => isset($ev['scroll_pct']) ? max(0, min((int) $ev['scroll_pct'], 100)) : null,
					':viewport_w'  => $vpWidth,
					':device_type' => $deviceType,
					':created_at'  => $now,
				]);

				$inserted++;
			}

			$db->commit();
		} catch(\Throwable $e) {
			$db->rollBack();
			throw $e;
		}

		return $inserted;
	}

	// -------------------------------------------------------------------------
	// DB: queries
	// -------------------------------------------------------------------------

	protected function queryScrollDepth(int $pageId, int $since, string $device = ''): array {
		$deviceSql = $device ? "AND device_type = :device" : '';
		$stmt = $this->wire->database->prepare("
			SELECT scroll_pct, COUNT(*) as cnt
			FROM " . Compass::TABLE . "
			WHERE page_id    = :page_id
			  AND type       = 'scroll'
			  AND created_at >= :since
			  AND scroll_pct IS NOT NULL
			  {$deviceSql}
			GROUP BY scroll_pct
			ORDER BY scroll_pct ASC
		");
		$params = [':page_id' => $pageId, ':since' => $since];
		if($device) $params[':device'] = $device;
		$stmt->execute($params);
		return $stmt->fetchAll(\PDO::FETCH_ASSOC);
	}

	protected function queryHeatmapPoints(int $pageId, string $type, int $since, string $device = ''): array {
		$deviceSql = $device ? "AND device_type = :device" : '';
		$stmt = $this->wire->database->prepare("
			SELECT x, y, viewport_w, COUNT(*) as value
			FROM " . Compass::TABLE . "
			WHERE page_id    = :page_id
			  AND type       = :type
			  AND created_at >= :since
			  AND x IS NOT NULL
			  AND y IS NOT NULL
			  {$deviceSql}
			GROUP BY x, y, viewport_w
			ORDER BY value DESC
			LIMIT 5000
		");
		$params = [
			':page_id' => $pageId,
			':type'    => $type,
			':since'   => $since,
		];
		if($device) $params[':device'] = $device;
		$stmt->execute($params);
		return $stmt->fetchAll(\PDO::FETCH_ASSOC);
	}

	protected function queryStats(int $pageId, int $since, string $device = ''): array {
		$deviceSql = $device ? "AND device_type = :device" : '';
		$stmt = $this->wire->database->prepare("
			SELECT
				COUNT(*)                   as total,
				COUNT(DISTINCT session_id) as sessions,
				MIN(created_at)            as first_seen,
				MAX(created_at)            as last_seen,
				SUM(device_type = 'desktop') as desktop_cnt,
				SUM(device_type = 'mobile')  as mobile_cnt,
				SUM(device_type = 'tablet')  as tablet_cnt
			FROM " . Compass::TABLE . "
			WHERE page_id = :page_id AND created_at >= :since
			{$deviceSql}
		");
		$params = [':page_id' => $pageId, ':since' => $since];
		if($device) $params[':device'] = $device;
		$stmt->execute($params);
		return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	// -------------------------------------------------------------------------
	// Device detection
	// -------------------------------------------------------------------------

	/**
	 * Sanitize device filter param — returns empty string for "all devices".
	 */
	protected function sanitizeDevice(string $device): string {
		return in_array($device, ['desktop', 'mobile', 'tablet']) ? $device : '';
	}

	protected function detectDevice(int $vpWidth): string {
		$ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');

		// Tablet UA patterns (check before mobile — iPads match both)
		if(preg_match('/ipad|tablet|kindle|silk|playbook|(android(?!.*mobile))/i', $ua)) {
			return 'tablet';
		}

		// Mobile UA patterns
		if(preg_match('/mobile|android|iphone|ipod|blackberry|iemobile|opera mini|opera mobi|windows phone/i', $ua)) {
			return 'mobile';
		}

		// UA says desktop — use viewport as secondary signal
		if($vpWidth > 0 && $vpWidth <= 767) return 'mobile';
		if($vpWidth > 767 && $vpWidth <= 1024) return 'tablet';

		return 'desktop';
	}

	protected function sanitizeType(string $type): string {
		$type = $this->wire->sanitizer->pageName($type);
		return in_array($type, [
			Compass::EVENT_CLICK,
			Compass::EVENT_SCROLL,
			Compass::EVENT_MOVE,
			Compass::EVENT_RAGE,
		]) ? $type : Compass::EVENT_CLICK;
	}

	protected function hasValidCsrfToken(): bool {
		$csrf = $this->wire->session->CSRF;
		$name = $csrf->getTokenName();
		$expected = (string) $csrf->getTokenValue();
		$actual = (string) $this->wire->input->get($name);

		return $actual !== '' && hash_equals($expected, $actual);
	}

	protected function getSessionId(): string {
		$key = 'compass_sid';

		if(!empty($_COOKIE[$key])) {
			// Validate — must be 32 hex chars
			$sid = preg_replace('/[^a-f0-9]/', '', $_COOKIE[$key]);
			if(strlen($sid) === 32) return $sid;
		}

		$sid = bin2hex(random_bytes(16));
		setcookie($key, $sid, [
			'expires'  => time() + 86400 * 365,
			'path'     => '/',
			'httponly' => true,
			'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
			'samesite' => 'Lax',
		]);

		return $sid;
	}

	protected function checkRateLimit(string $sessionId, int $eventCount): bool {
		if(session_status() === PHP_SESSION_NONE) {
			if(!session_start()) {
				$this->wire->log->warning('Compass: session unavailable, rate limiting skipped.');
				return true;
			}
		}

		$key  = 'compass_rl_' . $sessionId;
		$now  = time();
		$data = $_SESSION[$key] ?? ['count' => 0, 'start' => $now];

		if(($now - $data['start']) > Compass::RATE_LIMIT_WINDOW) {
			$data = ['count' => 0, 'start' => $now];
		}

		$data['count'] += max(1, $eventCount);
		$_SESSION[$key] = $data;

		return $data['count'] <= Compass::RATE_LIMIT_MAX;
	}

	protected function jsonError(int $code, string $message): string {
		http_response_code($code);
		return json_encode(['ok' => false, 'error' => $message]);
	}
}
