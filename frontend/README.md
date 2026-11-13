# Finance System: frontend

React + Vite + Tailwind CSS + Recharts + Axios. It talks to the Laravel API in `../backend`.

## Run it (Windows, PowerShell)
1. Start MySQL in XAMPP, then start the API in one window:
   ```powershell
   cd C:\xampp\htdocs\finance-system\backend
   php artisan serve
   ```
2. In a second window:
   ```powershell
   cd C:\xampp\htdocs\finance-system\frontend
   npm install
   npm run dev
   ```
3. Open **http://localhost:5173** (use `localhost`, not `127.0.0.1`) and sign in with the owner account you created with `php artisan finance:create-owner`.

In development the browser only talks to `localhost:5173`; Vite forwards `/api` and `/sanctum` to `http://127.0.0.1:8000`, so the login cookie belongs to one address and no extra CORS setup is needed. The backend `.env` must contain `SANCTUM_STATEFUL_DOMAINS=localhost:5173` (it already does if you followed Phase 3).

## Commands
| Command | What it does |
|---|---|
| `npm run dev` | Development server with hot reload |
| `npm test` | Runs the 125 automated tests |
| `npm run build` | Production build into `dist` |

## How it is organised
- `src/pages`: one file per menu item (Dashboard, Business sales, Inventory, Business expenses, Personal finance, Accounts, Budgets, Savings, Debts, Reports, Settings).
- `src/components`: reusable parts (table with sorting and paging, schema-driven form dialog, confirm dialog, date-range filter, charts, sale and stock forms).
- `src/lib`: money (exact, string-based), dates and error helpers. Money is never calculated with floating-point numbers; numbers are used only to draw charts.
- `src/api`: the Axios client and page helpers.
