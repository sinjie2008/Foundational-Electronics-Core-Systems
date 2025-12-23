# Track Specification: Typst Templating Enhancement

## Overview
This track focuses on improving the robustness and reliability of the Typst templating system, specifically regarding how global variable assets (images/files) are handled, validated, and debugged during the compilation process.

## Problem Statement
The current Typst templating system lacks detailed error reporting when compilation fails due to missing assets or invalid variable names. Additionally, there is limited validation on global variable assets before they are sent to the Typst binary.

## Goals
- Implement detailed error parsing for Typst compilation failures.
- Add server-side validation for global variable assets (mimetype, existence).
- Improve the staging process for assets in the build directory.

## Requirements
- Parse `typst.exe` stderr output to return meaningful error messages to the UI.
- Validate that image assets exist and are valid image types before compilation.
- Ensure sanitized Typst identifiers do not result in collisions that break the document.

## Technical Details
- **TypstService.php:** Enhance `compileTypst` to capture and interpret shell output.
- **Validation:** Use `finfo` or similar to verify uploaded asset integrity.
- **Staging:** Improve the `storage/typst-build` cleanup and asset staging logic.
