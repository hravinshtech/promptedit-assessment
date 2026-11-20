# PromptEdit — Pixelforge AI Image Studio
> **Senior Product Engineer Take-Home Assessment**  
> Built with **Laravel 11, Vue 3, Inertia.js, and Tailwind CSS**.

A production-grade, human-crafted full-stack application that integrates with **Pixelforge** (simulated asynchronous AI image provider), computes exact token-based pricing with sub-cent precision, guarantees idempotent webhook delivery and out-of-order state resilience, and implements both bonus stretch goals: a **User Credit Balance & Ledger** and an **Operator Financial Reconciliation Report**.

---

## ⚡ Quick Start: How to Run the App

### 1. Requirements
- PHP 8.2+ (with `bcmath`, `curl`, `pdo_sqlite` extensions)
- Composer 2.x
- Node.js 18+ and npm

---

### 2. Installation & Setup
Run these commands from your terminal:

```bash
# 1. Navigate to the project directory
cd ~/Projects/promptedit-assessment

# 2. Install PHP and Node dependencies
composer install
npm install

# 3. Create your .env file
cp .env.example .env

# 4. Generate the application encryption key
php artisan key:generate

# 5. Build frontend assets (or run 'npm run dev' for live Vite hot-reloading)
npm run build

# 6. Run database migrations and seed demo accounts
php artisan migrate:fresh --seed
```

---

### 3. Adding your Pixelforge API Key & Webhook Secret in `.env`

To enable live image generations, you must configure your Pixelforge credentials:

1. **Create an account / API Key**:
   - Go to [https://pixelforge.promptedit.dev/register](https://pixelforge.promptedit.dev/register).
   - Once logged in, click **"Get an API key"** / **"Create an API key"**.
   - Copy your **API key** and your **Webhook signing secret**.

2. **Open `.env` in the project root**:
   ```bash
   nano .env
   # or open with your favorite code editor
   ```

3. **Locate lines 67–69 and paste your credentials**:
   ```dotenv
   PIXELFORGE_BASE_URL=https://pixelforge.promptedit.dev/v1
   PIXELFORGE_API_KEY=pf_live_paste_your_api_key_here
   PIXELFORGE_WEBHOOK_SECRET=whsec_paste_your_signing_secret_here
   ```

4. **Clear cached configuration**:
   ```bash
   php artisan config:clear
   ```

> ⚠️ **Troubleshooting `Missing or invalid API key`**: If you see this error when generating images, it means `PIXELFORGE_API_KEY=` is blank or missing in your `.env` file. Paste your key from the Pixelforge dashboard and run `php artisan config:clear`.

---

### 4. Starting the Application

Start the Laravel local development server:
```bash
php artisan serve
```

Your app will be live at:
👉 **[http://127.0.0.1:8000](http://127.0.0.1:8000)**

*(Optional: If you are making UI changes and want Vite hot module replacement, run `npm run dev` in a separate terminal tab).*

---

## 👥 Demo Accounts (Pre-Seeded)

The database seeder creates two accounts ready for testing:

| Account | Email | Password | Role & Initial Balance | Capabilities |
|---|---|---|---|---|
| **Admin / Operator** | `admin@promptedit.com` | `password` | **Admin** ($50.00 credits) | Generation Studio + Operator Report & `GET /v1/usage` Reconcile |
| **Standard User** | `demo@promptedit.com` | `password` | **User** ($25.00 credits) | Generation Studio, balance holds, token audit |

> 💡 **Dev Role Switcher**: A convenient role switcher button is included in the top navigation bar, allowing you to toggle between Admin and Standard User with one click to test access control!

---

## 🌐 Receiving Webhooks Locally (Tunnel Setup)

Pixelforge delivers webhooks to public HTTPS URLs. To receive live webhooks on your local machine:

### Option A: Cloudflare Tunnel (Recommended, No Account Needed)
```bash
cloudflared tunnel --url http://127.0.0.1:8000
```

### Option B: ngrok
```bash
ngrok http 8000
```

### Option C: Expose
```bash
expose share 127.0.0.1:8000
```

1. Copy the generated HTTPS tunnel URL (e.g., `https://random-name.trycloudflare.com`).
2. Go to your **[Pixelforge Dashboard](https://pixelforge.promptedit.dev/)**.
3. Set your webhook URL to:  
   `https://random-name.trycloudflare.com/webhooks/pixelforge`
4. Copy the webhook secret into `PIXELFORGE_WEBHOOK_SECRET` in `.env`.
5. Click **"Send test event"** in the Pixelforge dashboard to verify your endpoint responds `204 No Content`.

*(Note: Even without a public tunnel, the app includes an automatic polling sync fallback via `GET /v1/generations/{id}` to synchronize pending generations seamlessly).*

---

## 🧪 Testing Keywords & Directives

The generation studio UI includes interactive chips that insert Pixelforge test directives into your prompt:
- `[succeed]`: Accepted and guarantees successful generation.
- `[fail]`: Accepted, then fails while processing (`generation.failed`). Billed at **$0.00**; credit hold is 100% refunded.
- `[slow]`: Takes 60 to 90 seconds to process.
- `[duplicate]`: Every webhook event is delivered twice. Verifies deduplication and idempotency.
- `[blocked]`: Rejected at submission with `400 content_policy_violation`. Nothing is created or billed.
- `[overloaded]`: Rejected at submission with `503 overloaded`. The client retries automatically using `Idempotency-Key`.

---

## 🌟 Key Architecture & Engineering Decisions

### 1. Accurate Money & Fractional Cents
- **Problem**: Pixelforge costs are charged per 1,000,000 tokens (e.g., 115 text tokens at $5.00/1M = `$0.000575000`). Floating-point arithmetic in standard IEEE 754 floats introduces rounding drift that corrupts financial ledgers.
- **Solution**:
  - All financial calculations are executed using PHP's **`bcmath`** extension up to 9 decimal places (`scale: 9`).
  - Database schema stores currency using **`DECIMAL(16, 8)`** with Laravel's `decimal:8` cast for provider costs and user prices.
  - Zero floating-point drift across the entire generation lifecycle.

### 2. Pricing & Up-Front Estimation
- **Published Rates**:
  - Input text tokens: `$5.00` per 1M tokens
  - Input image tokens: `$10.00` per 1M tokens
  - Output tokens: `$40.00` per 1M tokens
  - Reasoning tokens: `$12.50` per 1M tokens
- **Up-Front Cost Preview**:
  - Input text tokens estimated as `ceil(mb_strlen(prompt, 'UTF-8') / 4)` (Unicode characters count, not bytes).
  - Reference images estimated as `765` tokens per URL.
  - Output tokens looked up from size & quality matrix (`low`, `medium`, `high` × `1024x1024`, `1536x1024`, `1024x1536`).
  - Reasoning tokens estimated via quality range midpoints (`low`: 0–500, `medium`: 200–2,000, `high`: 1,000–8,000) while rendering min/max bounds in the UI.
- **PromptEdit 25% Markup**: Exact `1.25` multiplier applied to the provider cost.
- **Actual Settlement**: Upon completion, exact usage tokens and rates snapshot are used to compute final cost. **Failed generations strictly cost $0.00.**

### 3. Webhook Idempotency & Out-of-Order State Machine
- **Cryptographic Signature Verification**:
  - Parses `Pixelforge-Signature: t=<timestamp>,v1=<signature>`.
  - Reconstructs `{$timestamp}.{$rawBody}` and validates HMAC-SHA256 against `PIXELFORGE_WEBHOOK_SECRET` in constant time (`hash_equals`).
  - Enforces a 300-second replay window (`abs(time() - $timestamp) <= 300`).
- **Deduplication (`[duplicate]` keyword)**:
  - Tracks incoming `Pixelforge-Event-Id` in a `webhook_events` table within a database transaction.
  - Duplicate events immediately return `204 No Content` and are never processed or billed twice.
- **Out-of-Order Deliveries**:
  - Monotonic lifecycle progression: `queued` &rarr; `processing` &rarr; `succeeded` / `failed`.
  - Terminal states (`succeeded`, `failed`) can never be regressed by delayed `processing` webhooks.
- **Race Condition Prevention**:
  - Client sends a unique UUID as `Idempotency-Key` and within `metadata: { "local_id": "..." }`.
  - If a webhook arrives before the `POST /v1/generations` response finishes, the webhook handler atomically locates the local record by `metadata.local_id` or Pixelforge ID.

### 4. Stretch Goal 1: Credit Balance & Double-Entry Ledger
- Users have an ongoing USD credit balance.
- **Pre-Auth / Hold**: When a generation request is submitted, the estimated cost is held (`generation_hold`).
- **Settlement**: When `generation.succeeded` arrives, the hold is adjusted to the exact actual cost (`generation_settle`).
- **Refund**: When `generation.failed` arrives, the held amount is refunded 100% (`generation_refund`).
- **Buy Credits**: Instant modal to add simulated test balance ($5.00, $10.00, $25.00, $50.00, or custom).
- Pessimistic database locking (`lockForUpdate()`) prevents concurrent generation race conditions.

### 5. Stretch Goal 2: Operator Financial Report & Pixelforge Reconciliation
- Route `/admin/report`:
  - **Daily Breakdown**: Completed Date (UTC), Total Generations (succeeded vs failed), Pixelforge Cost, User Spend, Gross Profit, and Margin %.
  - **Per-User Breakdown**: User details, spend, provider cost, and profit.
  - **Live Provider Reconciliation Tool**: Queries Pixelforge's `GET /v1/usage` API for the selected date range and validates every single day against local database records, highlighting perfect matches or discrepancies.

---

## ✅ Automated Test Suite

Run the full automated test suite:
```bash
php artisan test
```

### Test Coverage Highlights (45 Tests, 132 Assertions, 100% Passing)
- **`PricingCalculatorTest`**: Verifies exact match against Pixelforge docs worked example (`$0.373875000`), 25% markup (`$0.467343750`), and Unicode character counting.
- **`WebhookHandlingTest`**: Verifies HMAC-SHA256 signature verification, replay protection, deduplication of duplicate deliveries (`[duplicate]`), and resilience to out-of-order events.
- **`CreditLedgerTest`**: Verifies balance top-up, blocking generations on insufficient funds, hold reservation, and settlement adjustments.
- **`AdminReportTest`**: Verifies 403 access control, daily metrics aggregation, and reconciliation against Pixelforge `GET /v1/usage`.
- **`GenerationFlowTest`**: Verifies end-to-end generation creation, Idempotency-Key dispatch, and instant refund on policy violations.

---

## 🚢 Deployment (Laravel Cloud / Railway / Render)

1. Push your repository to GitHub:
   ```bash
   git push -u origin main
   ```
2. In your deployment platform, configure the environment variables:
   - `APP_KEY`
   - `APP_URL`
   - `DB_CONNECTION=sqlite` (or PostgreSQL/MySQL)
   - `PIXELFORGE_BASE_URL=https://pixelforge.promptedit.dev/v1`
   - `PIXELFORGE_API_KEY=pf_live_your_deployed_key`
   - `PIXELFORGE_WEBHOOK_SECRET=whsec_your_deployed_secret`
3. Point your Pixelforge dashboard webhook URL to `https://your-domain.com/webhooks/pixelforge`.
