# Product Guidelines

## User Experience (UX) Philosophy
- **Efficiency First:** The interface is optimized for high-frequency data entry and retrieval tasks performed by expert users.
- **Information Density:** Screens should maximize the display of relevant technical data (tables, trees) without unnecessary padding, while maintaining readability.
- **Immediate Feedback:** Actions like search filtering, template compilation, and data saving should provide immediate visual confirmation.
- **Seamless Navigation:** Users should easily transition between the catalog view, specification search, and templating tools via a persistent sidebar.

## Visual Design & Tone
- **Aesthetic:** Technical, utilitarian, and clean. The design should recede to let the data stand out.
- **Color Palette:**
  - **Neutral Backgrounds:** White/Light Gray for content areas to ensure high contrast for text.
  - **Functional Colors:** Use distinct colors for actions (e.g., primary blue for "Compile/Save", danger red for "Delete") and status indicators (e.g., green for "Success", amber for "Warning").
  - **Typography:** Monospace fonts for code editors (Typst/LaTeX) and tabular data (SKUs, IDs). Sans-serif for UI labels and navigation.
- **Components:** Standardized Bootstrap 5 components (tables, buttons, forms, modals) for consistency and familiarity.

## Code & Data Standards
- **Datasheet Quality:** Generated PDFs must meet professional publishing standards, with high-resolution vector assets and precise typography.
- **Data Integrity:** Strict validation on inputs (e.g., CSV imports, SKU uniqueness) to prevent catalog corruption.
- **Performance:** UI interactions (search, hierarchy expansion) should be snappy (<200ms) even with large datasets.
