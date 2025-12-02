# Repository Specification

## Architecture and Technology Choices
- PHP 8 style procedural endpoints backed by small service classes (`app/*`) for catalog traversal, spec search facets/products, and document templating (LaTeX/Typst); chosen for rapid iteration with minimal framework overhead.
- MySQL (via `mysqli`) as the source of truth for catalog hierarchies, products, custom fields, templates, and variables; simple SQL over ORM to keep performance predictable.
- Static HTML/JS in `public` and `assets` for operator-facing tools; communicates with JSON APIs under `public/api`.
- PDF generation uses external binaries (`pdflatex`, `bin/typst.exe` fallback to PATH) writing to `public/storage/*`; logging goes to `storage/logs/app.log` with correlation IDs.
- Constraints: Windows-first PowerShell workflows; no Composer/runtime dependency manager present; file-based autoload via `app/bootstrap.php`.

## System Context
```mermaid
graph LR
    Operator[Operator UI<br/>catalog/spec/templating pages]
    API[PHP API Layer<br/>public/api/*]
    DB[(MySQL)]
    Binaries[PDF Binaries<br/>pdflatex & typst.exe]
    Storage[(Storage<br/>storage/, public/storage/)]
    Operator -->|HTTP/JSON| API
    API -->|SQL| DB
    API -->|spawn| Binaries
    API -->|write/read| Storage
    Binaries -->|emit PDFs| Storage
```

## Container / Deployment Overview
```mermaid
graph TD
    subgraph Host (Windows/Laragon)
        nginx[Web Server] --> phpFpm[PHP Runtime]
        phpFpm --> apiLayer[public/api endpoints]
        apiLayer --> services[App Services]
        services --> mysql[MySQL]
        services --> pdfBin[pdflatex/typst.exe]
        services --> storage[(storage & public/storage)]
    end
```

## Module Relationship (Backend / Frontend)
```mermaid
graph LR
    subgraph Frontend
        catalogUI[catalog_ui.html/js]
        specSearchUI[spec-search.html/js]
        templatingUI[latex/typst html/js]
    end
    subgraph Backend
        support[Support: Config/Db/Logger/Request/Response]
        catalogSvc[CatalogService]
        specSvc[SpecSearchService]
        latexSvc[LatexService]
        typstSvc[TypstService]
    end
    catalogUI -->|XHR JSON| catalogSvc
    specSearchUI -->|XHR JSON| specSvc
    templatingUI -->|XHR JSON| latexSvc
    templatingUI -->|XHR JSON| typstSvc
    catalogSvc --> support
    specSvc --> support
    latexSvc --> catalogSvc
    typstSvc --> catalogSvc
```

## Data Model
- Core tables: `category` (tree of categories/series), `product` (linked to series), `series_custom_field` (field metadata, scope series/product attributes), `product_custom_field_value`, `latex_templates`/`latex_variables`, `typst_templates`/`typst_variables`.
- Files: generated PDFs in `public/storage/latex-pdfs` and `public/storage/typst-pdfs`; CSV imports in `storage/csv`.

### ER Diagram
```mermaid
erDiagram
    category ||--o{ category : parent
    category ||--o{ product : series_id
    category ||--o{ series_custom_field : series_id
    series_custom_field ||--o{ product_custom_field_value : series_custom_field_id
    product ||--o{ product_custom_field_value : product_id
    category {
        int id
        int parent_id
        string name
        string type
    }
    product {
        int id
        int series_id
        string sku
        string name
    }
    series_custom_field {
        int id
        int series_id
        string field_key
        string label
        string field_type
        string field_scope
    }
    product_custom_field_value {
        int id
        int product_id
        int series_custom_field_id
        string value
    }
    latex_templates {
        int id
        string title
        text latex_code
        bool is_global
        int series_id
    }
    latex_variables {
        int id
        string field_key
        string field_type
        string field_value
        bool is_global
        int series_id
    }
    typst_templates {
        int id
        string title
        text typst_content
        bool is_global
        int series_id
    }
    typst_variables {
        int id
        string field_key
        string field_type
        string field_value
        bool is_global
        int series_id
    }
```

## Key Processes
- Catalog hierarchy/search: build tree from `category`, attach products; search by name/SKU.
- Spec search: root/product category discovery, facet construction from custom fields, filtered product list.
- Template compilation: fetch series metadata + products, substitute into LaTeX/Typst, compile via external binary, expose PDF URL; Typst data header sanitizes associative keys to Typst-safe identifiers (non-alphanumeric replaced with `_`, leading digits prefixed) and deduplicates collisions to prevent invalid variable names.
- Operator navigation: a shared sidebar on each operator UI exposes Spec Search, Catalog UI, CSV tools, Global Typst Template, and Series Typst Template to avoid broken links (deprecated Global LaTeX link removed).

### Operator UI Navigation Map
```mermaid
graph TD
    Sidebar[Shared Sidebar] --> SpecSearch[spec-search.html<br/>Spec Search]
    Sidebar --> CatalogUI[catalog_ui.html<br/>Catalog UI]
    Sidebar --> CatalogCSV[catalog-csv.html<br/>CSV Tools]
    Sidebar --> GlobalTypst[global_typst_template.html<br/>Global Typst]
    Sidebar --> SeriesTypst[series_typst_template.html<br/>Series Typst]
```

### Sequence (Typst Compile)
```mermaid
sequenceDiagram
    participant UI as UI
    participant API as /api/typst/compile.php
    participant TypstSvc as TypstService
    participant Catalog as CatalogService
    participant DB as MySQL
    participant Bin as typst.exe
    UI->>API: POST typst code + optional seriesId
    API->>TypstSvc: compileTypst(code, seriesId)
    TypstSvc->>Catalog: getSeriesDetails(seriesId)
    Catalog->>DB: SELECT series/products/custom_fields
    DB-->>Catalog: data
    Catalog-->>TypstSvc: hydrated series data
    TypstSvc->>Bin: typst compile temp.typ -> pdf
    Bin-->>TypstSvc: PDF path
    TypstSvc->>Storage: move pdf to public/storage/typst-pdfs
    API-->>UI: { success, url }
```

### Flowchart (Spec Search Filtering)
```mermaid
flowchart TD
    A[Receive categoryIds + filters] --> B{categoryIds empty?}
    B -- Yes --> C[Return []]
    B -- No --> D[Fetch products + series]
    D --> E{Series filter present?}
    E -- Yes --> F[Apply IN clause on series names]
    E -- No --> G[Skip]
    F --> H
    G --> H
    H{Attribute filters?} -->|Yes| I[Add EXISTS per field_key/value]
    H -->|No| J[Use base query]
    I --> K[Limit 500, execute]
    J --> K
    K --> L[Hydrate custom field values]
    L --> M[Return product list]
```

### State Diagram (Template Lifecycle)
```mermaid
stateDiagram-v2
    [*] --> Draft
    Draft --> Saved : POST template
    Saved --> Updated : PUT template
    Updated --> Compiled : Compile request
    Compiled --> Saved : Edit without delete
    Saved --> Deleted : DELETE template
    Compiled --> Deleted
```

### Class Diagram (Key Backend Classes)
```mermaid
classDiagram
    class CatalogService {
        -mysqli db
        +getHierarchy()
        +search(query)
        +getSeriesDetails(seriesId)
    }
    class SpecSearchService {
        -mysqli db
        +getRootCategories()
        +getProductCategories(rootId)
        +getFacets(categoryIds)
        +getProducts(categoryIds, filters)
    }
    class LatexService {
        -mysqli db
        -string pdfUrlPrefix
        +listGlobalTemplates()
        +compileLatex(latex, seriesId)
        +listGlobalVariables()
    }
    class TypstService {
        -mysqli db
        -string pdfUrlPrefix
        +listGlobalTemplates()
        +compileTypst(code, seriesId?)
        +listGlobalVariables()
    }
    class Support {
        +Config::get()
        +Db::connection()
        +Logger
        +Request
        +Response
    }
    CatalogService --> Support
    SpecSearchService --> Support
    LatexService --> CatalogService
    TypstService --> CatalogService
```

### Pseudocode (Critical Paths)
```text
Catalog.search(query):
  if query empty -> return []
  search categories name LIKE %query%
  search products name/SKU LIKE %query%
  map ids/parents/type into flat list

SpecSearch.getProducts(categoryIds, filters):
  build base JOIN query for products under series in categoryIds
  apply series name IN filter when provided
  for each attribute filter: add EXISTS against product_custom_field_value + series_custom_field
  LIMIT 500, run query, collect product ids
  hydrate attributes for those ids and merge into product rows

TypstService.compileTypst(code, seriesId?):
  header = generateDataHeader(seriesId)
    - inject globals, series metadata, and products
    - sanitize Typst keys (strip invalid chars, prefix leading digits, dedupe clashes)
  write header + code to temp .typ file
  call typst.exe compile input output
  on success move PDF to public/storage/typst-pdfs, return URL/path; else throw RuntimeException
```

## Key Processes (continued) and Constraints
- CSV lifecycle: imports stored under `storage/csv`, catalog truncation locked via `config/app.php` token/lock key.
- Logging: `App\Support\Logger` writes JSON lines to `storage/logs/app.log` with correlation ID per request.
- Security baseline: validate/escape SQL inputs, forbid logging secrets/PII, generated PDFs publicly accessible under `public/storage`.
- Editor UX: Typst/LaTeX template editors wrap `textarea#latexSource` with a line-numbered view (monospace, synchronized scroll) to simplify debugging and support copy/paste without losing positioning.
- Saved Typst templates: table-level “PDF Download” triggers a fresh compile when no stored `downloadUrl` exists, then opens the generated PDF URL.
