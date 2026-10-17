<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * What the writes of one transaction claim among themselves (0.22.0):
 * recurring receipts per rule and period; since 0.23.0 what a receipt
 * written by an approver still lacks (the answer's `missing`).
 */
final class CostBatch {
	/** @var array<string,string> "rule|period" → receipt key */
	public array $recurring = [];
	/** @var array<string,list<string>> receipt key → what it lacks for approval */
	public array $missing = [];
}
