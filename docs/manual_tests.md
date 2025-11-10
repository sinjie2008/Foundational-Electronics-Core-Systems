# Manual Test Notes

## UI Smoke Checklist

1. Open `http://localhost/catalog_ui.html` (or the deployed static HTML) in a browser.
2. Verify hierarchy tree loads with seeded data.
3. Select a series node (e.g., **C0 SERIES**) and confirm custom fields and products appear.
4. Add a new category or series via the **Add Node** form and confirm it appears in the hierarchy after submission.
5. Update the newly created node name using **Update Selected Node** and ensure the change persists after refreshing the hierarchy.
6. Create a new series field for the selected series, then delete it to confirm field CRUD behaviour.
7. Add a product within the selected series, populate custom field values, confirm it lists under Products, then delete it.
8. Use the search panel: enter a keyword (e.g., "C0"), choose the scope (series/products), optionally select a series to expose custom field filters, and confirm grouped results render across categories/series/products.
9. Refresh the page to confirm all persisted data remains consistent.
