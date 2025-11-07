# Image Recognition Project - Cost Tracking

## Cloud Projects & Accounts

### Google Cloud Platform
- **Project ID:** `freegle-image-recognition`
- **Project Number:** TBD
- **Billing Account:** TBD
- **Services Used:**
  - Vision API (Product Search)
  - Gemini API
  - Compute Engine (if self-hosting)
  - Cloud Storage (image/embedding storage)

**Budget:** £500/month (adjust as needed)
**Alerts:** 50%, 90%, 100%
**Dashboard:** https://console.cloud.google.com/billing?project=freegle-image-recognition

### OpenAI
- **API Key:** `sk-proj-image-recognition-...` (create separate project key)
- **Organization:** Freegle
- **Services Used:**
  - GPT-4V (multimodal fallback)
  - CLIP embeddings (if using)

**Budget:** £300/month
**Dashboard:** https://platform.openai.com/usage

### Anthropic (Claude)
- **API Key:** `sk-ant-image-recognition-...`
- **Services Used:**
  - Claude 3.5 Sonnet (multimodal fallback)

**Budget:** £300/month
**Dashboard:** https://console.anthropic.com/settings/usage

### AWS (if used)
- **Account:** Separate sub-account or use tags
- **Cost Allocation Tags:**
  - `Project: ImageRecognition`
  - `Phase: POC` / `Production`
  - `Component: EEEDetection` / `AttributeExtraction`
- **Services:**
  - Rekognition
  - Bedrock (Titan embeddings)
  - S3 (storage)

**Budget:** £200/month
**Dashboard:** AWS Cost Explorer filtered by tags

### Azure (if using FurnishRec)
- **Resource Group:** `freegle-image-recognition`
- **Tags:**
  - `Project: ImageRecognition`
  - `Environment: Production`

**Budget:** £100/month
**Dashboard:** Azure Cost Management

### Third-Party APIs

#### API4.AI
- **Account:** image-recognition@freegle.org
- **Service:** Household Items Recognition API
- **Pricing:** Pay-per-use
- **Budget:** £200/month
- **Dashboard:** https://api4.ai/dashboard

#### SerpApi (Google Lens)
- **Account:** image-recognition@freegle.org
- **Service:** Google Lens API access
- **Pricing:** $50/month + overage
- **Budget:** £100/month
- **Dashboard:** https://serpapi.com/dashboard

#### Roboflow
- **Account:** image-recognition@freegle.org
- **Services:**
  - E-Waste model hosting (if cloud)
  - Household items model
- **Option:** Self-host to avoid ongoing costs
- **Budget:** £50/month (or £0 if self-hosted)
- **Dashboard:** https://roboflow.com

## Cost Breakdown by Phase

### Phase 1: Proof of Concept (Months 1-2)
**Goal:** Test 1,000 diverse items, validate accuracy

| Service | Estimated Cost | Notes |
|---------|---------------|-------|
| Roboflow (E-Waste model) | £0-50 | Test cloud vs self-hosted |
| API4.AI | £100 | ~1,000 API calls @ £0.10 each |
| GPT-4V | £150 | ~500 items × £0.30/item |
| Claude 3.5 Sonnet | £100 | ~300 items × £0.33/item |
| SerpApi (Google Lens) | £75 | ~500 searches |
| **Total** | **£425-475** | One-time validation cost |

### Phase 2: Historical Analysis (Months 2-4)
**Goal:** Process 100,000 items from last year

| Service | Estimated Cost | Notes |
|---------|---------------|-------|
| Roboflow (self-hosted) | £100 | Compute instance costs |
| API4.AI | £1,000 | ~10,000 fallback calls |
| GPT-4V/Claude | £1,500 | ~5,000 edge cases × £0.30 |
| Storage (GCP/S3) | £50 | Embedding storage |
| Compute | £200 | Batch processing |
| **Total** | **£2,850** | One-time historical analysis |

### Phase 3: Production (Monthly Ongoing)
**Goal:** Real-time detection on ~8,000 new posts/month

#### Optimized Hybrid Approach

| Service | Monthly Cost | Usage | Notes |
|---------|-------------|-------|-------|
| Roboflow (self-hosted) | £80 | ~5,600 items (70%) | Always-on compute instance |
| API4.AI | £160 | ~1,600 items (20%) | Broader coverage fallback |
| GPT-4V/Claude | £240 | ~800 items (10%) | Edge cases + full attributes |
| Vector DB (Milvus cloud) | £50 | Storage + queries | Or £0 if self-hosted FAISS |
| Image Storage | £20 | Temporary processing storage | |
| Monitoring/Logs | £20 | Usage tracking | |
| **Total** | **£570/month** | **For 8,000 items/month** | |
| **Per-item cost** | **£0.071** | | |

#### Cost Optimization Options

**Option A: Maximum Self-Hosting (Lowest Cost)**
- Self-host Roboflow models on existing infrastructure
- Self-host FAISS vector DB
- Use Claude/GPT-4V only for 5% most difficult cases
- **Estimated:** £150-200/month

**Option B: Balanced (Recommended)**
- As shown above: £570/month
- Good balance of accuracy, speed, maintenance burden

**Option C: Full API (Highest Cost, Easiest Maintenance)**
- Primary: API4.AI for all items
- Fallback: GPT-4V/Claude for unknowns
- **Estimated:** £800-1,000/month

## Budget Alerts & Controls

### GCP Budget Alerts
```bash
# Create budget with email alerts
gcloud billing budgets create \
  --billing-account=BILLING-ACCOUNT-ID \
  --display-name="Image Recognition Monthly Budget" \
  --budget-amount=600 \
  --threshold-rule=percent=50 \
  --threshold-rule=percent=90 \
  --threshold-rule=percent=100 \
  --all-updates-rule-monitoring-notification-channels=CHANNEL-ID
```

### OpenAI Usage Limits
- Set hard limit: £300/month
- Email alert at: £150, £250
- Configure at: https://platform.openai.com/account/limits

### Anthropic Spending Limits
- Set monthly limit in dashboard
- Email notifications enabled

### AWS Budget
```json
{
  "BudgetName": "ImageRecognitionBudget",
  "BudgetLimit": {
    "Amount": "200",
    "Unit": "USD"
  },
  "TimeUnit": "MONTHLY",
  "CostFilters": {
    "TagKeyValue": ["Project$ImageRecognition"]
  }
}
```

## Cost Monitoring Dashboard

### Weekly Review
- [ ] Check GCP billing dashboard
- [ ] Check OpenAI usage
- [ ] Check Anthropic usage
- [ ] Check API4.AI usage
- [ ] Check SerpApi usage
- [ ] Review cost per item processed
- [ ] Identify cost spikes or anomalies

### Monthly Review
- [ ] Compare actual vs budgeted costs
- [ ] Calculate per-item costs
- [ ] Optimize routing (if costs too high)
- [ ] Evaluate self-hosting opportunities
- [ ] Update cost projections

## Cost Optimization Strategies

### 1. Intelligent Routing
Route items to cheapest appropriate service:
- **Obvious EEE** → Roboflow (cheap/free)
- **Household items** → API4.AI (moderate cost)
- **Unusual items** → Claude/GPT-4V (expensive)

### 2. Caching
- Cache API results for identical images (deduplicate)
- Store embeddings for similarity matching
- Reuse product identifications

### 3. Batch Processing
- Batch API calls where possible
- Process historical data during off-peak (cheaper compute)
- Use spot/preemptible instances

### 4. Confidence Thresholds
- Only call expensive models when confidence is low
- Accept "unknown" for very difficult items
- Manual review for high-stakes decisions

### 5. Self-Hosting Migration
- Start with APIs for speed
- Identify high-volume categories
- Self-host models for those categories
- Keep APIs for long-tail cases

## Actual Costs Tracking

### 2025-11 (POC Phase)
| Service | Budgeted | Actual | Variance | Notes |
|---------|----------|--------|----------|-------|
| Roboflow | £50 | TBD | TBD | |
| API4.AI | £100 | TBD | TBD | |
| GPT-4V | £150 | TBD | TBD | |
| Claude | £100 | TBD | TBD | |
| SerpApi | £75 | TBD | TBD | |
| **Total** | **£475** | **TBD** | **TBD** | |

### 2025-12 onwards
Add new rows as project progresses...

## ROI Analysis

### Value Delivered vs Cost

**Benefits:**
- Automatic EEE detection: 3× increase in captured statistics
- Reduced user effort: ~30 seconds saved per post
- Better recycling comms: 1,000+ items/year
- Policy evidence: Quantified reuse impact
- Regulatory compliance: WEEE tracking

**Costs:**
- Development: One-time ~£50,000 (estimated staff time)
- Infrastructure: £570/month ongoing (optimized hybrid)
- Annual running cost: £6,840

**Break-even analysis:**
- If values user time at £10/hour
- Saves 30 seconds per post = £0.083 per post
- 8,000 posts/month = £664 saved/month
- Breaks even on infrastructure costs alone
- Doesn't account for policy value, recycling impact, etc.

## Notes

- All costs in GBP (£) for consistency
- Exchange rates may affect USD-priced services
- Estimates based on Phase 1 validation results
- Update after actual POC completion
- Self-hosting costs assume existing infrastructure
- May need separate compute instances for production workloads

## Related Documents

- [Image Recognition Research](./image-recognition-research.md) - Technical approach
- [EEE Detection Plan](./image-recognition-research.md#14-specific-plan-for-eee-identification-project) - Project phases
- Budget tracking spreadsheet: TBD

## Contact for Budget Approvals

- Project Lead: TBD
- Finance Approver: TBD
- Technical Lead: TBD

---

**Last Updated:** 2025-11-06
**Next Review:** After Phase 1 POC completion
