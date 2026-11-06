<?php

/**
 * Basic, append-only audit trail (PLAN.md §11 Q9): who, when, what, which document and revision.
 * Never patient names, text or reasons. Events: doc_created, doc_copied, doc_deleted, code_requested,
 * approved, issued, withdrawn, withdrawn_deleted, doc_opened (issued/withdrawn), file_downloaded.
 */
function medscribe_audit(string $event, ?int $documentId, ?int $revision = null, ?string $user = null): void {
    $u = $user ?? (isset($GLOBALS['kernel']) ? ($GLOBALS['kernel']->getUserName() ?: 'user') : 'cli');
    store_db()->prepare('INSERT INTO audit_log (guid, cuser, event_type, document_id, revision, ip) VALUES (?,?,?,?,?,?)')
        ->execute([store_uuid(), mb_substr((string)$u, 0, 32), $event, $documentId, $revision, mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null]);
}
