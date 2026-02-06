# Shopmata AI Agents

Shopmata's AI Agents work autonomously in the background to help you manage your store more efficiently. They monitor your inventory, adjust pricing, research new items, and keep you informed—all while you focus on what matters most: running your business.

---

## What Are Agents?

Agents are intelligent assistants that automate repetitive tasks in your store. Unlike traditional automation that follows rigid rules, Shopmata agents use AI to make smart decisions based on your preferences and market conditions.

**Key Benefits:**
- **Save Time**: Agents work 24/7, handling tasks that would take hours of manual effort
- **Make Money**: Optimize pricing and reduce dead stock to improve your margins
- **Stay Informed**: Get daily digests of what your agents accomplished
- **Stay in Control**: Choose which actions happen automatically vs. require your approval

---

## Available Agents

### 1. Auto-Pricing Agent

**What it does:** Monitors market prices and keeps your inventory competitively priced.

**How it helps you:**
- Automatically checks eBay sold listings, Google Shopping, and other sources for current market values
- Identifies items that are overpriced (losing sales) or underpriced (losing profit)
- Suggests or applies price adjustments based on your pricing strategy

**Example scenario:**
> You have a vintage watch listed at $450. The Auto-Pricing Agent discovers similar watches are now selling for $600-$700 on eBay. It suggests raising your price to $595 to capture the increased market value.

**Configuration options:**
| Setting | Description | Default |
|---------|-------------|---------|
| Run Frequency | How often to check prices | Daily |
| Price Check Threshold | Days since last price check before re-checking | 30 days |
| Auto-Adjust Threshold | Price difference % that triggers adjustment | 10% |
| Approval Required Above | Price changes above this $ amount need your approval | $100 |
| Pricing Strategy | Competitive, premium, or budget positioning | Competitive |

---

### 2. Dead Stock Agent

**What it does:** Identifies slow-moving inventory and schedules progressive markdowns to move stale items.

**How it helps you:**
- Finds items sitting too long without selling
- Automatically schedules price reductions to move inventory
- Frees up capital and display space for fresh merchandise
- Flags truly dead stock for wholesale or liquidation review

**Example scenario:**
> A gold bracelet has been in your inventory for 120 days. The Dead Stock Agent applies a 20% markdown and notifies you. If it still doesn't sell after 30 more days, it applies another 10% reduction.

**Markdown schedule (default):**
| Days in Inventory | Discount Applied |
|-------------------|------------------|
| 90 days | 10% off |
| 120 days | 20% off |
| 150 days | 30% off |
| 180 days | 40% off |

**Configuration options:**
| Setting | Description | Default |
|---------|-------------|---------|
| Slow Mover Threshold | Days before item is considered slow-moving | 90 days |
| Dead Stock Threshold | Days before item is flagged as dead stock | 180 days |
| Markdown Schedule | Progressive discount percentages | 10%, 20%, 30%, 40% |
| Minimum Value | Only markdown items worth more than this | $25 |
| Exclude Categories | Categories to skip (e.g., collectibles) | None |

---

### 3. New Item Researcher Agent

**What it does:** Automatically researches new items when they're added to your inventory.

**How it helps you:**
- Instantly provides market data and pricing guidance for new acquisitions
- Generates listing descriptions using AI
- Identifies customers who might be interested in the new item
- Saves hours of manual research per item

**Example scenario:**
> You just bought a collection of vintage jewelry. As you add each piece to inventory, the New Item Researcher automatically:
> - Searches for comparable sales
> - Suggests a competitive price range
> - Drafts a listing description
> - Notifies 3 customers who previously expressed interest in similar items

**Configuration options:**
| Setting | Description | Default |
|---------|-------------|---------|
| Auto Research | Automatically research new items | Enabled |
| Auto Generate Listing | Create draft listing descriptions | Disabled |
| Notify Interested Customers | Alert customers who want similar items | Enabled |
| Research Depth | How thorough the research should be | Comprehensive |

---

## Permission Levels

You control how much autonomy each agent has:

| Level | Behavior | Best For |
|-------|----------|----------|
| **Auto** | Agent executes actions immediately without asking | Routine, low-risk tasks you trust completely |
| **Approve** | Agent proposes actions; you review and approve/reject | Higher-value decisions or when learning the system |
| **Block** | Agent is disabled for your store | Agents you don't want running |

**Recommendation:** Start with "Approve" mode to see what the agents suggest. Once you're comfortable with their decisions, switch to "Auto" for efficiency.

---

## Reviewing Pending Actions

When agents are in "Approve" mode, their proposed actions queue up for your review.

**To review pending actions:**
1. Go to **Agents > Pending Actions**
2. Review each proposed action, including:
   - What the agent wants to do
   - Why it's suggesting this action
   - Before and after values
3. Click **Approve** to execute or **Reject** to dismiss
4. Use **Bulk Approve/Reject** for faster processing

---

## Daily Digest

Every morning at 8 AM, you'll receive an email summary of agent activity:

**What's included:**
- Total agent runs (successful and failed)
- Actions executed automatically
- Actions pending your approval
- Highlights (biggest price changes, customers notified, etc.)
- Quick link to your agent dashboard

---

## Getting Started

### Step 1: Enable Your Agents
1. Navigate to **Settings > Agents**
2. Review each agent's description and default settings
3. Toggle on the agents you want to use

### Step 2: Configure Your Preferences
1. Click on an agent to view its settings
2. Adjust thresholds and behaviors to match your business
3. Set your preferred permission level (start with "Approve" if unsure)

### Step 3: Monitor and Adjust
1. Review the daily digest emails
2. Check pending actions regularly
3. Fine-tune settings based on agent performance

---

## Frequently Asked Questions

**Q: Will agents make changes without my permission?**
A: Only if you set them to "Auto" mode. In "Approve" mode, all actions require your explicit approval.

**Q: Can I undo an action an agent took?**
A: Yes. Go to **Agents > History**, find the action, and click "Rollback" (where supported).

**Q: How do I stop an agent?**
A: Go to **Agents**, find the agent, and either toggle it off or set permission to "Block".

**Q: Do agents work on all my inventory?**
A: By default, yes. You can exclude specific categories or set minimum value thresholds in each agent's settings.

**Q: How much do agents cost?**
A: Agents are included in your Shopmata subscription. AI processing uses your account's AI credits.

**Q: Can I run an agent manually?**
A: Yes. Go to the agent's detail page and click "Run Now" to trigger an immediate run.

---

## Tips for Success

1. **Start conservative**: Use "Approve" mode and higher thresholds initially
2. **Review regularly**: Check pending actions at least once daily
3. **Trust the data**: Agents base decisions on real market data, not guesses
4. **Customize for your niche**: Adjust settings based on your specific market (e.g., longer thresholds for collectibles)
5. **Read the digest**: The daily email highlights important activity you shouldn't miss

---

## Support

Need help with agents? Contact support@shopmata.com or visit our help center for tutorials and troubleshooting guides.
