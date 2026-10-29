<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The stable identifiers of rule violations (0.31.0): `code` in the error
 * body beside the coarse `error`. Every constant stands in the table
 * «Error codes» of API.md and vice versa (a unit test keeps them equal);
 * a code never changes once published, a retired rule keeps its row.
 */
final class ErrorCode {
	// ---- receipts and expenses
	public const EXPENSE_SETTLED = 'expense_settled';
	public const EXPENSE_SETTLED_LEAD_ONLY = 'expense_settled_lead_only';
	public const EXPENSE_FIELDS_OWNER_ONLY = 'expense_fields_owner_only';
	public const EXPENSE_EXPORT_ADMIN_ONLY = 'expense_export_admin_only';
	public const RECEIPT_APPROVED_NOT_DELETED = 'receipt_approved_not_deleted';
	public const RECEIPT_APPROVED_LEAD_ONLY = 'receipt_approved_lead_only';
	public const RECEIPT_APPROVED_STAYS = 'receipt_approved_stays';
	public const RECEIPT_APPROVER_ONLY = 'receipt_approver_only';
	public const RECEIPT_BILLED = 'receipt_billed';
	public const RECEIPT_FILE_MISSING = 'receipt_file_missing';
	public const RECEIPT_NOT_APPROVED = 'receipt_not_approved';
	public const RECEIPT_NOT_OPEN = 'receipt_not_open';
	public const RECEIPT_NOT_OWN = 'receipt_not_own';
	public const RECEIPT_REOPEN_ADMIN_ONLY = 'receipt_reopen_admin_only';
	public const BOOKED_CURRENCY_AMOUNT = 'booked_currency_amount';
	public const BUDGET_CURRENCY_AMOUNT = 'budget_currency_amount';
	public const RULE_HAS_RECEIPTS = 'rule_has_receipts';
	public const RULE_OWNER_ONLY = 'rule_owner_only';
	public const RULE_PERIOD_DUPLICATE = 'rule_period_duplicate';
	// ---- expense reports
	public const REPORT_NO_EXPENSES = 'report_no_expenses';
	public const REPORT_NO_CATEGORY = 'report_no_category';
	public const REPORT_NO_RATE = 'report_no_rate';
	public const REPORT_NOT_FIXED = 'report_not_fixed';
	public const REPORT_NOT_WRITABLE = 'report_not_writable';
	public const REPORT_NUMBER_TAKEN = 'report_number_taken';
	public const REPORT_OWNER_ONLY = 'report_owner_only';
	public const REPORT_REVERSE_ADMIN_ONLY = 'report_reverse_admin_only';
	public const REPORT_DOCUMENT_EXISTS = 'report_document_exists';
	public const REPORT_DOCUMENTS_MAX = 'report_documents_max';
	public const PERSON_NOT_MEMBER = 'person_not_member';
	public const SETTLE_OWNER_ONLY = 'settle_owner_only';
	// ---- invoices and hand-over
	public const NO_CURRENCY = 'no_currency';
	public const NO_ISSUER = 'no_issuer';
	public const INVOICE_ISSUED = 'invoice_issued';
	public const INVOICE_NOT_DRAFT = 'invoice_not_draft';
	public const INVOICE_NO_BUDGET = 'invoice_no_budget';
	public const INVOICE_HANDED_OVER = 'invoice_handed_over';
	public const INVOICE_NOT_HANDED_OVER = 'invoice_not_handed_over';
	public const INVOICE_LEAD_ONLY = 'invoice_lead_only';
	public const INVOICE_LEAD_DRAFT_ONLY = 'invoice_lead_draft_only';
	public const INVOICE_REVIEW_ADMIN_ONLY = 'invoice_review_admin_only';
	public const INVOICE_ISSUE_ADMIN_ONLY = 'invoice_issue_admin_only';
	public const INVOICE_ISSUE_EXTERNAL = 'invoice_issue_external';
	public const INVOICE_NUMBER_ADMIN_ONLY = 'invoice_number_admin_only';
	public const INVOICE_NUMBER_TAKEN = 'invoice_number_taken';
	public const INVOICE_ALREADY_REVERSED = 'invoice_already_reversed';
	public const INVOICE_READ_LEAD_ONLY = 'invoice_read_lead_only';
	public const BOOKING_BILLED = 'booking_billed';
	public const HANDOVER_ADMIN_ONLY = 'handover_admin_only';
	public const HANDOVER_MODE_NOT_EXTERNAL = 'handover_mode_not_external';
	public const HANDOVER_OTHER_SYSTEM = 'handover_other_system';
	public const HANDOVER_NO_ARCHIVE = 'handover_no_archive';
	public const HANDOVER_PDF_EXISTS = 'handover_pdf_exists';
	// ---- the two-step issue and the documents (0.32.0)
	public const ISSUE_PENDING = 'issue_pending';
	public const NOT_ISSUING = 'not_issuing';
	public const DOCUMENT_MISSING = 'document_missing';
	public const NUMBER_NOT_LAST = 'number_not_last';
	public const DOCUMENT_MISMATCH = 'document_mismatch';
	public const DOCUMENT_EXISTS = 'document_exists';
	// ---- quotes (0.32.0)
	public const QUOTE_LEAD_ONLY = 'quote_lead_only';
	public const QUOTE_NOT_DRAFT = 'quote_not_draft';
	public const QUOTE_NOT_ISSUED = 'quote_not_issued';
	public const QUOTE_ISSUED_FROZEN = 'quote_issued_frozen';
	public const QUOTE_NUMBER_TAKEN = 'quote_number_taken';
	public const QUOTE_DOCUMENTS_MAX = 'quote_documents_max';
	public const QUOTE_NAME_REQUIRED = 'quote_name_required';
	// ---- records and routes
	public const EXCHANGE_RATE_SERVER_ONLY = 'exchange_rate_server_only';
	public const VERSION_CONFLICT = 'version_conflict';
	public const GONE = 'gone';
}
