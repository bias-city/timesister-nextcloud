<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\RecordMapper;

/**
 * Where a receipt's file lies in the document tree: `Spesen/<person>/`
 * when reimbursed, else `Belege/`, named from the record. While the
 * receipt is open the file follows every change of a name part; approval
 * moves it a last time (0.22.0).
 */
final class ReceiptPlace {
	public function __construct(
		private RecordMapper $records,
		private TeamTree $tree,
		private DocumentFolder $folders,
		private TenantService $team,
	) {
	}

	/** Folder and name of the receipt's file. @return array{0:string,1:string} */
	public function place(int $tid, string $root, \stdClass $d, string $ext): array {
		$date = (string)$d->date;
		$person = Fields::str($d, 'person');
		$folder = ($d->reimburse ?? false) === true && $person !== null
			? DocumentTree::expenseFolder($root, $date, $this->team->displayName($person))
			: DocumentTree::receiptFolder($root, $date);
		$code = null;
		$project = Fields::str($d, 'project');
		if ($project !== null) {
			$p = $this->records->findOne($tid, 'project', $project);
			$code = ReceiptRules::projectCode($p === null || $p->isTombstone() ? null : InvoiceRules::decode($p->getData()), $project);
		}
		$name = DocumentTree::receiptName($date, (string)($d->supplier ?? ''), (float)$d->gross, (string)($d->currency ?? 'CHF'), $code, is_int($d->cost ?? null) ? $d->cost : null, $ext);
		return [$folder, $name];
	}

	/**
	 * Moves the receipt's file to its current name and sets `file.path`.
	 *
	 * @return ?array{0:string,1:string,2:string} owner, new and old path when it moved
	 */
	public function follow(int $tid, \stdClass $d): ?array {
		$file = Fields::obj($d, 'file');
		$path = Fields::str($file, 'path');
		if ($file === null || $path === null) {
			return null;
		}
		$place = $this->tree->of($tid);
		[$folder, $name] = $this->place($tid, $place['root'], $d, DocumentTree::extension(Fields::text($file, 'mime')));
		$new = $this->folders->move($place['owner'], $path, $folder, $name);
		if ($new === $path) {
			return null;
		}
		$file->path = $new;
		return [$place['owner'], $new, $path];
	}

	/** Undoes a move of {@see follow()} (the record write failed). @param array{0:string,1:string,2:string} $moved */
	public function back(array $moved): void {
		[$owner, $new, $old] = $moved;
		$this->folders->move($owner, $new, dirname($old), basename($old));
	}
}
