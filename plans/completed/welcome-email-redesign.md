# Welcome Email Redesign Plan

## Overview

Modernize the welcome email to be mobile-first, engaging, and show real local content to new users.

## Research: Best Practices for Welcome Emails (2024-2025)

Based on research from industry sources:

### Key Statistics
- Welcome emails get **4x the open rate** and **5x the click rate** vs standard marketing emails
- Average open rate: 34.79% (highest of all email types)
- Subject lines containing "welcome" drive **55% higher open rates**
- Subscribers are most engaged immediately after signup

### Mobile-First Design Principles
- **Single-column layouts** for reliable mobile rendering
- **Large tappable buttons** (minimum 44px height)
- **16px+ font size** for body text
- **60:40 text-to-image ratio** for deliverability
- Dark mode compatibility

### Content Best Practices
- Personalization beyond just first name
- Clear, single CTA (or at most 2)
- Set expectations for future emails
- Brief and scannable content
- Send immediately after signup

### What to Avoid
- Overwhelming walls of text
- Multiple competing CTAs
- Generic, impersonal content
- Delay in sending
- Desktop-first designs that break on mobile

**Sources:**
- [MailerLite: Best Welcome Email Examples](https://www.mailerlite.com/blog/best-welcome-email-examples)
- [Klaviyo: Email Design Tips for 2025](https://www.klaviyo.com/blog/email-design-tips)
- [Benatar Brands: Mobile-First Email Design 2024](https://www.benatarbrands.com/blog/mobile-first-email-design-best-practices-for-2024)
- [Omnisend: Best Welcome Emails 2025](https://www.omnisend.com/blog/best-welcome-emails/)

---

## Current State Analysis

### Birthday Email (Reference for Clean Design)
The birthday email is a good example of modern email design:
- Single-column, centered layout
- Celebratory CSS animations (bouncing balloons)
- Large, bold typography
- Multiple donation CTAs with different amounts
- Minimal text, maximum impact
- Naturally mobile-friendly

### Current Welcome Email Issues
- **Two-column layout** (55%/45%) - breaks on mobile, wastes space
- **11-item terms list** - overwhelming, nobody reads this
- **Three separate sections** with similar CTAs - dilutes focus
- **Desktop-first design** - images too wide for mobile
- **Generic imagery** - same for every user regardless of location

### What Works in Current Design
- Good quality images (welcome1.jpg, welcome2.jpg, welcome3.jpg)
- Clear branding with Freegle colors
- Action-oriented CTAs
- Footer with settings link

---

## Design Options

### Option A: Local Social Proof Feed (Recommended)

Show **4-6 real offers near the user's location** at send time. This mirrors the landing page experience and provides immediate, personalized value.

```
┌─────────────────────────────────────────────────┐
│                                                 │
│           [Freegle Logo]                        │
│                                                 │
│      Welcome to Freegle, {firstName}!           │
│                                                 │
│     See what's being shared near you:           │
│                                                 │
│    ┌──────┐  ┌──────┐  ┌──────┐  ┌──────┐      │
│    │      │  │      │  │      │  │      │      │
│    │ Img  │  │ Img  │  │ Img  │  │ Img  │      │
│    │      │  │      │  │      │  │      │      │
│    │Chair │  │Books │  │Toys  │  │Table │      │
│    └──────┘  └──────┘  └──────┘  └──────┘      │
│                                                 │
│          [Browse what's near you →]             │
│                                                 │
├─────────────────────────────────────────────────┤
│                                                 │
│         Ready to give or find?                  │
│                                                 │
│    [POST AN OFFER]      [POST A WANTED]         │
│       (green)              (orange)             │
│                                                 │
├─────────────────────────────────────────────────┤
│                                                 │
│  Three simple rules:                            │
│                                                 │
│  ✓ Everything must be free and legal           │
│  ✓ Be nice to other freeglers                  │
│  ✓ Stay safe — see our safety tips             │
│                                                 │
│              Happy freegling!                   │
│                                                 │
├─────────────────────────────────────────────────┤
│  [Footer: Settings link, charity info]          │
└─────────────────────────────────────────────────┘
```

**Pros:**
- Immediate, personalized value
- Social proof increases engagement
- Mirrors landing page experience (brand consistency)
- Creates curiosity and urgency
- Shows the platform is active

**Cons:**
- Requires dynamic content at send time
- Need fallback for areas with few posts
- More complex to implement

---

### Option B: Simplified Feature Showcase

Keep existing welcome images but in mobile-first single-column layout:

```
┌─────────────────────────────────────────────────┐
│                                                 │
│           [Freegle Logo]                        │
│                                                 │
│      Welcome to Freegle, {firstName}!           │
│                                                 │
│  ┌───────────────────────────────────────────┐ │
│  │                                           │ │
│  │         [welcome1.jpg - full width]       │ │
│  │                                           │ │
│  └───────────────────────────────────────────┘ │
│                                                 │
│              Post an OFFER                      │
│     Give something away — it's easy!            │
│                                                 │
│             [POST AN OFFER]                     │
│                                                 │
│  ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─  │
│                                                 │
│  ┌───────────────────────────────────────────┐ │
│  │                                           │ │
│  │         [welcome2.jpg - full width]       │ │
│  │                                           │ │
│  └───────────────────────────────────────────┘ │
│                                                 │
│              Post a WANTED                      │
│      Ask for something you need!                │
│                                                 │
│            [POST A WANTED]                      │
│                                                 │
├─────────────────────────────────────────────────┤
│  Three simple rules:                            │
│  ✓ Free and legal only                         │
│  ✓ Be nice, stay safe                          │
│                                                 │
│              Happy freegling!                   │
└─────────────────────────────────────────────────┘
```

**Pros:**
- Uses existing imagery (no new assets needed)
- Simpler to implement
- Guaranteed to render correctly

**Cons:**
- Generic content for all users
- Doesn't show real platform activity
- Less compelling than personalized content

---

### Option C: Hybrid — Hero Image + Local Posts

Combine a strong brand image with social proof:

```
┌─────────────────────────────────────────────────┐
│                                                 │
│  ┌───────────────────────────────────────────┐ │
│  │                                           │ │
│  │     [Hero image with Freegle branding]    │ │
│  │                                           │ │
│  │     Share the love. Love the share.       │ │
│  │                                           │ │
│  └───────────────────────────────────────────┘ │
│                                                 │
│      Welcome to Freegle, {firstName}!           │
│                                                 │
│    Give and get stuff locally for free.         │
│                                                 │
│    [GIVE STUFF]        [FIND STUFF]             │
│                                                 │
├─────────────────────────────────────────────────┤
│                                                 │
│      What's being shared near you:              │
│                                                 │
│    ┌──────┐  ┌──────┐  ┌──────┐  ┌──────┐      │
│    │ Img  │  │ Img  │  │ Img  │  │ Img  │      │
│    └──────┘  └──────┘  └──────┘  └──────┘      │
│                                                 │
│           [Explore more →]                      │
│                                                 │
├─────────────────────────────────────────────────┤
│  Quick rules: Free • Legal • Be nice           │
│              Happy freegling!                   │
└─────────────────────────────────────────────────┘
```

**Pros:**
- Strong brand presence with hero image
- Still shows personalized local content
- Balanced approach

**Cons:**
- More complex layout
- Hero image may push important content below fold on mobile

---

## Recommendation

**Option A: Local Social Proof Feed** is the recommended approach because:

1. **Personalization drives engagement** — showing real local content is more compelling than generic imagery
2. **Mirrors landing page** — consistent experience between web and email
3. **Social proof works** — seeing active posts proves the platform is useful
4. **Mobile-first by design** — single column with small thumbnail grid
5. **Immediate value** — user sees what they could get right now

---

## Technical Implementation Notes

### Fetching Local Posts at Send Time

The welcome email service needs to:

1. **Get user's location** from their profile (postcode or lat/lng)
2. **Query recent offers** with photos near that location
   - Use same logic as `MobileVisualiseList` component
   - Fetch offers within ~10km radius
   - Filter for offers with attachments
   - Limit to 4-6 items
3. **Generate thumbnail URLs** via image delivery service
   - Size: 100x100 or 120x120 pixels
   - Format: WebP with JPEG fallback
4. **Fallback** if no local posts:
   - Show curated sample offers from nearby areas
   - Or fall back to Option B (generic welcome images)

### Data Required

```php
// In WelcomeMail Mailable
public function __construct(User $user)
{
    $this->user = $user;
    $this->recentOffers = $this->fetchNearbyOffers($user);
}

private function fetchNearbyOffers(User $user): Collection
{
    // Get user's location
    $location = $user->lat && $user->lng 
        ? [$user->lat, $user->lng]
        : $this->geocodePostcode($user->postcode);
    
    // Fetch recent offers with photos within 10km
    return Message::where('type', 'Offer')
        ->whereHas('attachments')
        ->whereRaw('ST_Distance_Sphere(point, POINT(?, ?)) < 10000', $location)
        ->where('arrival', '>=', now()->subDays(7))
        ->orderByDesc('arrival')
        ->limit(6)
        ->get();
}
```

### MJML Template Structure

```mjml
<mjml>
  <mj-head>
    <mj-preview>See what's being shared near you!</mj-preview>
    <mj-attributes>
      <mj-all font-family="Trebuchet MS, Helvetica, Arial" />
      <mj-text font-size="16px" line-height="1.5" />
    </mj-attributes>
  </mj-head>
  <mj-body background-color="#f8f9fa">
    
    <!-- Header with logo -->
    <mj-section background-color="#ffffff" padding="20px">
      <mj-column>
        <mj-image src="logo.png" width="80px" />
        <mj-text font-size="24px" font-weight="bold" align="center">
          Welcome to Freegle, {{ $firstName }}!
        </mj-text>
      </mj-column>
    </mj-section>
    
    <!-- Local offers grid -->
    <mj-section background-color="#ffffff" padding="10px 20px">
      <mj-column>
        <mj-text align="center" font-size="18px">
          See what's being shared near you:
        </mj-text>
      </mj-column>
    </mj-section>
    
    <mj-section background-color="#ffffff" padding="0 20px">
      @foreach($recentOffers->chunk(2) as $pair)
      <mj-group>
        @foreach($pair as $offer)
        <mj-column width="50%">
          <mj-image 
            src="{{ $offer->thumbnailUrl }}" 
            href="{{ $offer->url }}"
            width="120px"
            border-radius="4px"
          />
          <mj-text align="center" font-size="12px">
            {{ Str::limit($offer->subject, 20) }}
          </mj-text>
        </mj-column>
        @endforeach
      </mj-group>
      @endforeach
    </mj-section>
    
    <!-- CTA buttons -->
    <mj-section background-color="#ffffff" padding="20px">
      <mj-column>
        <mj-button background-color="#325906" href="/give">
          Post an Offer
        </mj-button>
        <mj-button background-color="#e56b1f" href="/find">
          Post a Wanted
        </mj-button>
      </mj-column>
    </mj-section>
    
    <!-- Simple rules -->
    <mj-section background-color="#f5f5f5" padding="20px">
      <mj-column>
        <mj-text>
          Three simple rules:<br/>
          ✓ Everything must be free and legal<br/>
          ✓ Be nice to other freeglers<br/>
          ✓ Stay safe — see our tips
        </mj-text>
        <mj-text font-weight="bold" align="center">
          Happy freegling!
        </mj-text>
      </mj-column>
    </mj-section>
    
    <!-- Footer -->
    @include('emails.mjml.partials.footer')
    
  </mj-body>
</mjml>
```

---

## Changes Summary

| Aspect | Current | Proposed |
|--------|---------|----------|
| Layout | Two-column (55/45) | Single column |
| Content | Generic images | Real local offers |
| Rules | 11 numbered items | 3 bullet points |
| CTAs | 3 separate sections | 2 buttons together |
| Design | Desktop-first | Mobile-first |
| Personalization | Password only | Name + local content |
| Map | None visible | Not needed |

---

## Next Steps

1. **Confirm approach** — Option A, B, or C?
2. **Create MJML template** following chosen design
3. **Build service** to fetch nearby offers at send time
4. **Add fallback logic** for areas with few posts
5. **Test rendering** across email clients (Gmail, Outlook, Apple Mail)
6. **A/B test** against current welcome email
7. **Monitor metrics** — open rate, click rate, first-post conversion

---

## Questions for Decision

1. **Fallback content**: If a user's area has fewer than 4 offers, should we:
   - Expand the search radius?
   - Fall back to generic welcome images?
   - Show fewer thumbnails?

2. **Offer selection**: Should we prioritize:
   - Most recent offers?
   - Offers with the best photos?
   - Mix of different categories?

3. **Password display**: Keep showing password in email or remove it for security?
