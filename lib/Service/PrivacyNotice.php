<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\RecordMapper;
use OCA\TimeSister\Db\Tenant;
use OCP\IL10N;

/**
 * The privacy notice of a team (0.10.2): facts live from the team
 * ({@see PrivacyFacts}), text in the caller's language
 * ({@see PrivacyDocument}), rendered as Markdown or HTML
 * ({@see PrivacyFormat}). Also reads and writes the record
 * `setting/privacy` for the Nextcloud admin, who is no team member.
 */
final class PrivacyNotice {
	public function __construct(
		private PrivacyFacts $facts,
		private RecordMapper $records,
		private RecordService $recordService,
		private IL10N $l,
	) {
	}

	/**
	 * The document as a download.
	 *
	 * @return array{content:string,name:string,mime:string}
	 */
	public function render(Tenant $t, mixed $format): array {
		$format = PrivacyFormat::checkFormat($format);
		$facts = $this->facts->collect($t);
		$doc = (new PrivacyDocument($this->l))->build($facts);
		return [
			'content' => $format === 'md' ? PrivacyFormat::markdown($doc) : PrivacyFormat::html($doc, $this->l->getLanguageCode()),
			'name' => PrivacyFormat::fileName($t->getSlug(), (string)$facts['date'], $format),
			'mime' => PrivacyFormat::mime($format),
		];
	}

	/**
	 * `setting/privacy` as the admin page shows it: the stored values in
	 * full ({@see PrivacyRules::normalize}) and the version to write against.
	 *
	 * @return array{version:int,data:array<string,mixed>}
	 */
	public function settings(Tenant $t): array {
		$r = $this->records->findOne($t->getId(), 'setting', PrivacyRules::KEY);
		$raw = $r?->getData();
		$data = $r === null || $r->isTombstone() || $raw === null ? null : Json::decode($raw);
		return [
			'version' => $r === null || $r->isTombstone() ? 0 : $r->getVersion(),
			'data' => PrivacyRules::normalize($data instanceof \stdClass ? $data : null),
		];
	}

	/**
	 * PUT /admin/teams/{id}/privacy: the Nextcloud admin writes the record
	 * with the rights of a Team Admin, against the current version.
	 *
	 * @return array<string,mixed> the record as RecordService presents it
	 */
	public function save(Tenant $t, string $actor, mixed $data): array {
		if (!($data instanceof \stdClass)) {
			throw ApiException::invalid('“data” must be a JSON object.');
		}
		$body = new \stdClass();
		$body->version = $this->settings($t)['version'];
		$body->data = $data;
		$m = new Membership($actor, $t->getId(), Role::ADMIN);
		return $this->recordService->put($m, 'setting', PrivacyRules::KEY, $body);
	}
}
