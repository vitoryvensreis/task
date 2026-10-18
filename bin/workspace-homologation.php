<?php
declare(strict_types=1);

/**
 * B10 — read-only workspace homologation gate.
 *
 * Does not create tenants, activate billing, alter schema, reset defaults,
 * impersonate the owner, or contact third-party services.
 *
 * Usage:
 *   php bin/workspace-homologation.php
 *   php bin/workspace-homologation.php --deep
 *   php bin/workspace-homologation.php --require-multi-ready
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__).'/app/bootstrap.php';

$deep = in_array('--deep', $argv, true);
$requireMultiReady = in_array('--require-multi-ready', $argv, true);

try {
    $issues = array_values(array_diff(
        WorkspaceFoundation::issues($deep),
        // A revoked/unlinked legacy user is intentionally denied by B1 auth.
        ['unlinked_legacy_users']
    ));

    $workspace = DB::one(
        'SELECT id,slug,status,owner_user_id FROM workspaces WHERE slug=? LIMIT 1',
        [WorkspaceFoundation::SLUG]
    );
    $workspaceCount = (int)(DB::one('SELECT COUNT(*) n FROM workspaces')['n'] ?? 0);
    $master = DB::one(
        'SELECT p.status,p.user_id,u.active,u.role,u.email
           FROM platform_master_identities p
           LEFT JOIN users u ON u.id=p.user_id
          WHERE p.email=? LIMIT 1',
        [WorkspaceFoundation::MASTER_EMAIL]
    );
    $masterVerified = (bool)(
        $master && $master['status'] === 'verified'
        && (int)($master['user_id'] ?? 0) > 0
        && (int)($master['active'] ?? 0) === 1
        && ($master['role'] ?? '') === 'admin'
        && strtolower((string)($master['email'] ?? '')) === WorkspaceFoundation::MASTER_EMAIL
    );
    $masterInWorkspace = $master && (int)($master['user_id'] ?? 0) > 0
        ? (bool)DB::one('SELECT 1 FROM workspace_members WHERE user_id=? LIMIT 1',
            [(int)$master['user_id']])
        : false;
    $activeSupport = (int)(DB::one(
        'SELECT COUNT(*) n FROM workspace_support_sessions
          WHERE ended_at IS NULL AND expires_at > ?',
        [now()]
    )['n'] ?? 0);

    $legacyTables = array_keys(WorkspaceFoundation::DATA_TABLES);
    $placeholders = implode(',', array_fill(0, count($legacyTables), '?'));
    $defaultRows = DB::all(
        "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME='workspace_id'
             AND TABLE_NAME IN ($placeholders) AND COLUMN_DEFAULT IS NOT NULL",
        $legacyTables
    );
    $remainingDefaults = count($defaultRows);

    $baseline = $issues === [] && $workspaceCount === 1
        && $workspace !== null && $workspace['status'] === WorkspaceFoundation::STATUS
        && !$masterInWorkspace;
    $blockers = [];
    if ($issues !== []) $blockers[] = 'workspace_foundation_drift';
    if ($workspaceCount !== 1) $blockers[] = 'unexpected_workspace_count';
    if ($masterInWorkspace) $blockers[] = 'master_has_tenant_membership';
    if (!$masterVerified) $blockers[] = 'master_activation_pending';
    if ($activeSupport > 0) $blockers[] = 'active_support_session';
    if ($remainingDefaults > 0) $blockers[] = 'legacy_workspace_defaults_present';

    // Architectural blockers are deliberate: a healthy schema is NOT evidence
    // of tenant isolation. B1 and B9 enforce a single workspace in code.
    $blockers[] = 'single_workspace_application_guard';
    $blockers[] = 'cross_workspace_api_and_worker_isolation_not_homologated';
    $blockers[] = 'staging_a_b_authorization_checks_not_homologated';

    echo json([
        'block' => 10,
        'operation' => 'read_only',
        'schema' => (int)(DB::one("SELECT value FROM settings WHERE `key`='schema_version'")['value'] ?? 0),
        'foundation' => [
            'baseline_ok' => $baseline,
            'issues' => $issues,
            'workspace_count' => $workspaceCount,
            'workspace_status' => $workspace['status'] ?? null,
            'deep_row_check' => $deep,
            'legacy_default_columns' => $remainingDefaults,
            'legacy_tables_expected' => count($legacyTables)
        ],
        'master' => [
            'verified' => $masterVerified,
            'linked_to_workspace' => $masterInWorkspace,
            'active_support_sessions' => $activeSupport
        ],
        'multiworkspace' => [
            'activation_allowed' => false,
            'second_workspace_created' => false,
            'blockers' => array_values(array_unique($blockers))
        ],
        'next' => 'Isolar APIs, workers, realtime, uploads e integrações; homologar A/B em staging; migrar índices/defaults sob backup; autorizar piloto explicitamente.'
    ]).PHP_EOL;

    // The default command inspects the current single-workspace baseline.
    // The strict gate must stay CLOSED until a subsequent verified release.
    if (!$baseline) exit(2);
    if ($requireMultiReady) exit(3);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, json([
        'block' => 10,
        'operation' => 'read_only',
        'baseline_ok' => false,
        'multiworkspace_activation_allowed' => false,
        'error' => 'homologation_read_failed'
    ]).PHP_EOL);
    exit(4);
}
