<?php
/**
 * Domain Issues Tracker — English language file (the module UI is EN only).
 */

$_ADDONLANG = [];

// Issue types
$_ADDONLANG['type.transfer_in'] = 'Transfer in';
$_ADDONLANG['type.transfer_out'] = 'Transfer out';
$_ADDONLANG['type.renewal'] = 'Renewal failed';
$_ADDONLANG['type.redemption'] = 'Expired / redemption';
$_ADDONLANG['type.registration'] = 'Registration failed';
$_ADDONLANG['type.sync_mismatch'] = 'WHMCS / registrar mismatch';
$_ADDONLANG['type.other'] = 'Other';
$_ADDONLANG['type.cat:transfers'] = 'All transfers';
$_ADDONLANG['type.cat:other'] = 'All non-transfer issues';

// Statuses
$_ADDONLANG['status.new'] = 'New';
$_ADDONLANG['status.in_progress'] = 'In progress';
$_ADDONLANG['status.waiting_client'] = 'Waiting for client';
$_ADDONLANG['status.resolved'] = 'Resolved';
$_ADDONLANG['status.cancelled'] = 'Cancelled';
$_ADDONLANG['status.open'] = 'Open (all active)';
$_ADDONLANG['status.closed'] = 'Closed (resolved + cancelled)';
$_ADDONLANG['status.all'] = 'All statuses';

// Priorities
$_ADDONLANG['priority.low'] = 'Low';
$_ADDONLANG['priority.normal'] = 'Normal';
$_ADDONLANG['priority.high'] = 'High';

// Sources
$_ADDONLANG['source.manual'] = 'Manual';
$_ADDONLANG['source.automatic'] = 'Automatic';

// Subtypes (hints)
$_ADDONLANG['subtype.invalid_epp'] = 'Invalid EPP / auth code';
$_ADDONLANG['subtype.domain_locked'] = 'Domain locked';
$_ADDONLANG['subtype.pending_approval'] = 'Pending client approval';
$_ADDONLANG['subtype.approval_expired'] = 'Approval expired';
$_ADDONLANG['subtype.payment_failed'] = 'Payment failed';
$_ADDONLANG['subtype.redemption_period'] = 'Redemption period';
$_ADDONLANG['subtype.pending_delete'] = 'Pending delete';
$_ADDONLANG['subtype.registrar_error'] = 'Registrar error';
$_ADDONLANG['subtype.expired_grace'] = 'Expired (grace period)';
$_ADDONLANG['subtype.transfer_cancelled'] = 'Transfer cancelled';
$_ADDONLANG['subtype.transfer_completed'] = 'Transfer completed at registrar';
$_ADDONLANG['subtype.not_at_registrar'] = 'Not found at registrar';
$_ADDONLANG['subtype.registered_at_registrar'] = 'Registered at registrar, WHMCS pending';
$_ADDONLANG['subtype.registrar_mismatch'] = 'WHMCS points to another registrar';
$_ADDONLANG['subtype.domain_unavailable'] = 'Domain not available';
$_ADDONLANG['subtype.contact_mismatch'] = 'Contact mismatch';
$_ADDONLANG['subtype.nameserver_mismatch'] = 'Nameserver mismatch';
$_ADDONLANG['subtype.manual_action'] = 'Manual action required (external registrar)';
$_ADDONLANG['subtype.other'] = 'Other';

// Log fields
$_ADDONLANG['field.created'] = 'Created';
$_ADDONLANG['field.status'] = 'Status';
$_ADDONLANG['field.priority'] = 'Priority';
$_ADDONLANG['field.deadline'] = 'Deadline';
$_ADDONLANG['field.issue_type'] = 'Type';
$_ADDONLANG['field.issue_subtype'] = 'Subtype';
$_ADDONLANG['field.registrar'] = 'Registrar';
$_ADDONLANG['field.domain_name'] = 'Domain';
$_ADDONLANG['field.client_id'] = 'Client ID';
$_ADDONLANG['field.domain_id'] = 'WHMCS domain ID';
$_ADDONLANG['field.client_reminder'] = 'Transfer code reminder e-mailed to client (no.)';

// Generic UI
$_ADDONLANG['ui.title'] = 'Domain Issues';
$_ADDONLANG['ui.all_issues'] = 'All issues';
$_ADDONLANG['ui.add_issue'] = 'Add issue';
$_ADDONLANG['ui.edit_issue'] = 'Edit issue';
$_ADDONLANG['ui.back_to_list'] = 'Back to list';
$_ADDONLANG['ui.save'] = 'Save changes';
$_ADDONLANG['ui.create'] = 'Create issue';
$_ADDONLANG['ui.cancel'] = 'Cancel';
$_ADDONLANG['ui.delete'] = 'Delete';
$_ADDONLANG['ui.delete_confirm'] = 'Permanently delete this issue, its comments and history? This cannot be undone. Consider setting the status to Cancelled instead.';
$_ADDONLANG['ui.filter'] = 'Filter';
$_ADDONLANG['ui.reset'] = 'Reset';
$_ADDONLANG['ui.search'] = 'Search';
$_ADDONLANG['ui.search_placeholder'] = 'Domain, client name, email or ID';
$_ADDONLANG['ui.all_types'] = 'All types';
$_ADDONLANG['ui.all_registrars'] = 'All registrars';
$_ADDONLANG['ui.no_registrar'] = '(no registrar)';
$_ADDONLANG['ui.all_sources'] = 'All sources';
$_ADDONLANG['ui.all_priorities'] = 'All priorities';
$_ADDONLANG['ui.urgent_only'] = 'Urgent only';
$_ADDONLANG['ui.show_expired'] = 'Show expired';
$_ADDONLANG['ui.show_expired_help'] = 'Include domains in the renewal grace period and expired domains that no longer exist at the registrar (low priority).';
$_ADDONLANG['ui.show_redemption'] = 'Show redemption';
$_ADDONLANG['ui.show_redemption_help'] = 'Include domains already in redemption or pending delete.';
$_ADDONLANG['ui.lifecycle_hidden'] = 'Expired / redemption rows (and expired domains gone from the registrar) are hidden — tick "Show expired" / "Show redemption" to include them.';
$_ADDONLANG['ui.showing'] = 'Showing :from–:to of :total issues';
$_ADDONLANG['ui.no_results'] = 'No issues match the current filters.';
$_ADDONLANG['ui.view'] = 'View';
$_ADDONLANG['ui.actions'] = 'Actions';
$_ADDONLANG['ui.open_in_whmcs'] = 'Open domain in WHMCS';
$_ADDONLANG['ui.system'] = 'System';
$_ADDONLANG['ui.none'] = 'none';
$_ADDONLANG['ui.optional'] = 'optional';
$_ADDONLANG['ui.lookup'] = 'Look up';
$_ADDONLANG['ui.domain_found'] = 'Found in WHMCS: domain #:id, client :client, registrar :registrar, status :status';
$_ADDONLANG['ui.domain_not_found'] = 'Not found in WHMCS — the issue will be stored without a domain link.';
$_ADDONLANG['ui.domain_help'] = 'Start typing to search WHMCS domains, or enter any domain name.';
$_ADDONLANG['ui.client_help'] = 'WHMCS client ID. Filled automatically when the domain is found.';
$_ADDONLANG['ui.deadline_help'] = 'e.g. transfer approval expiry or end of redemption period.';
$_ADDONLANG['ui.details_help'] = 'Raw registrar error or a description of the problem.';
$_ADDONLANG['ui.urgent_hint'] = 'Highlighted rows: high priority or deadline within :days days.';

// Columns
$_ADDONLANG['col.id'] = '#';
$_ADDONLANG['col.domain'] = 'Domain';
$_ADDONLANG['col.client'] = 'Client';
$_ADDONLANG['col.type'] = 'Type';
$_ADDONLANG['col.subtype'] = 'Subtype';
$_ADDONLANG['col.registrar'] = 'Registrar';
$_ADDONLANG['col.source'] = 'Source';
$_ADDONLANG['col.status'] = 'Status';
$_ADDONLANG['col.priority'] = 'Priority';
$_ADDONLANG['col.deadline'] = 'Deadline';
$_ADDONLANG['col.updated'] = 'Last update';
$_ADDONLANG['col.details'] = 'Details';
$_ADDONLANG['col.created'] = 'Created';
$_ADDONLANG['col.created_by'] = 'Created by';
$_ADDONLANG['col.resolved'] = 'Resolved';
$_ADDONLANG['col.whmcs_status'] = 'WHMCS domain status';

// Deadline
$_ADDONLANG['deadline.overdue'] = 'overdue by :days d';
$_ADDONLANG['deadline.today'] = 'due today';
$_ADDONLANG['deadline.days_left'] = ':days d left';

// Detail page
$_ADDONLANG['detail.title'] = 'Issue #:id — :domain';
$_ADDONLANG['detail.comments'] = 'Comments';
$_ADDONLANG['detail.no_comments'] = 'No comments yet.';
$_ADDONLANG['detail.add_comment'] = 'Add comment';
$_ADDONLANG['detail.comment_placeholder'] = 'Write a note for the team…';
$_ADDONLANG['detail.history'] = 'History';
$_ADDONLANG['detail.no_history'] = 'No changes recorded.';
$_ADDONLANG['detail.audit'] = 'Audit';
$_ADDONLANG['detail.automatic_note'] = 'This issue was created automatically. Status changes you make here are kept; the cron may mark it resolved once the registrar no longer reports the problem.';

// Flash messages
$_ADDONLANG['flash.created'] = 'Issue #:id created.';
$_ADDONLANG['flash.updated'] = 'Issue #:id updated (:fields).';
$_ADDONLANG['flash.no_changes'] = 'No changes were made.';
$_ADDONLANG['flash.comment_added'] = 'Comment added.';
$_ADDONLANG['flash.deleted'] = 'Issue #:id deleted.';
$_ADDONLANG['flash.validation'] = 'Please correct the highlighted fields.';

// Errors
$_ADDONLANG['error.domain_required'] = 'Domain name is required.';
$_ADDONLANG['error.domain_invalid'] = 'Enter a valid domain name (e.g. example.md).';
$_ADDONLANG['error.type_invalid'] = 'Select a valid issue type.';
$_ADDONLANG['error.type_disabled'] = 'This issue category is disabled in the addon settings.';
$_ADDONLANG['error.status_invalid'] = 'Invalid status.';
$_ADDONLANG['error.priority_invalid'] = 'Invalid priority.';
$_ADDONLANG['error.deadline_invalid'] = 'Deadline must be a date in YYYY-MM-DD format.';
$_ADDONLANG['error.client_invalid'] = 'Client ID must be a number.';
$_ADDONLANG['error.client_not_found'] = 'No WHMCS client with this ID.';
$_ADDONLANG['error.too_long'] = 'Value is too long (max 64 characters).';
$_ADDONLANG['error.not_found'] = 'Issue not found.';
$_ADDONLANG['error.comment_required'] = 'Comment cannot be empty.';
$_ADDONLANG['error.invalid_request'] = 'Invalid request.';

// System comments
$_ADDONLANG['comment.auto_resolved'] = 'Automatically resolved: :reason';
$_ADDONLANG['comment.kept_open'] = 'Not closed automatically: the domain is now ":status" in WHMCS, so the registrar no longer reports on the :type operation, but it was never confirmed as completed. Close this case manually once it is handled.';
$_ADDONLANG['comment.not_in_whmcs'] = 'no longer in WHMCS';
$_ADDONLANG['comment.recheck_reason'] = 'the :registrar adapter no longer reports this problem (cron re-check)';
$_ADDONLANG['comment.reminder_started'] = 'Daily transfer code reminders started: the client is e-mailed once a day (template "Domain Transfer Code Reminder") until a valid code is received, the transfer completes or this case is closed. Each reminder is listed in the History.';
$_ADDONLANG['comment.reminder_escalated'] = 'Escalated to staff after :count reminder(s): the client has not sent a valid transfer code and the domain expiry is :deadline. Priority set to High; the admins are e-mailed daily. Please contact the client directly.';
$_ADDONLANG['comment.reminder_no_deadline'] = 'unknown';

// Widget
$_ADDONLANG['widget.problem_transfers'] = 'Problem transfers';
$_ADDONLANG['widget.other_issues'] = 'Other domain issues';
$_ADDONLANG['widget.urgent'] = 'Urgent (high priority or due within :days days)';
$_ADDONLANG['widget.open_tracker'] = 'Open tracker';
$_ADDONLANG['widget.urgent_short'] = 'Urgent';
$_ADDONLANG['widget.not_installed'] = 'Domain Issues tables are missing — re-activate the addon.';
