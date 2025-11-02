# Donation Appeals: Evidence-Based Research Review & Implementation

**Date**: October 31, 2025 (Research) | November 2, 2025 (Implementation)
**Page Location**: `/iznik-nuxt3/pages/donation-appeals.vue` (Research) | `/iznik-nuxt3/components/MyPostsDonationAsk.vue` (Live Test)
**Status**: **LIVE A/B TESTING** - My Posts Donation Appeal

## 🚀 Current Live Implementation (November 2, 2025)

### What We're Testing: My Posts Donation Appeal

**Component**: `MyPostsDonationAsk.vue`
**Location**: Shown on `/myposts` page after user posts an Offer (and hasn't donated yet)
**A/B Test ID**: `mypostsdonation`

### Test Configuration

**Two Message Variations**:
1. **Solution Framing** (Variation #5)
   - Message: "Help us continue the solution! Our 4.6M volunteer-run members have kept 47,000 tonnes out of landfill. Donate to keep Freegle growing?"
   - Research basis: Yale 2024 study showing solution framing increases donation intent

2. **Minimal Friction** (Variation #10)
   - Message: "Freegle is volunteer-run and free to use. Donate to help us continue?"
   - Research basis: NextAfter 2024 research on friction reduction

**Eight Donation Amounts**: £0.50, £1, £1.50, £2, £2.50, £3, £4, £5

**Total Variants**: 16 (2 messages × 8 amounts)

### Multi-Armed Bandit Algorithm

The system uses intelligent A/B testing via the `abtest` table:
- **90% exploitation**: Shows best-performing variant based on conversion rate
- **10% exploration**: Randomly tries other variants to find hidden winners
- **Auto-optimization**: Automatically shifts traffic to better performers
- **Metrics tracked**: `shown` (impressions), `action` (conversions), `rate` (%)

### Technical Implementation

**User Experience**:
- Slider control for amount selection (£0.50-£10 range)
- Default amount set by bandit algorithm
- Stripe payment (with PayPal fallback)
- Rainbow animated border for visual prominence
- Mobile-optimized with larger touch targets

**Tracking**:
- `api.bandit.choose()` - Selects variant on mount
- `api.bandit.shown()` - Records impression when displayed
- `api.bandit.chosen()` - Records conversion on successful donation

**Display Logic**:
- Only shows after user posts an **Offer** (`type === 'Offer'`)
- Only shows if user hasn't donated this session (`!donated`)
- Post-transaction timing optimizes for positive user sentiment

### Why This Timing?

Post-offer donation asks capitalize on:
1. **Peak engagement** - User just successfully completed their goal
2. **Positive sentiment** - Feeling good about giving away items
3. **Platform value demonstration** - Just experienced Freegle's benefit firsthand
4. **UK affordability barrier** - User just saved money, reducing "can't afford" concern

### Database Setup

All 16 variants configured in `abtest` table with `suggest=1`:
```sql
SELECT variant, shown, action, rate FROM abtest
WHERE uid = 'mypostsdonation'
ORDER BY variant;
```

### Success Metrics to Monitor

**Primary**:
- Conversion rate (target: 15-20%)
- Average donation amount (target: £1.50-£3.00)
- Total revenue per 1000 impressions

**Secondary**:
- Message variation performance (solution vs minimal friction)
- Optimal amount (which defaults convert best)
- Mobile vs desktop conversion difference
- Time to donate (friction metric)

### Next Steps

1. **Week 1-2**: Gather baseline data, ensure tracking working
2. **Week 3-4**: Identify clear winners in message type and amount
3. **Month 2**: Optimize based on winners, test additional variations
4. **Month 3**: Consider expanding to other post-transaction moments

---

## Overview (Research Phase)

Conducted comprehensive evidence-based research review of Freegle's donation appeal variations, comparing proposed approaches against peer-reviewed studies, field experiments, and UK charity sector data. Updated donation-appeals.vue page with proper citations, research-backed rationale, and implementation warnings.

## Research Methodology

### Sources Consulted

**Academic Research**:
- PLOS ONE field experiments (N=12,316)
- Journal of Consumer Behaviour neurophysiological studies
- Judgment & Decision Making (Carnegie Mellon)
- Yale Center for Customer Insights (N=2,900+)
- Stanford Graduate School of Business
- Harvard Business School / GlobalGiving Consortium

**UK-Specific Data**:
- GOV.UK Charity Commission Trust Reports (2022-2025)
- CAF (Charities Aid Foundation) UK Giving Report 2025
- UK donor behavior and barriers research

**Sector Research**:
- Wikipedia Fundraising Reports (2018-19, 2023-24)
- NextAfter mobile conversion experiments (2024)
- Charitable Giving Consortium studies
- Neon One donor fatigue research

## Key Research Findings

### ✅ Strongly Validated Approaches

#### 1. **Agentic Appeals (+82.3%)**
- **Research**: Whillans & Dunn, PLOS ONE 2018, field experiment N=12,316
- **Finding**: Emphasizing donor agency increased donations $18.47 vs $10.13 (82.3% increase)
- **Critical Caveat**: Study on affluent sample (median income $85k+, business school alumni)
- **Recommendation**: TOP PRIORITY for Phase 1 testing, but validate with Freegle's demographic
- **Status**: Updated Variation #6 with caveat warnings

#### 2. **Solution Framing**
- **Research**: Yale Center for Customer Insights 2024, N=2,900+
- **Finding**: Framing nonprofits as "solution sources" significantly increases donation intent
- **UK Context**: Addresses "solutions not seen" fatigue in environmental giving
- **Recommendation**: Phase 1 testing, particularly strong for Freegle's environmental mission
- **Status**: Enhanced Variation #5 with UK environmental context

#### 3. **Goal Proximity Effect (+200%)**
- **Research**: Charitable Giving Consortium (GlobalGiving, DonorsChoose, Kiva, Harvard)
- **Finding**: Donations causing beneficiaries to reach goals ~200% larger than early-stage
- **Critical Requirement**: Progress bar MUST be authentic and real-time
- **Warning**: False urgency creates donor fatigue (Neon One 2024)
- **Status**: Updated Variation #4 with authenticity requirements

#### 4. **Minimal Friction (17% → 22.6% conversion)**
- **Research**: NextAfter 2024, multiple experiments
- **Findings**:
  - Form fields 11→4: +17% to 22.6% conversion
  - Two-step mobile forms: +22% conversion
  - Removing scroll friction: +50% donations
  - Eliminating navigation bar: +200% donations
  - Phone number required: 37% abandon
- **UK Context**: Addresses affordability barrier (44% can't afford, CAF UK 2025)
- **Status**: Enhanced Variation #10 as high conversion potential

#### 5. **Concrete Impact Data**
- **Research**: Multiple sources
  - 80% want to know real outcomes (Accenture)
  - 75% look for concrete achievements before giving (amplifi)
  - Stanford GSB: numerical data nearly quadrupled donations
- **Correction Made**: Changed "97% want impact data" to "80%" (verified figure)
- **Status**: Updated Variation #7 with corrected stats + UK trust signals

### ⚠️ Approaches Requiring Modification

#### 6. **Emotional Storytelling - CRITICAL FIX**
- **Research**: Small, Loewenstein & Slovic 2007, Judgment & Decision Making
- **Finding**: Adding statistical information to emotional appeals REDUCES donations
- **Problem Identified**: Original Variation #1 mixed story + statistics
- **Action Taken**: Removed all statistics from emotional story variation
- **Status**: ✅ Fixed - now pure emotional storytelling

#### 7. **Wikipedia-Style Appeals - UK ADAPTATION**
- **Research**: Wikipedia Fundraising 2018-19 Report
- **Finding**: "Please don't scroll" messaging proven effective through extensive A/B testing
- **UK Issue**: 67% think charities spend too much on overhead (GOV.UK 2024)
- **Modification**: Changed "100% goes to platform" to "97% volunteer-run, no CEO salaries"
- **Rationale**: Public trusts small volunteer-run charities more than professionalized ones
- **Status**: Updated Variation #2 with trust-building language

#### 8. **Negative Social Proof - HIGH RISK**
- **Research**: Wikipedia Fundraising Report - paradoxical finding
- **Finding**: Wikipedia's donation rates DROP when they remove "fewer than 1%" messaging
- **Problem**: Contradicts traditional social proof theory (show what people DO, not don't do)
- **Context Issue**: Wikipedia's success may not transfer to Freegle's context
- **Action Taken**: Reframed from negative ("Only 0.4%") to elite group membership
- **Status**: Marked Variation #3 as HIGH RISK with warning

### ❌ Approaches NOT Recommended

#### 9. **Heavy Text + Bold (Variation #8)**
- **Research**: NextAfter mobile friction studies
- **Problem**: Contradicts all mobile best practices
  - Scroll reduction: +50% donations
  - Form field reduction: +22.6% conversion
  - Heavy text increases cognitive load and friction
- **Wikipedia Context**: Encyclopedia readers expect text-heavy content (unique context)
- **Recommendation**: LOW PRIORITY / DO NOT TEST
- **Status**: Marked as "NOT RECOMMENDED FOR MOBILE" with warning

## UK-Specific Barriers Identified

### Critical Issues (CAF UK Giving 2025, GOV.UK 2024)

1. **Affordability Crisis** - 44% cite "can't afford it" as primary barrier
2. **Trust Collapse** - 67% think charities spend too much on salaries/admin (up from 58% in 2014)
3. **Declining Donor Numbers** - 4 million fewer donors in 2024 vs 2019 (50% vs 58%)
4. **Youth Collapse** - Only 36% of 16-24s donated in 2024 (vs 52% in 2019)
5. **Donor Fatigue** - 19% first-time donor retention, 45% average retention
6. **Form Abandonment** - 37% abandon if phone number required

### Freegle Advantages

- **Volunteer-run model** addresses trust concerns about overhead
- **Post-transaction timing** (after user saves money) counters affordability barrier
- **Environmental impact** (47K tonnes diverted) provides concrete solution narrative
- **Grassroots positioning** aligns with public preference for small local charities over large professionalized ones

## Technical Requirements for ALL Variations

Based on friction reduction research (NextAfter, re:charity):

### ✅ MUST IMPLEMENT
- Max 4 form fields (11→4 = +22.6% conversion)
- Phone number optional or removed (37% abandon if required)
- Single page or 2-step form (8% drop-off per new page)
- No navigation bar on donation page (+200% when removed)
- Pre-populate email/name if user logged in
- Large touch targets on mobile (min 44x44px)
- Fast load times (7% loss per second delay)

### ❌ MUST AVOID
- Required phone numbers (-37%)
- Multi-page redirects (-8% per page)
- Heavy text on mobile (increases friction)
- Mixing emotional stories + statistics (reduces donations)
- False urgency (causes donor fatigue)
- "100% to cause" claims (cognitive dissonance about real overhead)

## Updated Testing Strategy

### Phase 1: Strongest Research-Backed (Months 1-3)

Split traffic equally between:
1. **#6 Agentic Appeals** - 82.3% increase (validate with demographic)
2. **#5 Solution Framing** - Yale 2024 + UK environmental context
3. **#10 Minimal Friction** - NextAfter research + UK affordability

**Success Metrics**:
- Conversion rate (target 15-20%)
- Average donation (£10-18)
- Total revenue
- Mobile vs desktop split
- Completion time (<2 min)
- First-time vs repeat donors

### Phase 2: Winner + Impact/Goal (Months 4-6)

- Take Phase 1 winner as new baseline
- Test vs **#7 Impact Calculator** (with UK trust signals)
- Test vs **#4 Goal Proximity** (ONLY if progress bar authentic)
- Optimize donation amounts

### Phase 3: Adapted Wikipedia (Months 7-9)

- **#2 "Don't Scroll"** (UK volunteer-run emphasis)
- **#3 Social Proof** (elite group framing, HIGH RISK - monitor closely)
- **Skip #8** (heavy text contradicts mobile best practices)

### Phase 4: Advanced Testing (Months 10-12)

- Matching gift campaigns (71% response increase, 51% higher average)
- Gift Aid emphasis (UK-specific, 25% value increase)
- Monthly recurring option (test lifetime value)
- Local/postcode impact ("Your area diverted X tonnes")

### Ongoing Monitoring

**Donor Health Metrics**:
- Retention rate (target >45%, current UK average)
- Appeal frequency (max 2-3 per year to avoid fatigue)
- Unsubscribe rates (fatigue signal)

**UK-Specific Tracking**:
- Youth engagement (16-24s only 36% donate nationally)
- Trust perception surveys
- Seasonal patterns (end tax year April 5, Giving Tuesday, Christmas)

## Changes Made to donation-appeals.vue

### Content Updates

1. **Introduction Section**
   - Corrected statistics with verified sources
   - Added UK-specific context warning
   - Emphasized post-transaction timing advantage

2. **All 10 Variations Enhanced**
   - Added research notes with validation status (✅ Strong, ⚠️ Caution, ❌ Not Recommended)
   - Included implementation warnings where critical
   - Updated copy to address UK trust issues
   - Removed problematic elements (stats from emotional stories)
   - Reframed negative social proof to elite group framing

3. **Research References Section**
   - Expanded from 8 to 17 properly cited sources
   - Added journal names, publication dates, sample sizes
   - Included URLs where available
   - Noted key caveats (e.g., affluent sample for agentic appeals)

4. **Testing Strategy Section**
   - Complete rewrite with 4-phase approach
   - Added technical requirements checklist
   - Included UK-specific monitoring metrics
   - Listed what NOT to test with rationale
   - Added success metrics and targets

### Style Updates

- Added `.research-note` styling (green for positive validation, red for warnings)
- Added `.warning` styling for UK context alerts
- Added `.trust-signal` styling for volunteer-run emphasis
- Enhanced `.testing-strategy` section formatting

## Implementation Recommendations

### Immediate Actions

1. **Validate Technical Stack**
   - Confirm form can be limited to 4 fields maximum
   - Ensure phone number can be made optional
   - Verify ability to track real-time progress for goal proximity
   - Check single-page donation flow capability

2. **Prepare for Testing**
   - Set up A/B testing infrastructure for 3-way split (Phase 1)
   - Configure analytics for success metrics
   - Create donor retention tracking system
   - Establish appeal frequency limits (max 2-3/year per user)

3. **Risk Mitigation**
   - Test agentic appeals with small sample first (affluent sample caveat)
   - Monitor negative social proof (Variation #3) closely for backfire effects
   - Ensure progress bars are authentic or don't use Variation #4
   - Never mix emotional stories with statistics

### Long-term Opportunities

1. **Matching Gift Campaigns** - Find major donor to match first £5,000 (71% response increase)
2. **Gift Aid Optimization** - Simplify checkbox (25% value increase, UK-specific)
3. **Postcode Impact** - Build local impact tracking ("Your area diverted X tonnes")
4. **Recurring Giving** - Add monthly option with clear lifetime value messaging

## Risk Factors

### High Risk Variations

- **#3 Social Proof** - May backfire if users think "everyone freeloads, why shouldn't I?"
- **#6 Agentic** - May not work for less affluent demographic
- **#8 Heavy Text** - Contradicts mobile best practices

### Research Limitations

- Agentic appeals tested on affluent sample (median £85k+)
- Wikipedia findings may be context-specific (encyclopedia readers)
- Most research from US context, applied to UK with adaptations
- Limited research on named asker vs named beneficiary (Variation #9)

### Donor Fatigue Risks

- Too-frequent appeals = 19% first-time donor retention
- False urgency = disengagement
- Ongoing "crisis" messaging = feeling problems unfixable
- Overly similar appeals = donors lose interest

## Success Indicators

### Phase 1 Success Criteria (3 months)

- Conversion rate >15% (vs industry avg 12-19%)
- Average donation £12-18
- Mobile conversion within 10% of desktop
- Form completion time <2 minutes
- Donor satisfaction survey >4/5 on trust

### Overall Program Success (12 months)

- Total revenue increase >20% vs baseline
- Donor retention >50% (vs UK avg 45%)
- Youth (16-24) engagement >40% (vs UK avg 36%)
- Appeal frequency maintained ≤3 per year per user
- Trust perception survey >80% "trust Freegle uses donations wisely"

## Next Steps

1. **Technical Implementation** - Build 4-field donation form with optional phone number
2. **Begin Phase 1 Testing** - #6 Agentic, #5 Solution, #10 Minimal Friction
3. **Establish Monitoring** - Set up analytics dashboards for all success metrics
4. **Plan Donor Communications** - Draft follow-up impact reports for Phase 1 donors
5. **Prepare Phase 2** - Build real-time progress bar system (if using Variation #4)

## References

See `/iznik-nuxt3/pages/donation-appeals.vue` for complete list of 17 research citations including:

- Whillans & Dunn (PLOS ONE 2018) - Agentic appeals
- Yale Center for Customer Insights (2024) - Solution framing
- GlobalGiving Consortium - Goal proximity effect
- NextAfter (2024) - Mobile friction reduction
- GOV.UK Charity Commission (2024) - UK trust in charities
- CAF UK Giving Report (2025) - UK donor barriers
- Small et al. (2007) - Identifiable victim effect
- Wikipedia Fundraising Reports (2018-19) - Social proof paradox

---

**Page Reference**: `/iznik-nuxt3/pages/donation-appeals.vue`
**Live Preview**: `http://freegle-dev.localhost/donation-appeals` or `http://freegle-prod.localhost/donation-appeals`
**Git Status**: Updated and staged in iznik-nuxt3 submodule
