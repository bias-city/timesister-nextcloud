<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * What the writes of one transaction claim among themselves (0.22.0):
 * recurring receipts per rule and period, receipts per expense report,
 * and the receipts whose `report` the server sets afterwards.
 */
final class CostBatch {
	/** @var array<string,string> "rule|period" → receipt key */
	public array $recurring = [];
	/** @var array<string,string> receipt key → report key */
	public array $claimed = [];
	/** @var array<string,?string> receipt key → the report to note in it (null: none) */
	public array $marks = [];
}
