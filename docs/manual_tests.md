# Manual Test Notes

## UI Smoke Checklist

1. Open `http://localhost/catalog_ui.html` (or the deployed static HTML) in a browser.
2. Verify hierarchy tree loads with seeded data.
3. Confirm Section 1 displays the four cards (Hierarchy tree with per-category accordions, Add Node, Update Selected Node, Selected Node) inline on larger viewports, that only the root level is expanded by default (all deeper levels start collapsed), and that each category’s chevron toggles only its children.
4. Select a series node (e.g., **C0 SERIES**) and confirm Sections 2 and 3 slide in (Series Custom Fields + Series Metadata inline, Products section showing list/form side-by-side).
5. Add a new category or series via the **Add Node** form and confirm it appears in the hierarchy after submission.
6. Update the newly created node name using **Update Selected Node** and ensure the change persists after refreshing the hierarchy.
7. Create a new series field for the selected series, then delete it to confirm field CRUD behaviour.
8. Add a product within the selected series, populate custom field values, confirm it lists under Products, then delete it.
9. Trigger the **Truncate Catalog** button, type `TRUNCATE` plus a reason, confirm the toast shows the audit ID, and verify the hierarchy panel now reports "No categories defined."
10. Refresh the page to confirm the cleared state persists until you import a new CSV.
