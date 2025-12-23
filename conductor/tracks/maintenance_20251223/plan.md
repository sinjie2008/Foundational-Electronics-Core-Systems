# Track Plan: Typst Templating Enhancement

## Phase 1: Error Handling & Output Parsing
- [ ] Task: Write tests for Typst error parsing in `TypstService`.
- [ ] Task: Update `TypstService::compileTypst` to capture and return stderr on failure.
- [ ] Task: Update `api/typst/compile.php` to surface detailed errors to the frontend.
- [ ] Task: Conductor - User Manual Verification 'Error Handling & Output Parsing' (Protocol in workflow.md)

## Phase 2: Asset Validation & Staging
- [ ] Task: Write tests for global variable asset validation.
- [ ] Task: Implement asset existence and mimetype checks in `TypstService`.
- [ ] Task: Refactor asset staging logic to ensure clean build environments.
- [ ] Task: Conductor - User Manual Verification 'Asset Validation & Staging' (Protocol in workflow.md)
