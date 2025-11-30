# App Browse Page Redesign (Phase 2)

## Overview

This plan covers the browse page redesign for the Freegle mobile app, using a full-screen card pattern where each item fills most of the viewport with vertical scrolling between items.

**Status:** Deferred - Focus on Individual Item Display (Phase 1) first. See `mobile-post-display.md`.

---

## Design Concept

Based on the mockup, the browse experience uses a **full-screen card pattern** where each item fills most of the viewport, with vertical scrolling between items.

**Single-column, full-screen cards** (like Tinder/Instagram stories):
- Each card takes up most of the screen
- Vertical scroll to move between items
- Adjacent cards peek from top/bottom as visual hint
- Tap card to open reply/detail flow
- Category tabs for filtering at top

---

## Card Layout

```
+----------------------------------+
| < Outdoors    [Kids]    Sport >  |  <- Category tabs (horizontal scroll)
+----------------------------------+
|                                  |
|                                  |
|                                  |
|      [PHOTO - ~70% height]       |
|                                  |
|                                  |
|                                  |
+----------------------------------+  <- Dark gradient starts here
| [WANTED]                         |  <- Type badge (pill style)
| Friedland EVO doorbell extenders |  <- Item title (bold, prominent)
| Posted 6 days ago by user (EH26) |  <- Posted info + postcode
| Description preview text here... |  <- First 2-3 lines of description
|                                  |
| Collect from Balerno             |  <- Location
+----------------------------------+
|    [peek of next card below]     |
+----------------------------------+
```

---

## Photo Handling

### Multiple Photos
- **Tap photo area** to cycle through photos
- **Dot indicators** at bottom of photo area showing current/total
- When viewing photos, swipe left/right within the photo area

### Single Photo
- Just display the photo, no indicators needed

### No Photo - Creative Options

Research shows several approaches. Key UX insight: "When using the same placeholder images, users might group them as similar products at first glance" - so variety is important.

**Option A: Similar Item Photo (Recommended for engagement)**
- Server API returns a photo from a similar item (same category/keywords)
- Clearly marked as sample/example to avoid confusion:
  - Grayscale/desaturated filter
  - "Sample image" watermark badge
  - Subtle blur effect
  - Or: Small "Example photo" label overlay
- Still applies Ken Burns animation
- Most engaging option - maintains visual consistency with photo posts

**Option B: Category Illustration**
- Custom illustrations for each category (Kids, Electronics, Garden, etc.)
- Freegle brand colors and style
- More polished than generic placeholder
- Can still animate (subtle float/pulse)
- Examples: Traveloka uses illustrations for empty states

**Option C: Gradient + Icon**
- Gradient background in Freegle brand colors:
  - OFFER: Green gradient
  - WANTED: Blue/purple gradient
- Large category icon or Freegle logo centered
- Simple but consistent
- Text overlay still readable

**Option D: AI-Generated Representative Image**
- Generate a representative image based on item title
- Mark clearly as "AI illustration"
- More complex to implement but most visually rich

**Recommendation:** Start with **Option A (similar item photo)** as it:
- Maintains visual engagement
- Leverages existing photo assets
- Differentiates from "broken" placeholder feel
- Can fall back to Option C if no similar items found

**Implementation for Option A:**
```
GET /api/message/{id}/similar-photo
Returns: { url: "...", isExample: true, sourceItemId: 123 }
```

Display with:
- CSS filter: `grayscale(30%) opacity(0.85)`
- Small badge: "Example photo" in corner
- Ken Burns animation still applies

---

## Ken Burns Animation Effect

**Key feature:** Photos have a slow, subtle pan/zoom animation to create a sense of life and movement.

**Implementation:**
- Slow pan across the image (e.g., left-to-right over 8-10 seconds)
- Subtle zoom in or out (e.g., scale from 1.0 to 1.1 over 10 seconds)
- Random starting position/direction for variety
- Animation loops or reverses smoothly

**CSS approach:**
```css
@keyframes kenburns {
  0% {
    transform: scale(1.0) translate(0, 0);
  }
  100% {
    transform: scale(1.1) translate(-3%, -3%);
  }
}

.card-photo {
  animation: kenburns 10s ease-in-out infinite alternate;
  /* 'alternate' reverses direction each cycle for smooth loop */
}
```

**Considerations:**
- Animation should be subtle - not distracting
- Pause animation when card is not in viewport (performance)
- Respect `prefers-reduced-motion` media query for accessibility
- Different items could have different pan directions for variety
- May want to pause on tap/interaction

---

## Visual Style

### Dark Gradient Overlay
- Semi-transparent gradient from transparent (top) to dark (bottom)
- Ensures text is always readable regardless of photo
- Smooth transition, not harsh line

### Typography
- Type badge: Small pill, rounded corners, colored background
  - OFFER: Green (#28a745)
  - WANTED: Purple/blue (#6f42c1 or similar)
- Title: 20-24px, bold/semibold, white
- Meta info: 14px, regular, light gray/white with opacity
- Description: 14px, regular, light gray, max 2-3 lines with ellipsis

### Card Chrome
- Subtle rounded corners (12-16px) on the card
- Very subtle shadow or border to separate from adjacent cards
- Adjacent cards visible at ~15-20px peek

---

## Navigation & Interaction

### Vertical Scroll
- Scroll up/down to move between items
- Scroll snap to center each card
- Momentum scrolling feels natural

### Tap to Reply
- Tap anywhere on card opens reply/detail view
- This is the full-screen detail modal from the item detail plan

### Category Tabs
- Horizontal scrollable tabs at top
- Tap to filter by category
- Current category highlighted

### Future Enhancement
- Multi-column grid layout
- Toggle between full-card and grid views

---

## Card States

### Normal
- Full color, interactive

### Promised/Reserved
- Semi-transparent overlay
- "Reserved" badge visible
- Still tappable to view details

### Freegled/Completed
- Grayscale filter or dimmed
- "Freegled!" badge
- Consider hiding from browse entirely

---

## Skeleton Loading

While loading:
```
+----------------------------------+
| [shimmer animation]              |  <- Shimmer animation
|                                  |
|      [Photo placeholder]         |
|                                  |
+----------------------------------+
| [shimmer]                        |  <- Badge placeholder
| [shimmer shimmer shimmer]        |  <- Title placeholder
| [shimmer shimmer]                |  <- Meta placeholder
+----------------------------------+
```

---

## Components Needed

- `MessageCardApp.vue` - Photo-first mobile card
- `MessageCardSkeleton.vue` - Loading placeholder
- `QuickActionsSheet.vue` - Long-press actions bottom sheet
- `NoPhotoPlaceholder.vue` - Gradient/icon fallback for posts without photos

---

## Technical Considerations

### Performance
- Virtual scrolling for large lists (vue-virtual-scroller)
- Fixed height cards for better scroll calculation
- Limit initial load, infinite scroll for more
- Image placeholder sizing to prevent layout shifts
- Lazy load photos below the fold
- Pause Ken Burns animation when card not in viewport

### Footer Ads / Sticky Banner
- Use the existing `sticky-banner.scss` variables for consistent spacing
- Card container must account for variable banner heights
- Test with ads both enabled and disabled

---

## Potential Issues

### Issue 1: Card Grid Scroll Performance
**Problem:** Large number of cards in browse grid may cause jank
**Mitigation:**
- Virtual scrolling for large lists (vue-virtual-scroller)
- Fixed height cards for better scroll calculation
- Limit initial load, infinite scroll for more
- Image placeholder sizing to prevent layout shifts

### Issue 2: Consistent Experience Web vs App
**Problem:** App-specific pages diverge from web experience
**Mitigation:**
- Keep web version unchanged for desktop users
- App pages only loaded when on mobile
- Share core business logic components
- Clear URL structure: `/browse/mobile/` vs `/browse/`

---

## Implementation Tasks

1. Create `MessageCardApp.vue` - Photo-first mobile card component
2. Implement Ken Burns animation on card photos
3. Add category tabs with horizontal scroll
4. Implement vertical scroll snap for cards
5. Create no-photo fallback (similar item photo or gradient)
6. Add card state styling (reserved, freegled)
7. Implement skeleton loading
8. Add virtual scrolling for performance
9. Implement tap-to-detail navigation
10. Respect `prefers-reduced-motion` for animations

---

## Related Documents

- `mobile-post-display.md` - Individual item detail view (Phase 1)
