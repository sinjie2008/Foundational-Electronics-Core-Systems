Todolist
========

Legend: Pending | In Progress | Blocked | Done

| Task | Status | Test Approach | Notes/Dependencies |
| --- | --- | --- | --- |
| Draft/Review docs (spec.md with Mermaid diagrams, api.md, decisions) | Done | Manual review; optional `Select-String` check for Mermaid blocks | Required before any code moves |
| Backend OO refactor (controllers in public/, services/repositories in app/, config centralization) | In Progress | Unit tests for services/validation; integration tests hitting DB and endpoints | Depends on docs finalization |
| Frontend update to Bootstrap 5 + DataTables; jQuery via ES6 classes; wire assets to public/assets | In Progress | Browser smoke/E2E for DataTables init and interactions | Depends on backend endpoints stability |
| CSV/LaTeX service wrap (no behavior change) with logging and correlation IDs | Pending | Unit tests for import validation; integration test for PDF URL generation | Depends on backend refactor |
| Build/test scripts (PowerShell) and test consolidation (unit/integration/contract) | Pending | Run `scripts/run-tests.ps1` (to be updated) | Depends on refactor tasks |
| Clean-up and verify paths (public/, storage/, assets/) and update docs/todolist statuses | Pending | Regression smoke + contract tests | Final step |
