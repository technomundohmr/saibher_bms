# Project Requirements Document (PRD): Saibher POS

## 1. Project Overview
**Saibher POS** is a lightweight, B2B internal management system designed for small and medium-sized businesses. It functions as an ERP/Point of Sale (POS) solution focused on inventory management, billing, and automated web store generation.

*   **Platform:** Web Application (Desktop-first)
*   **Backend:** Headless Drupal 11 (REST/JSON API)
*   **Authentication:** External Go/Gin service (JWT via REST)
*   **Target User:** Non-technical business owners and staff.

## 2. Design System Specifications
### 2.1 Color Palette
- **Primary Blue (#1242E6):** Active icons, primary buttons, headers.
- **Dark Blue (#052699):** Sidebar background, dark section backgrounds.
- **Light Blue (#C5CFFA):** Hover states, soft backgrounds, dividers.
- **Primary Green (#83E612):** Positive actions, "Paid/Entry" status, secondary CTAs.
- **Dark Green (#4A8A00):** Text on green backgrounds.
- **Primary Red (#E64012):** Alerts, errors, destructive actions.
- **Neutral Grey (#F4F6FF):** Card backgrounds, alternate table rows.

### 2.2 Typography
- **Headings:** Plus Jakarta Sans
- **Body:** Figtree
- **Monospace:** JetBrains Mono (for SKUs, Invoice IDs, codes)

### 2.3 UI/UX Principles
- **Layout:** Fixed, collapsible sidebar with a global top header.
- **Components:** Rounded-xl corners, soft shadows, data tables with pagination/filters.
- **Interaction:** Prefer Drawers/Modals for CRUD operations to maintain context.
- **Tone:** Human, approachable, and helpful (non-technical language).

## 3. Functional Requirements
### 3.1 Authentication
- Login via email/username and password.
- Integration with external Gin-based auth service.
- Token management and auto-redirection.

### 3.2 Core Modules
1.  **Dashboard:** Daily/Monthly sales overview, low stock alerts, and quick inventory movements.
2.  **Billing (Sales/Purchases):** Intelligent product search, SKU scanning, multiple payment methods (Cash, Card, Transfer, Credit), and PDF invoice generation.
3.  **Inventory:** SKU management, stock tracking, minimum stock alerts, and category/supplier filtering.
4.  **Movement Registry:** Automated read-only log of all stock changes (entries, exits, adjustments, losses).
5.  **People Management:**
    *   **Suppliers:** Contact info, associated products, and status.
    *   **Clients:** Credit limits, debt tracking (cartera), and movement history.
6.  **Statistics:** Advanced Recharts-based reporting on sales trends, product rankings, and inventory rotation.
7.  **Automated Web Store:** Toggle to generate a public catalog based on inventory.
8.  **User Roles:** Admin, Cashier, Warehouse, Read-only.

## 4. Technical Stack
- **Frontend:** React + TypeScript
- **Styling:** Tailwind CSS
- **Components:** Radix UI / shadcn/ui patterns
- **Charts:** Recharts
- **Icons:** Lucide React / Material Icons (standardized)

## 5. Success Metrics
- Interface responsiveness (low latency for POS transactions).
- Accessibility compliance (color + text/icons for status).
- Minimal friction in adding/editing inventory records.
