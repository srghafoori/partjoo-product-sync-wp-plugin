
# AGENTS.md

# PartJoo WooCommerce Plugin

This repository contains the official WooCommerce connector for the PartJoo Search Engine.

Target version: **2.0.0**

Baseline: **v1.3**

---

## Goal

Build a production-ready, scalable, secure and maintainable WooCommerce plugin.

This plugin replaces crawling by synchronizing WooCommerce products directly with the PartJoo API.

---

## Rules

Before making any change:

1. Read the relevant files first.
2. Understand the existing architecture.
3. Preserve backward compatibility.
4. Refactor only when necessary.
5. Never replace whole files if a small change is enough.
6. Never remove existing features without approval.

---

## Architecture

Prefer small service classes.

Avoid God Classes.

Business logic belongs in services, not WordPress hooks.

Current architecture:

- Container
- Sync Orchestrator
- Payload Builder
- Signature Service
- API Client
- Transport
- Logger
- Product Repository
- Validation Layer

---

## WordPress Standards

Always follow:

- WordPress Coding Standards
- WooCommerce Best Practices

Use:

- Nonces
- Capability checks
- Validation
- Sanitization
- Escaping

---

## Performance

Performance is important.

Avoid:

- Loading all products into memory.
- N+1 database queries.
- Unnecessary database writes.

Prefer batching.

---

## API

The PartJoo API documentation is the source of truth.

Never:

- Change payload structure.
- Rename API fields.
- Change signature logic.
- Invent undocumented API fields.

If the API documentation and the code conflict, follow the documentation.

---

## Compatibility

Must remain compatible with:

- WordPress
- WooCommerce
- HPOS
- Multisite
- Previous plugin versions

Never introduce breaking changes without approval.

---

## Git

Use Conventional Commits.

Examples:

feat(sync):
fix(admin):
refactor(core):
perf(queue):
docs(readme):

Keep commits small and focused.

---

## Before Every Commit

Verify:

- No regression
- Backward compatibility
- Security
- Performance
- Clean architecture

If uncertain, stop and ask before implementing.
