# ADR-022 — Optional translation key preview

- **Decision ID:** ADR-022
- **Status:** ACTIVE
- **Decision Authority:** Package Owner, through the request to implement optional preview storage and a post-cache API, and the direction to record the decision in PR #19.
- **Scope:** Preview management, persistence, authorization, and transaction boundaries.
- **Canonical Contract / Current Owner:** [Package Reference §5.13](../../I18N_PACKAGE_REFERENCE.md#513-management--translation-key-preview) and [§8.1](../../I18N_PACKAGE_REFERENCE.md#81-optional-translation-key-preview).

## Context

Consumers need to locate text by translation key in their rendered interfaces. Shared storage and matching let consumers reuse the same preview workflow while applying their own authorization and cache policies.

## Decision

Provide an opt-in management API backed by a separately installed MySQL table. A preview selects whole domains or exact keys and expires at an explicit deadline. Apply the preview to presentation after loading translations so cached values and ordinary read contracts remain intact.

The Host authorizes management operations and chooses which requests can use a preview. It controls page and CDN cache bypass for those requests. The preview ID identifies stored state. Preview operations use the supplied PDO and participate in an open Host transaction on that connection.

## Rationale and consequences

Separate installation keeps the seven core tables sufficient for ordinary translation use. Keeping the preview at the presentation boundary lets PHP and JavaScript consumers reuse the same selection without adding preview state to shared translation caches. Hosts opting in install the extra table, wire authorization and request selection, and manage transactions that include their own writes.
