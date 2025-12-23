# Technology Stack

## Backend
- **Language:** PHP 8.x (procedural endpoints with service class architecture)
- **Database:** MySQL (interfaced via `mysqli`)
- **Autoloading:** Custom file-based autoloader (`app/bootstrap.php`)
- **API Style:** REST-like JSON endpoints under `public/api/`

## Frontend
- **Markup:** HTML5 (static files in `public/`)
- **Styling:** CSS3, Bootstrap 5
- **Scripting:** Vanilla JavaScript, jQuery (for AJAX/DOM manipulation)
- **Architecture:** Client-side rendering via JSON API consumption

## Tooling & External Binaries
- **PDF Generation:** 
  - Typst (via `bin/typst.exe` or system PATH)
  - LaTeX (via `pdflatex` in system PATH)
- **CLI/Workflows:** PowerShell
- **Development Server:** Laragon (Nginx/PHP-FPM)

## Infrastructure & Storage
- **Logging:** File-based JSON logging (`storage/logs/app.log`)
- **Asset Storage:** Local filesystem (`storage/`, `public/storage/`)
- **Version Control:** Git
