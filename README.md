# CS2 Skin Trading Bot - Implementation Guide
## Symfony + Messenger + Redis

---

## Table of Contents
1. [Project Goal](#project-goal)
2. [The Challenge](#the-challenge)
3. [Core Architecture](#core-architecture)
4. [How It Works](#how-it-works)
5. [Database Tables](#database-tables)
6. [Services Overview](#services-overview)
7. [Message Queue System](#message-queue-system)
8. [The Trading Algorithm](#the-trading-algorithm)
9. [Safety System](#safety-system)
10. [Phase-by-Phase Build Plan](#phase-by-phase-build-plan)

---

## Project Goal

Build an automated trading bot that buys CS2 skins when underpriced and sells them for profit, while **never losing money** through strict risk management.

**Key Constraints:**
- 15% marketplace fee on every sale
- Must make 35%+ price movement to achieve 10% net profit
- Focus on high-liquid items (10+ sales per day, €0.50-€100)
- Maintain minimum balance floor (never go below €10)

**Success Criteria:**
- 60-70% win rate
- 8-12% average profit per trade after fees
- 15-30% monthly ROI
- Full automation with minimal intervention

---

## The Challenge

### The Math Problem

**Without fees:**
```
Buy €10 → Sell €11 → Profit €1 (10%)
```

**With 15% fee:**
```
Buy €10 → Sell €11 → Fee €1.65 → Net €9.35 → LOSS -€0.65
```

**To make 10% profit:**
```
Buy €10 → Must sell €12.94 → Fee €1.94 → Net €11 → Profit €1 (10%)
```

**This means:** You need items to increase 29.4% in price just to make 10% profit!

### The Solution

Only buy items that are:
1. **Significantly underpriced** (20-30% below average)
2. **Highly liquid** (10+ sales per day minimum)
3. **Proven to reach target price** (historically reached target 3+ times in 30 days)
4. **Low risk** (stable prices, good volume, diversified)

---

## Core Architecture

### The Big Picture

```
┌──────────────────────────────────────────────────────────┐
│              CRON JOBS (Every 15-60 min)                 │
│  Scan market → Find opportunities → Send to queue       │
└──────────────────────────────────────────────────────────┘
                         ↓
┌──────────────────────────────────────────────────────────┐
│              REDIS QUEUE (Message storage)               │
│  Messages persist until processed                        │
└──────────────────────────────────────────────────────────┘
                         ↓
┌──────────────────────────────────────────────────────────┐
│         BACKGROUND WORKERS (Always running)              │
│  Process messages → Execute trades → Update DB          │
└──────────────────────────────────────────────────────────┘
```

### Why Messenger + Redis?

**Without Messenger (direct execution):**
- Scanner finds opportunities
- Scanner executes all purchases (takes time)
- If one fails, might skip others
- Scanner blocks for 30+ seconds

**With Messenger:**
- Scanner finds opportunities (5 seconds)
- Scanner sends messages to queue (instant)
- Scanner finishes and exits
- Workers process messages in background
- Failed messages automatically retry
- Can scale workers independently

**Trade-off:** More complexity, but better reliability and scalability.

---

## How It Works

### The Complete Flow

**Every Day (3 AM):**
```
1. SalesHistoryFetcherCommand runs
   → Calls /GetNewestSales30Days for each whitelisted item
   → Stores actual sales data in sale_history table
   → Finishes (5-10 minutes for 30 items)
```

**Every 6 Hours:**
```
2. StatsCalculatorCommand runs
   → Calculates averages, volatility, sales velocity from sale_history
   → Groups by wear tiers (low/mid/high float)
   → Updates sales_stats table
   → Finishes (30 seconds)
```

**Every 15 Minutes:**
```
3. BuyOpportunityScannerCommand runs
   → Loads whitelisted items (your manual list)
   → Searches SkinBaron for each
   → Evaluates each listing (8 checks):
      • Whitelisted and active? ✓
      • 20%+ below wear-adjusted average? ✓
      • Good spread to next listing? ✓
      • Can we afford it? ✓
      • Portfolio limits OK? ✓
      • Historically reaches target? ✓
      • Risk score acceptable? ✓
   → Creates BuyOpportunityMessage for each match
   → Dispatches messages to Redis queue
   → Finishes (10 seconds)
```

**Background (continuous):**
```
4. BuyOpportunityHandler consumes messages
   → Gets message from Redis queue
   → Reserves budget
   → Calls SkinBaron API to purchase
   → Updates inventory table
   → Updates transaction log
   → Refreshes balance
   → Acknowledges message (removed from queue)
   → Waits for next message (2 seconds delay)
```

**Every 30 Minutes:**
```
5. SellOpportunityScannerCommand runs
   → Loads inventory (holding/listed items)
   → For each item:
      • Has it reached target price? → LIST
      • Held too long (7+ days)? → LIST at break-even
      • Price dropped 10%? → STOP-LOSS
      • Already listed but not competitive? → ADJUST price
   → Creates SellOpportunityMessage for each action
   → Dispatches to queue
   → Finishes (10 seconds)
```

**Background:**
```
6. SellOpportunityHandler consumes messages
   → Gets message from queue
   → If action = LIST:
      • Fetch item from SkinBaron inventory
      • Call API to list item
      • Update inventory (status: listed)
   → If action = ADJUST:
      • Call API to change price
      • Update inventory (listedPrice)
   → Acknowledges message
```

**Every 10 Minutes:**
```
7. SalesMonitorCommand runs
   → Calls API to get sold items
   → Matches with inventory
   → Updates inventory (status: sold)
   → Creates transaction record
   → Logs profit/loss
   → Refreshes balance
```

**Every 5 Minutes:**
```
8. BalanceMonitorCommand runs
   → Fetches balance from API
   → Checks against hard floor (€10)
   → Checks against soft floor (€12)
   → If approaching floor: ALERT
   → If at floor: LOCKDOWN (stop buying)
   → Creates balance snapshot
```

---

## Database Tables

### 1. whitelisted_items
**Purpose:** Pre-approved items for trading (manually curated by you)

**Key Fields:**
- market_hash_name: "AK-47 | Redline (Field-Tested)" (includes wear category)
- tier: 1 or 2
- min_discount_pct: Minimum discount to buy (20% for Tier 1, 25% for Tier 2)
- min_spread_pct: Minimum spread to next listing (15%)
- is_active: Whether to trade this item

**Why:** Only trade items you've researched and approved. Manual curation = better control.

**How to populate:** 
- Start with 20-30 liquid items you research
- Each wear category is a separate entry (e.g., whitelist both FT and MW variants separately)
- Use SQL inserts or database seeder
- Add/remove based on performance

**Example entries:**
```sql
INSERT INTO whitelisted_items (market_hash_name, tier, min_discount_pct) VALUES
('AK-47 | Redline (Field-Tested)', 1, 20),
('AK-47 | Redline (Minimal Wear)', 1, 20),
('AWP | Asiimov (Field-Tested)', 1, 20),
('M4A4 | Desolate Space (Field-Tested)', 2, 25);
```

### 2. sale_history
**Purpose:** Time-series data of actual completed sales (NOT listing prices)

**Key Fields:**
- market_hash_name: Full name including wear (e.g., "AK-47 | Redline (Field-Tested)")
- price: Actual sale price
- date_sold: When the item was sold
- fetched_at: When we retrieved this data (for deduplication)

**Why:** Track actual market activity, calculate realistic averages, measure true liquidity.

**Data Source:** `/GetNewestSales30Days` API endpoint (fetched daily)

**Data Retention:** Keep 90 days of sales data.

**Critical Note:** This stores ACTUAL SALES, not current listing prices. This is more reliable because:
- Sales reflect true market value (what people actually paid)
- Can calculate sales velocity (how often items sell)
- Better for historical viability checks (did items actually sell at target price?)

**Wear Categories:** Each wear category is a SEPARATE item:
- "AK-47 | Redline (Factory New)" - Different item
- "AK-47 | Redline (Minimal Wear)" - Different item
- "AK-47 | Redline (Field-Tested)" - Different item
- "AK-47 | Redline (Well-Worn)" - Different item
- "AK-47 | Redline (Battle-Scarred)" - Different item

All items with the same market_hash_name are treated identically (no float-based price adjustments).

### 3. sales_stats
**Purpose:** Aggregated statistics for quick lookups

**Key Fields:**
- market_hash_name: Full name including wear category
- avg_price_7d: 7-day average
- avg_price_30d: 30-day average
- price_volatility: Standard deviation (for risk scoring)
- sales_count_7d: Number of sales in last 7 days
- sales_count_30d: Number of sales in last 30 days

**Why:** Don't recalculate on every decision. Pre-compute and cache.

**Note:** Each wear category (FN/MW/FT/WW/BS) is a separate item with its own statistics.

### 4. inventory
**Purpose:** Track owned items from purchase to sale

**Key Fields:**
- market_hash_name: Full name including wear category
- purchase_price
- purchase_date
- target_sell_price: Calculated at purchase
- status: 'holding' → 'listed' → 'sold'
- sold_price
- sold_date

**Why:** Track P&L, know when to sell, audit trail.

### 5. transactions
**Purpose:** Complete audit trail of all trades

**Key Fields:**
- transaction_type: 'buy' or 'sell'
- market_hash_name
- price
- fee
- net_amount: Positive for sells, negative for buys
- balance_before / balance_after
- status: 'pending' → 'completed' or 'failed'

**Why:** Financial tracking, debugging, performance analysis.

### 6. balance_snapshots
**Purpose:** Track balance over time

**Key Fields:**
- balance: Total from API
- available_balance: Can spend now
- invested_amount: In current inventory
- unrealized_profit: If sold at market price
- realized_profit_today: From completed sales

**Why:** Performance tracking, charts, ROI calculation.

### 7. system_config
**Purpose:** Dynamic configuration

**Key Fields:**
- key: Setting name
- value: Setting value
- description

**Examples:** maintenance_mode, trading_enabled, max_items_per_skin

**Why:** Change behavior without code deployment.

### 8. alerts
**Purpose:** System notifications

**Key Fields:**
- alert_type: 'balance_floor', 'api_error', 'profitable_trade', etc.
- severity: 'low', 'medium', 'high', 'critical'
- message
- resolved: Boolean

**Why:** Track issues, send notifications, audit events.

---

## Services Overview

### SkinBaronClient
**What:** Wrapper for all API calls
**Methods:** 
- getExtendedPriceList(): Current market prices
- getNewestSales30Days(itemName): Historical sales data (CRITICAL!)
- search(): Find listings
- buyItems(): Purchase
- listItems(): List for sale
- editPriceMulti(): Adjust prices
- getSales(): Your sales
- getBalance(): Current balance

**Features:** Rate limiting, circuit breaker, retry logic, caching

**Key Endpoint - getNewestSales30Days():**
```php
$sales = $client->getNewestSales30Days(
    itemName: 'AK-47 | Redline (Field-Tested)',
    statTrak: false
);
// Returns: [
//   ['itemName' => '...', 'price' => 30.00, 'wear' => 0.32, 'dateSold' => '2025-10-11'],
//   ['itemName' => '...', 'price' => 32.89, 'wear' => 0.29, 'dateSold' => '2025-10-11'],
//   ... (60 sales)
// ]
```

### BudgetManager
**What:** Track balance and enforce limits
**Key Methods:**
- canAfford(price): Check if purchase allowed
- getTradingState(): 'normal', 'conservative', 'emergency', 'lockdown'
- refreshBalance(): Update from API
**Features:** Hard floor (€10), soft floor (€12), reserve 20% cash

### BuyDecisionEngine
**What:** Analyze market and find opportunities
**Process:**
1. Load whitelisted items (your manual list)
2. Search SkinBaron for each
3. Evaluate each result (8 checks total)
4. Calculate wear-adjusted discount
5. Calculate risk score
6. Return approved opportunities
**Output:** Array of BuyOpportunity DTOs

### SellDecisionEngine
**What:** Decide when to sell inventory
**Logic:**
- Profit target met? → LIST
- Held 7+ days? → LIST at break-even
- Price dropped 10%? → STOP-LOSS
- Not competitive? → ADJUST price
**Output:** Array of SellOpportunity DTOs

### BuyExecutor
**What:** Execute purchases
**Process:**
1. Check canAfford() again (state may have changed)
2. Reserve budget
3. Create pending transaction
4. Call API to buy
5. Update inventory
6. Release reservation
7. Refresh balance

### SellExecutor
**What:** List or adjust prices
**Actions:**
- LIST: Fetch from SkinBaron inventory, call ListItems API
- ADJUST: Call EditPriceMulti API
**Updates:** Inventory table with status and prices

### StatsCalculator
**What:** Calculate market statistics from sale_history
**Calculations:**
- Average prices (7-day, 30-day) per market_hash_name
- Standard deviation (volatility)
- Sales velocity (count per day)
**Updates:** sales_stats table
**Frequency:** Every 6 hours

**Note:** Each wear category is calculated independently since they're separate items.

### RiskScorer
**What:** Assess opportunity risk (0-10 scale)
**Factors:**
- High volatility: +3 points
- Near 30-day low: +2 points
- Already own some: +1.5 per item
**Usage:** Filter high-risk opportunities

### AlertManager
**What:** Send notifications
**Channels:** Webhook (Slack/Discord), Email, SMS (optional)
**Triggers:** Floor breach, API errors, profitable trades, system errors

---

## Message Queue System

### Why Messages?

**Problem:** If a cron job directly executes 10 purchases and the 3rd one fails, what happens?
**Solution:** Send each opportunity as a message to a queue. Workers process them independently.

**Benefits:**
1. Scanner finishes quickly (non-blocking)
2. Failed purchases don't affect others
3. Automatic retry on network errors
4. Can inspect failed messages
5. Can scale workers (multiple in parallel)

### How Messenger Works

**1. Dispatch Message:**
```
In BuyOpportunityScannerCommand:
- Create BuyOpportunityMessage with opportunity data
- Dispatch to message bus
- Message sent to Redis
- Command continues (non-blocking)
```

**2. Message Stored in Redis:**
- Redis Streams used (persistent, reliable)
- Message serialized as JSON
- Stays in queue until processed

**3. Worker Consumes Message:**
```
BuyOpportunityHandler:
- Receives message from queue
- Calls BuyExecutor->execute()
- If exception: message stays in queue, retry later
- If success: message acknowledged and removed
```

**4. Automatic Retry:**
- Attempt 1: Immediate
- Attempt 2: After 1 second (if failed)
- Attempt 3: After 2 seconds
- Attempt 4: After 4 seconds
- After 4 failures: Move to "failed" table

**5. Failed Message Handling:**
- Can inspect: What message, what error
- Can retry manually
- Can remove if permanently invalid

### Message Classes

**BuyOpportunityMessage:**
- saleId, marketHashName, currentPrice, targetSellPrice, expectedProfit, riskScore, timestamp

**SellOpportunityMessage:**
- inventoryId, saleId, marketHashName, action (list/adjust), listPrice, reason, timestamp

**Messages are pure data objects (DTOs). No logic.**

### Workers

**Running Workers:**
- Start: `php bin/console messenger:consume async`
- Production: Use Supervisor to keep worker running
- Supervisor restarts worker if it crashes
- Worker auto-restarts every hour (prevents memory leaks)

**Worker Process:**
1. Connects to Redis
2. Waits for messages
3. Processes message (calls handler)
4. Acknowledges or retries
5. Repeats forever

**Scaling:**
- Start with 1 worker
- Can run 2-3 workers in parallel if needed
- API rate limits may prevent effective parallelization

---

## The Trading Algorithm

### Buy Decision Algorithm

**Input:** Search result from SkinBaron
**Output:** BuyOpportunity or null (rejected)

**Step 1: Whitelist Check**
```
Is item in whitelisted_items table?
Is isActive = true?
→ No: REJECT (not approved for trading)
→ Yes: Continue
```

**Step 2: Discount Check**
```
discountPct = ((avgPrice7d - currentPrice) / avgPrice7d) * 100
Is discountPct >= minDiscountPct?
→ No: REJECT (not enough discount)
→ Yes: Continue

Example:
marketHashName = "AK-47 | Redline (Field-Tested)"
avgPrice7d = €35.50
currentPrice = €28.00
discountPct = ((35.50 - 28.00) / 35.50) * 100 = 21.1%
minDiscountPct = 20%
→ PASS (21.1% >= 20%)

Note: All Field-Tested variants are treated the same. Float value doesn't matter.
```

**Step 3: Spread Check**
```
Fetch next cheapest listing from API
spread = ((nextPrice - currentPrice) / currentPrice) * 100
Is spread >= minSpreadPct?
→ No: REJECT (no room for profit)
→ Yes: Continue

Why: If next listing is €28.10 and current is €28.00,
there's minimal spread. Can't resell profitably.
```

**Step 4: Budget Check**
```
Call budgetManager.canAfford(currentPrice)
Checks:
- Is balance > hardFloor + price?
- Is price <= maxRiskPerTrade * balance?
- Is totalInvested + price <= maxTotalExposure * balance?
→ Any fail: REJECT (budget constraints)
→ All pass: Continue
```

**Step 5: Portfolio Check**
```
Count inventory items with same marketHashName
Is count < 3?
→ No: REJECT (already own max of this item)
→ Yes: Continue
```

**Step 6: Historical Viability**
```
targetSellPrice = (currentPrice * 1.10) / 0.85
Query sale_history: How many times did price >= targetSellPrice in last 30 days?
Is count >= 3?
→ No: REJECT (target price unrealistic)
→ Yes: Continue

Example:
marketHashName = "AK-47 | Redline (Field-Tested)"
currentPrice = €28.00
targetSellPrice = (28.00 * 1.10) / 0.85 = €36.24
Check: Has "AK-47 | Redline (Field-Tested)" sold at €36.24+ at least 3 times in last 30 days?
```

**Step 7: Risk Assessment**
```
Calculate risk score (0-10)
Factors:
- priceVolatility > 2.0: +3
- currentPrice within 5% of 30-day low: +2
- Current inventory count * 1.5

totalRiskScore = sum of factors (capped at 10)
Is riskScore <= 7?
→ No: REJECT (too risky)
→ Yes: ACCEPT
```

**Step 8: Create Opportunity**
```
opportunity = new BuyOpportunity(
    saleId: item.id,
    marketHashName: item.marketHashName,
    currentPrice: item.price,
    targetSellPrice: calculated above,
    expectedProfit: (targetSellPrice * 0.85) - currentPrice,
    riskScore: calculated above
)
return opportunity
```

### Sell Decision Algorithm

**Input:** Inventory item
**Output:** SellOpportunity or null (hold)

**If Status = 'holding' (not yet listed):**

```
1. Get current market price (from cache or stats)
2. Calculate holdDays = days since purchase

3. Check: currentPrice >= targetSellPrice?
   → Yes: ACTION = LIST at targetSellPrice
         REASON = "Profit target met"
   → No: Continue

4. Check: holdDays >= 7?
   → Yes: breakEvenPrice = purchasePrice / 0.85
          If currentPrice >= breakEvenPrice:
            ACTION = LIST at breakEvenPrice
            REASON = "Held too long, listing at break-even"
   → No: Continue

5. Check: Has price dropped 10%+?
   priceDrop = ((purchasePrice - currentPrice) / purchasePrice) * 100
   → Yes: ACTION = LIST at currentPrice * 0.95
         REASON = "Stop-loss triggered"
   → No: Continue

6. Check: Is it weekend AND price up 5%+?
   → Yes: ACTION = LIST at min(targetSellPrice, currentPrice * 0.98)
         REASON = "Weekend surge"
   → No: Continue

7. Default: ACTION = HOLD
           REASON = "Waiting for better price"
```

**If Status = 'listed' (already on market):**

```
1. Fetch cheapest competing listing (same market_hash_name)
2. Calculate holdDays

3. Check: Is our price competitive?
   listedPrice <= cheapest * 1.02?
   → Yes: ACTION = HOLD
         REASON = "Still competitive"
   → No: Continue

4. Check: holdDays >= 3 AND not cheapest?
   → Yes: newPrice = max(cheapest * 0.98, breakEvenPrice)
         ACTION = ADJUST to newPrice
         REASON = "Undercutting competition"
   → No: Continue

5. Default: ACTION = HOLD
           REASON = "Recently listed"
```

### Target Price Calculation

**Why this formula?**

```
Target = (Purchase * 1.10) / 0.85

Breaking it down:
- Purchase * 1.10 = We want 10% profit
- / 0.85 = Account for 15% fee

Example:
Purchase = €10
Target = (10 * 1.10) / 0.85 = €12.94

Verification:
Sell at €12.94
Fee: €12.94 * 0.15 = €1.94
Net: €12.94 - €1.94 = €11.00
Profit: €11.00 - €10.00 = €1.00 (10%) ✓
```

---

## Safety System

### The Floor System

**Hard Floor (€10):**
- Absolute minimum balance
- If balance <= hardFloor:
  - State: LOCKDOWN
  - All buying STOPS
  - Only selling allowed
  - CRITICAL alert sent
  - Manual intervention required

**Soft Floor (€12):**
- Warning threshold (20% above hard floor)
- If balance <= softFloor:
  - State: EMERGENCY
  - Buying disabled
  - Only selling allowed
  - HIGH alert sent

**Conservative Zone (€12-€14.40):**
- Between soft floor and soft floor * 1.2
- If balance in this range:
  - State: CONSERVATIVE
  - Buying allowed but restricted
  - Reduce position sizes by 50%
  - Require higher profit margins (+5%)
  - MEDIUM alert sent

**Normal Zone (€14.40+):**
- Comfortable trading range
- State: NORMAL
- Standard rules apply
- Full automation

### Budget Constraints

**Per-Trade Limit:**
```
maxPerTrade = balance * 0.05  (5%)

Example:
Balance = €100
Max per trade = €5
```

**Total Exposure Limit:**
```
maxTotalExposure = balance * 0.70  (70%)

Example:
Balance = €100
Max invested = €70
```

**Minimum Reserve:**
```
minReserve = balance * 0.20  (20%)

Example:
Balance = €100
Must keep €20 liquid
Available = €80 maximum
```

**Combined Check:**
```
availableBalance = balance - (balance * 0.20) - reserved

Example:
Balance = €100
Reserved for pending orders = €10
Available = 100 - 20 - 10 = €70
```

### Budget Reservation System

**Problem:** Concurrent purchases could overspend
**Solution:** Reserve budget before purchase

**Process:**
```
1. Scanner finds opportunity: €8.50 item
2. Dispatch message
3. Handler starts processing
4. Reserve €8.50 in BudgetManager
5. Call API to purchase
6. If success: Release reservation, money actually spent
7. If failure: Release reservation, money still available
```

### Circuit Breaker

**Purpose:** Stop calling API when it's down

**States:**
- **Closed:** Normal, requests allowed
- **Open:** Too many failures, block all requests
- **Half-Open:** Testing recovery

**Logic:**
```
1. API call fails → failureCount++
2. If failureCount >= 10 → State = OPEN
3. While OPEN: Block all requests (5 minutes)
4. After 5 minutes: State = HALF-OPEN, allow 1 test
5. Test succeeds → State = CLOSED
6. Test fails → State = OPEN again
```

---

## Phase-by-Phase Build Plan

### Phase 1: Foundation (Week 1)
**Goal:** Basic infrastructure working

**Build:**
1. Create Symfony project
2. Create all 8 database tables (entities + migrations)
3. Build SkinBaronClient service (all API methods, error handling)
4. Build RateLimiter service
5. Build CircuitBreaker service
6. Setup Redis connection
7. Setup logging (Monolog)

**Test:**
- Database connection ✓
- API calls working ✓
- Rate limiter delays requests ✓
- Circuit breaker opens after failures ✓
- Logs writing ✓

**Deliverable:** Working API client with safety features

---

### Phase 2: Data Collection (Week 2)
**Goal:** Build sales history database from actual market data

**Build:**
1. SalesHistoryFetcherCommand (fetch sales data daily via /GetNewestSales30Days)
2. StatsCalculatorCommand (calculate statistics every 6 hours)
3. Manually populate whitelisted_items table (20-30 items you've researched)
4. Setup cron jobs

**Commands:**
```bash
# Daily at 3 AM - Fetch historical sales
0 3 * * * php /path/to/bin/console app:sales-history:fetch

# Every 6 hours - Calculate statistics
0 */6 * * * php /path/to/bin/console app:stats:calculate
```

**Test:**
- Cron jobs running ✓
- Sales data accumulating in sale_history ✓
- Stats calculated correctly ✓
- Whitelist populated ✓

**Run for 3-5 days to build history**

**Key Insight:** After 3-5 days, you'll have:
- 180-300 sales records (for 30 liquid items)
- 7-day and 30-day averages per item
- Volatility metrics
- Sales velocity data

**Deliverable:** Sales database with 3-5 days of data, statistics ready, whitelist populated

---

### Phase 3: Decision Logic (Week 3)
**Goal:** Identify opportunities correctly

**Build:**
1. BudgetManager service (track balance, enforce floors)
2. RiskScorer service (calculate risk scores)
3. BuyDecisionEngine service (full algorithm, all 8 checks)
4. SellDecisionEngine service (sell logic, all conditions)
5. BuyOpportunityScannerCommand (dry-run only)
6. SellOpportunityScannerCommand (dry-run only)

**Test (Dry-Run Mode):**
- Scanner finds opportunities ✓
- Discounts calculated correctly ✓
- Logs show decisions and reasons ✓
- Review: Are decisions sensible? ✓
- Tune thresholds ✓

**Don't execute trades yet - just log decisions**

**Example Log Output:**
```
[INFO] Evaluating: AK-47 | Redline (Field-Tested) @ €28.00
[DEBUG] Avg price (7d): €35.50
[DEBUG] Discount: 21.1% (need 20%) - APPROVED
[DEBUG] Risk score: 4.5 - APPROVED
[INFO] Buy opportunity created - dispatching to queue (DRY-RUN)
```

**Deliverable:** Decision engines working, opportunities identified correctly

---

### Phase 4: Message Queue (Week 4)
**Goal:** Setup async execution

**Build:**
1. Configure Symfony Messenger (Redis transport, retry strategy)
2. Create message classes (BuyOpportunityMessage, SellOpportunityMessage)
3. Create handlers (BuyOpportunityHandler, SellOpportunityHandler)
4. Build BuyExecutor service (execute purchases)
5. Build SellExecutor service (list/adjust items)
6. Setup Supervisor for workers
7. Update scanners to dispatch messages

**Test:**
- Messages dispatched to Redis ✓
- Workers pick up messages ✓
- Handlers process correctly ✓
- Failed messages retry ✓

**Deliverable:** Working queue system

---

### Phase 5: Trading (Small Scale) (Week 5)
**Goal:** Execute real trades with €20-50

**Build:**
1. SalesMonitorCommand (detect sold items, log profits)
2. BalanceMonitorCommand (track balance, enforce floors)
3. AlertManager service (webhooks, emails)

**Setup:**
- Start with €20-50 balance
- Hard floor €10, soft floor €12
- Enable all cron jobs
- Start workers

**Monitor Closely:**
- Watch every trade
- Check logs multiple times daily
- Tune parameters

**Run for 1-2 weeks**

**Success Criteria:**
- 5+ trades
- Win rate >50%
- No floor breaches
- Decision logic working correctly

**Deliverable:** Bot trading successfully at small scale

---

### Phase 6: Scale & Optimize (Week 6+)
**Goal:** Increase budget and optimize

**Optimize:**
1. Review logs for patterns
2. Analyze which items are most profitable
3. Update whitelist (remove bad, add good items)
4. Tune parameters (discounts, risk thresholds)

**Scale Budget:**
- Week 6: €50 → €100
- Week 7: €100 → €200
- Week 8: €200 → €500
- Month 3: €500+

**Success:** Win rate 60-70%, ROI 15-30% monthly, running autonomously

**Deliverable:** Production bot with good performance

---

## Key Success Factors

### 1. Start Small
Begin with €50-100, prove it works, then scale.

### 2. Log Everything
Every decision, every trade. Review daily. Learn from data.

### 3. Be Patient
Not every day has opportunities. Monthly view matters.

### 4. Stay Conservative
Never breach floor. Maintain reserve. Diversify.

### 5. Treat Wear Categories Separately
Each wear category (FN/MW/FT/WW/BS) is a completely different item. Don't compare prices across categories.

### 6. Iterate
Review weekly, adjust parameters, continuous improvement.

---

## Expected Timeline

**Week 1:** Foundation complete
**Week 2:** Sales data collected (3-5 days minimum)
**Week 3:** Decision logic working
**Week 4:** Queue system ready
**Week 5:** First trades executed
**Week 6:** Optimization begins
**Week 8:** Running smoothly
**Week 12:** Scaled to target size

**Total: 6-12 weeks from start to full automation**

---

## Remember

**This is a marathon, not a sprint.**

- Small consistent profits compound
- Safety first, always
- Each wear category is a separate item (don't compare FT to MW)
- Log and learn
- Iterate based on data
- Scale gradually