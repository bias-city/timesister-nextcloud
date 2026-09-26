<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Controller;

use OCA\TimeSister\AppInfo\Application;
use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\Json;
use OCA\TimeSister\Service\TenantService;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * Gemeinsames aller Endpunkte: Fehler als `data = {error, message, …}`,
 * JSON-Rümpfe als Objekte (damit `{}` nicht zu `[]` wird).
 */
abstract class BaseController extends OCSController {
	/** Ein Datensatz ist höchstens 256 KB; der Rumpf darf etwas mehr haben. */
	public const MAX_RECORD_BODY = 1024 * 1024;
	public const MAX_BATCH_BODY = 50 * 1024 * 1024;

	public function __construct(
		IRequest $request,
		protected TenantService $tenants,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function run(callable $fn): DataResponse {
		try {
			$result = $fn();
			return $result instanceof DataResponse ? $result : new DataResponse($result);
		} catch (ApiException $e) {
			return new DataResponse($e->toData(), $e->getStatus());
		}
	}

	/**
	 * Der JSON-Rumpf. Art, Schlüssel und Kennung stehen im Pfad, nie im
	 * Rumpf – sonst könnte der Rumpf die Pfadwerte überdecken.
	 */
	protected function body(int $maxBytes): \stdClass {
		$body = Json::body(Json::readInput($maxBytes), $maxBytes);
		foreach (['kind', 'key', 'id'] as $field) {
			if (property_exists($body, $field)) {
				throw ApiException::badRequest("„{$field}“ gehört in den Pfad, nicht in den Rumpf.");
			}
		}
		return $body;
	}
}
