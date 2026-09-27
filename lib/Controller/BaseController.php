<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Controller;

use OCA\TimeSister\AppInfo\Application;
use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\Json;
use OCA\TimeSister\Service\Message;
use OCA\TimeSister\Service\TenantService;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Shared by all endpoints: errors as `data = {error, message, …}` with the
 * message in the account's language, JSON bodies as objects (so that `{}`
 * does not become `[]`).
 */
abstract class BaseController extends OCSController {
	/** A record is at most 256 KB; the body may be somewhat larger. */
	public const MAX_RECORD_BODY = 1024 * 1024;
	public const MAX_BATCH_BODY = 50 * 1024 * 1024;

	public function __construct(
		IRequest $request,
		protected TenantService $tenants,
		protected IL10N $l,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function run(callable $fn): DataResponse {
		try {
			$result = $fn();
			return $result instanceof DataResponse ? $result : new DataResponse($result);
		} catch (ApiException $e) {
			return new DataResponse($e->toData($this->l), $e->getStatus());
		}
	}

	/**
	 * The JSON body. Kind, key and ID are in the path, never in the body –
	 * otherwise the body could override the path values.
	 */
	protected function body(int $maxBytes): \stdClass {
		$body = Json::body(Json::readInput($maxBytes), $maxBytes);
		foreach (['kind', 'key', 'id'] as $field) {
			if (property_exists($body, $field)) {
				throw ApiException::badRequest(Message::of('“{field}” belongs in the path, not in the body.', ['field' => $field]));
			}
		}
		return $body;
	}
}
