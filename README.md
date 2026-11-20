# PromptEdit — Pixelforge AI Image Studio
> **Senior Product Engineer Take-Home Assessment**  
> Built with **Laravel 11, Vue 3, Inertia.js, and Tailwind CSS**.

A production-grade, human-crafted full-stack application that integrates with **Pixelforge** (simulated asynchronous AI image provider), computes exact token-based pricing with sub-cent precision, guarantees idempotent webhook delivery and out-of-order state resilience, and implements both bonus stretch goals: a **User Credit Balance & Ledger** and an **Operator Financial Reconciliation Report**.

---

## 🌟 Highlights & Key Engineering Decisions

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

## 🛠️ Tech Stack
- **Backend**: Laravel 11 (PHP 8.2+)
- **Frontend**: Vue 3 (Composition API `<script setup>`), Inertia.js
- **Styling**: Tailwind CSS
- **Testing**: Pest / PHPUnit (100% passing test suite with 45 tests)
- **Database**: SQLite (default zero-config) / MySQL / PostgreSQL ready

---

## 🚀 Getting Started

### 1. Requirements
- PHP 8.2 or higher (with `bcmath`, `curl`, `pdo_sqlite` extensions)
- Composer 2.x
- Node.js 18+ and npm

### 2. Installation
```bash
# Clone the repository
cd ~/Projects/promptedit-assessment

# Install PHP dependencies
composer install

# Install NPM packages and build assets
npm install
npm run build

# Setup environment
cp .env.example .env
php artisan key:generate

# Run database migrations and seed demo accounts
php artisan migrate:fresh --seed
```

### 3. Configure Pixelforge Credentials
Sign up for a free account at [https://pixelforge.promptedit.dev/register](https://pixelforge.promptedit.dev/register) and create an API key:
Edit your `.env` file:
```dotenv
PIXELFORGE_BASE_URL=https://pixelforge.promptedit.dev/v1
PIXELFORGE_API_KEY=pf_live_your_api_key_here
PIXELFORGE_WEBHOOK_SECRET=whsec_your_webhook_signing_secret_here
```

### 4. Running the Development Server
```bash
php artisan serve
```
Open your browser at [http://127.0.0.1:8000](http://127.0.0.1:8000).

---

## 👥 Demo Accounts (Pre-Seeded)

| Account | Email | Password | Role & Initial Balance |
|---|---|---|---|
| **Admin / Operator** | `admin@promptedit.com` | `password` | **Admin** (Operator report, $50.00 credits) |
| **Standard User** | `demo@promptedit.com` | `password` | **User** ($25.00 credits) |

> **Tip**: You can also toggle between Admin and Standard User anytime using the role switch button in the navigation bar!

---

## 🧪 Testing Keywords & Directives

The generation studio UI contains interactive chips that insert Pixelforge test directives into your prompt:
- `[succeed]`: Accepted and guarantees successful generation.
- `[fail]`: Accepted, then fails while processing (`generation.failed`). Billed at **$0.00**; credit hold is 100% refunded.
- `[slow]`: Takes 60 to 90 seconds to process.
- `[duplicate]`: Every webhook event is delivered twice. Verifies deduplication and idempotency.
- `[blocked]`: Rejected at submission with `400 content_policy_violation`. Nothing is created or billed.
- `[overloaded]`: Rejected at submission with `503 overloaded`. The client retries automatically using `Idempotency-Key`.

---

## 🌐 Webhook Tunneling for Local Development

To receive live Pixelforge webhooks on your local machine, expose your local port `8000` via a public tunnel:

### Option A: Cloudflare Tunnel (Recommended, No signup required)
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

Copy the public HTTPS URL from your tunnel (e.g. `https://your-tunnel.ngrok-free.app`) and configure it in the [Pixelforge Dashboard](https://pixelforge.promptedit.dev/):
- **Webhook URL**: `https://your-tunnel.ngrok-free.app/webhooks/pixelforge`
- Copy the **Webhook Signing Secret** (`whsec_...`) into your `.env` as `PIXELFORGE_WEBHOOK_SECRET`.
- Click **"Send test event"** in Pixelforge to verify your webhook endpoint responds `204 No Content`.

*(Note: Even without a webhook tunnel, the application includes automatic fallback polling via `GET /v1/generations/{id}` to synchronize pending generations seamlessly).*

---

## ✅ Automated Test Suite

Run the full test suite with Pest / PHPUnit:
```bash
php artisan test
```

### Test Coverage Highlights
- **`PricingCalculatorTest`**:
  - Verifies exact match against Pixelforge docs worked example (`$0.373875000`).
  - Verifies exact 25% markup (`$0.467343750`).
  - Verifies Unicode characters prompt estimation (character count vs byte count).
  - Verifies actual cost calculation from token usage.
- **`WebhookHandlingTest`**:
  - Verifies HMAC-SHA256 signature verification.
  - Verifies 300-second replay attack window rejection.
  - Verifies `generation.succeeded` records actual cost and settles hold.
  - Verifies `generation.failed` records $0 cost and refunds hold.
  - Verifies duplicate webhook delivery idempotency (`[duplicate]`).
  - Verifies out-of-order `processing` event does not regress `succeeded` state.
- **`CreditLedgerTest`**:
  - Verifies top-up and balance audit trail.
  - Verifies blocking generation submission on insufficient balance.
  - Verifies hold deduction and settlement adjustments.
- **`AdminReportTest`**:
  - Verifies 403 Forbidden for non-admins.
  - Verifies daily and user metric aggregations.
  - Verifies reconciliation against mocked Pixelforge `GET /v1/usage`.
- **`GenerationFlowTest`**:
  - Verifies generation creation, Idempotency-Key dispatch, and immediate refund on policy violations (`400`).

---

## 🚢 Deployment (Laravel Cloud / Railway / Fly.io / Render)

Deploying to Laravel Cloud, Railway, or Fly.io:
1. Push code to your public GitHub repository:
   ```bash
   git init
   git add .
   git commit -m "feat: complete PromptEdit Pixelforge integration assessment"
   git remote add origin https://github.com/<your-username>/promptedit-assessment.git
   git push -u origin main
   ```
2. Create environment variables on your host:
   - `APP_KEY`
   - `APP_URL`
   - `DB_CONNECTION=sqlite` (or PostgreSQL/MySQL)
   - `PIXELFORGE_BASE_URL=https://pixelforge.promptedit.dev/v1`
   - `PIXELFORGE_API_KEY=pf_live_your_deployed_key`
   - `PIXELFORGE_WEBHOOK_SECRET=whsec_your_deployed_secret`
3. Configure the webhook URL in Pixelforge dashboard to point to `https://your-domain.com/webhooks/pixelforge`.
