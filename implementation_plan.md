# Implementation Plan - Catalog Hierarchy & Search

## Goal Description
Revise the `catalog_ui.html` to display a collapsible tree structure for the product catalog. The tree must show Categories, Sub-categories, Series, and Products. It must display counts for Categories (child categories) and Series (products). Additionally, implement an AJAX search function to filter and highlight results.

## User Review Required
> [!IMPORTANT]
> The `api/catalog` directory was empty. I will create `hierarchy.php` and `search.php` there.
> The existing `catalog.php` seems to be a monolithic handler. I will create standalone scripts in `api/catalog/` as requested.

## Proposed Changes

### Backend APIs

#### [NEW] [hierarchy.php](file:///c:/laragon/www/test/api/catalog/hierarchy.php)
- **Purpose**: Return the full hierarchical JSON tree.
- **Logic**:
    - Connect to DB.
    - Fetch all `category` rows (categories and series).
    - Fetch all `product` rows.
    - Build a nested array structure.
    - Calculate `child_category_count` for categories.
    - Calculate `product_count` for series.
    - Return JSON.

#### [NEW] [search.php](file:///c:/laragon/www/test/api/catalog/search.php)
- **Purpose**: Return matched nodes for a search query.
- **Logic**:
    - Connect to DB.
    - `GET ?q=keyword`.
    - Search `category` table (name) for matches.
    - Search `product` table (name) for matches.
    - Return list of matches with `id`, `type`, `name`, `parent_id` (or `series_id`).

### Frontend

#### [MODIFY] [catalog_ui.html](file:///c:/laragon/www/test/catalog_ui.html)
- Add a search input field above the hierarchy.
- Ensure the container structure matches the requirements.

#### [MODIFY] [catalog_ui.js](file:///c:/laragon/www/test/assets/js/catalog_ui.js)
- Update `loadHierarchy` to fetch from `api/catalog/hierarchy.php`.
- Update `renderHierarchy` (or `buildHierarchyList`) to:
    - Display counts: `[category] (X)` or `[series] (Y)`.
    - Render products as leaf nodes under series.
    - Use `>` and `v` icons for expand/collapse.
- Implement `handleSearch`:
    - Listen to search input (debounce).
    - Call `api/catalog/search.php`.
    - Highlight matched nodes.
    - Auto-expand parents of matched nodes.

## Verification Plan

### Manual Verification
1.  **Hierarchy Display**:
    - Verify the tree loads with Categories -> Series -> Products.
    - Verify counts are correct.
2.  **Search**:
    - Type a known product name. Verify the tree expands and highlights the product.
    - Type a known category name. Verify the category is highlighted.
3.  **Interactions**:
    - Click `>` to expand.
    - Click `v` to collapse.
