# Environment
## Operating system is Windows
## Commands must be compatible with PowerShell
## All shell examples and scripts must run in PowerShell; if bash examples exist, a PowerShell equivalent must also be provided

# Documentation
## If a project is divided into different domains (Frontend, Backend), AGENTS.md must be written separately (e.g., frontend/AGENTS.md and backend/AGENTS.md)
## Before making any modifications, documentation (spec.md, api.md) must be updated first
## Before writing code, the specification documents must be fully understood, and the understanding must be confirmed with the developer (record Q\&A/decisions)
## Specification documents must include the following (Mermaid diagrams required where noted):

```
1. Architecture and technology choices (rationale, trade-offs, constraints)
2. Data model
3. Key processes
4. Pseudocode (for critical paths)
5. System context diagram (Mermaid)
6. Container/deployment overview (Mermaid)
7. Module relationship diagram (Backend / Frontend) (Mermaid)
8. Sequence diagram (Mermaid)
9. ER diagram (Mermaid)
10. Class diagram (key backend classes) (Mermaid)
11. Flowchart (Mermaid)
12. State diagram (Mermaid)
```

## Mermaid requirement: spec.md must contain the above diagram types in Mermaid format; absence of Mermaid diagrams blocks development
## If backend code is required, API documentation (api.md) must be planned in advance, following RESTful style (resource naming, versioning, status codes, error model)
## Definition of Ready (before coding starts):

```
- spec.md updated and reviewed
- api.md drafted (if backend/API work)
- open questions resolved or recorded in docs/decisions
- tasks broken down in todolist.md (independently developable)
- test approach defined for each task
```
## Optional PowerShell presence check (can be used in CI to enforce Mermaid in spec.md):
````
if (-not (Select-String -Path .\docs\spec.md -Pattern '```mermaid' -Quiet)) { throw "docs/spec.md must contain Mermaid diagrams." }
````

# Coding Standards
## Code must include function-level comments, and important variables or objects must also have comments
## Consistent naming; avoid magic values; centralize configuration; handle errors explicitly
## Logging at service boundaries; include correlation IDs; never log secrets or PII
## PowerShell scripts must include comment-based help and set: \$ErrorActionPreference = 'Stop'

# Tasks
## Before development begins, tasks must be broken down so that each can be developed independently without interference, and tasks must be recorded in todolist.md
## When tasks are in progress or completed, todolist.md must be updated
## Before starting a new task, todolist.md must be reviewed, and the CONTEXT WINDOW must be reset
## Context Window reset checklist:
```
1. Summarize previous task results in todolist.md
2. Close or carry over open TODOs
3. Re-read spec.md and api.md changes since last task
4. Sync repository and clean local state
5. Refresh test data/mocks and local environment
```

## Definition of Done (per task):
```
- code implemented and linked to relevant spec/api sections
- tests written/updated and passing
- documentation (spec.md/api.md) updated, diagrams refreshed if needed
- todolist.md status updated (started/completed)
```

# Testing
## Each task must pass testing before it is considered complete, and only after testing is completed can the next task begin
## Testing scope (as applicable):
```
- Unit tests (fast, isolated)
- Integration tests (database, message broker, external services)
- Contract/API tests (request/response, error model)
- End-to-end tests for critical user flows
```
## Example PowerShell test invocations (adapt to stack):

```
- dotnet test --configuration Release
- npm test
- Invoke-Pester -CI
```




