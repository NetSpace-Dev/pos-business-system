# POS Business Management System — Setup Guide (Antigravity)

This guide walks through getting the backend running inside Antigravity, step by step.

## What you need installed first

1. **Node.js** (v18 or higher) — check with `node -v` in the Antigravity terminal. If missing, install from nodejs.org.
2. **PostgreSQL** — either:
   - Install locally (postgresql.org), OR
   - Use a free hosted DB like **Neon** (neon.tech) or **Supabase** — easier for beginners, no local install needed. Just copy the connection string they give you.

## Step 1 — Open the project in Antigravity

1. Extract the zip file you downloaded.
2. Open the `pos-business-system` folder in Antigravity.
3. Open a terminal inside Antigravity (most IDEs have a built-in terminal — look for "Terminal" in the menu).

## Step 2 — Install backend dependencies

In the terminal:

```bash
cd backend
npm install
```

This downloads all required packages (Express, Prisma, TypeScript, etc.) into `node_modules`.

## Step 3 — Set up your database connection

1. Copy `.env.example` to a new file named `.env`:
   ```bash
   cp .env.example .env
   ```
2. Open `.env` and replace `DATABASE_URL` with your actual PostgreSQL connection string.
   - If using Neon/Supabase: paste the connection string they gave you when you created the database.
   - If using local Postgres: it looks like `postgresql://postgres:yourpassword@localhost:5432/pos_business_db`
3. Change `JWT_SECRET` to any long random string (this secures login tokens).

## Step 4 — Create the database tables

This reads `schema.prisma` and creates all the tables (clients, invoices, inventory, etc.) in your database automatically:

```bash
npx prisma generate
npx prisma migrate dev --name init
```

If this succeeds, you'll see migration files created and a confirmation message. You can verify visually anytime with:

```bash
npx prisma studio
```
This opens a browser window where you can see/edit your database tables directly — useful for checking things look right.

## Step 5 — Run the backend server

```bash
npm run dev
```

You should see:
```
Server running on http://localhost:4000
```

Test it: open `http://localhost:4000/api/health` in your browser. You should see `{"status":"ok",...}`.

## What's next

Once this is running cleanly, the next pieces to build (in order):
1. Auth (login for you/your staff)
2. Client CRUD (add/edit/list clients)
3. Quotation → Invoice flow
4. Inventory + stock deduction logic
5. Dealer tracking
6. Support tickets
7. Reports
8. Frontend (React dashboard)

Tell Claude which one you want built next, and the code will be added into this same `backend/src` structure (routes, controllers, etc.).

## Troubleshooting tips

- **"Cannot find module" errors** → run `npm install` again inside `backend/`.
- **Prisma migration fails** → double check `DATABASE_URL` in `.env` is correct and the database server is reachable.
- **Port already in use** → change `PORT` in `.env` to something else, e.g. `4001`.
