# App Item Detail Flow Redesign (Phase 1)

## Overview

This plan redesigns the item detail view for the Freegle mobile app, creating a modern, engaging interface that feels native and enjoyable to use.

**Related:** See `mobile-browse-page.md` for the browse page redesign (Phase 2).

## Goals

1. Create a full-screen, immersive item detail experience
2. Make photo viewing intuitive with pinch-to-zoom and swipe gestures
3. Use progressive disclosure to keep the UI clean
4. Replace text-heavy layouts with visual representations
5. Maintain functionality while reducing visual clutter

---

## Research Summary

### Competitor Analysis

**Depop/Vinted Style:**
- Instagram-like grid layout for browse
- Large, prominent photos in listings
- Social features (likes, shares) visually represented
- AI-powered listing creation
- Youth-oriented, trendy feel

**OfferUp/Letgo Style:**
- Local-first, proximity-based discovery
- Large photo thumbnails in browse grid
- Quick photo capture and listing
- Trust indicators (ratings, verification badges)
- Clean, minimal detail views

**Facebook Marketplace:**
- Full-width photo carousels
- Swipe between photos horizontally
- Collapsible description sections
- Floating action button for messaging
- Location shown as small map chip

### UX Best Practices (from research)

1. **Photo Gestures (Baymard Institute):**
   - 40% of mobile e-commerce sites fail to support pinch/double-tap zoom
   - Both gestures should be supported (no single convention)
   - High-resolution images required for zoom to be useful

2. **Carousel Navigation (Nielsen Norman Group):**
   - Users expect swipe gestures on mobile carousels
   - Most users stop after 3-4 items - keep carousel small
   - Provide visual cues (dots, partial next image visibility)
   - Add bounce effect at end to indicate limits

3. **Progressive Disclosure (Shopify/IxDF):**
   - Lead with essential information first
   - Use clear affordances ("Learn more", icons, arrows)
   - Minimize clicks and modal interruptions
   - Let users control the pace of information reveal

---

## Design Specification

### 1. Full-Screen Item Detail Modal

**Navigation:**
- Back arrow (top-left) instead of X (top-right)
- Swipe right from left edge to go back (iOS-style gesture)
- No visible modal chrome - should feel like a native page
- Status bar visible (safe area respected)

**Structure:**
```
+----------------------------------+
| < Back          [Share] [Heart]  |  <- Minimal header
+----------------------------------+
|                                  |
|                                  |
|       [PHOTO AREA - 60%]         |  <- Primary focus
|                                  |
|                                  |
+----------------------------------+
|  [Info Section - Scrollable]     |  <- Secondary
|  - Item name                     |
|  - Distance indicator (visual)   |
|  - Quick stats row               |
|  - Description (collapsed)       |
|  - Location (tap for map modal)  |
+----------------------------------+
|  [Reply Button - Fixed Footer]   |  <- CTA
+----------------------------------+
```

### 2. Photo Display Strategies

#### A. Single Photo Layout
- Photo fills 60% of viewport height
- Tap to enter full-screen lightbox mode
- Double-tap or pinch to zoom inline
- Subtle "Tap to zoom" hint on first view

#### B. Multiple Photos Layout
- Horizontal swipe carousel with dot indicators
- Show edge of next photo as visual hint (~15px)
- Counter badge: "1/4" in corner
- Swipe to navigate, tap for lightbox
- In lightbox: full swipe gallery with zoom

#### C. No Photo Layout
- Display large type icon based on category
- Gradient background in Freegle brand colors
- Or: Abstract pattern/illustration
- Item name displayed prominently over the visual

**Lightbox (Full-screen Photo View):**
- Pinch-to-zoom with smooth animation
- Pan when zoomed
- Double-tap to toggle zoom levels (1x, 2x, fit)
- Swipe up/down to dismiss (with velocity-based animation)
- Swipe left/right for next/previous photo
- Photo counter and download button visible
- Background dims to black

### 3. Information Hierarchy (Visual First)

**Quick Stats Row (replaces text):**
```
+----------------------------------+
| [🕐 2h]  [📍 0.3mi]  [💬 3]      |
|  Posted   Distance    Replies    |
+----------------------------------+
```

- Use compact visual chips instead of full text
- Distance shown as small number with icon
- Reply count as badge (clickable to see who replied)
- Posted time in relative format

**Item Details:**
```
+----------------------------------+
| Sony TV 42" - Working            |  <- Item name (prominent)
| [OFFER] • Electronics            |  <- Type badge + category
+----------------------------------+
| [See description ∨]              |  <- Collapsed by default
+----------------------------------+
```

**Description Section:**
- Collapsed by default with first 2 lines preview
- "See more ∨" tap target
- Expands inline (no modal)
- Full text with preserved formatting

### 4. Progressive Disclosure for Reply

**Initial State:**
- Single "Reply" button in fixed footer
- Clean, uncluttered view focused on the item

**After Tap "Reply":**
- Footer expands upward with smooth animation
- Reveals:
  - Text input field (auto-focused, keyboard appears)
  - Quick reply suggestions: "Is this still available?", "Can I collect today?"
  - Character count (optional)
- User can tap outside or swipe down to dismiss

**Reply Input:**
```
+----------------------------------+
| [Suggested: "Is this available?"] |
+----------------------------------+
| [Your message here...]           |
|                        [Send ->] |
+----------------------------------+
```

### 5. Map Integration

**Current Problem:**
- Map takes up significant space
- Often not immediately useful
- Adds to visual clutter

**New Approach:**
- Map is hidden by default - no thumbnail, no preview
- Location shown as text only in the quick stats row
- Tap the map icon (standard map icon, not pin) to open full map in modal
- Modal shows interactive map with approximate location
- Clean, minimal default view - map only when explicitly requested

**Quick Stats Row with Map Icon:**
```
+------+------+------+------+
|  🕐  |  🗺️  |  💬  |  📦  |
|  2h  | 0.3mi|   3  |   1  |
+------+------+------+------+
         ↑
    Tap to open map modal
```

**Map Modal:**
- Opens as overlay/modal when map icon tapped
- Full interactive map showing approximate location
- Close button or tap outside to dismiss
- No map visible in the main item view

### 6. Visual Representation Instead of Tables

**Current (Text-Heavy):**
```
Posted: 2 hours ago
Location: Loughborough
Type: Offer
Replies: 3 people interested
Available: 1 item
```

**New (Visual Compact):**
```
+------+------+------+------+
|  🕐  |  📍  |  💬  |  📦  |
|  2h  | 0.3mi|   3  |   1  |
+------+------+------+------+
```

Or as inline badges:
```
[2h ago] [0.3mi] [3 replies] [1 available]
```

---

## Implementation Tasks

### Phase 1: Individual Item Display (Priority)

Focus on improving the display when viewing a single item. Entry points:
- `/pages/message/[id].vue` - Direct link to message
- `OurMessage` component with `startExpanded=true`
- `MessageExpanded.vue` - The actual expanded view

**Tasks:**
1. Create `MessageExpandedApp.vue` - App-optimized item display
2. Implement full-screen photo with Ken Burns animation
3. Add photo carousel with swipe (tap to cycle, swipe for multiple)
4. Create no-photo fallback (similar item photo or gradient)
5. Redesign info section with visual stats row
6. Implement collapsible description
7. Create map modal (opened from map icon in stats row)
8. Implement progressive reply (bottom sheet)
9. Add "Example photo" badge for similar-item placeholders
10. Respect `prefers-reduced-motion` for Ken Burns

**Conditional rendering:**
```vue
<!-- In OurMessage.vue or pages/message/[id].vue -->
<MessageExpandedApp v-if="mobileStore.isApp" ... />
<MessageExpanded v-else ... />
```

### Phase 2: Browse Page (Future)
See separate plan: `mobile-browse-page.md`

### Phase 3: Reply Flow Enhancement
11. Implement expandable reply footer (bottom sheet pattern)
12. Add quick reply suggestions
13. Create smooth expand/collapse animations
14. Handle keyboard appearance gracefully

### Phase 4: Polish & Refinement
15. Add micro-interactions and animations
16. Implement haptic feedback on key actions
17. Performance optimization for image loading
18. Accessibility review and improvements
19. Cross-platform testing (iOS/Android differences)

---

## Technical Considerations

### Existing Code to Leverage
- `ImageCarousel.vue` - Has zoom buttons, but needs swipe gestures
- `PinchMe.vue` - Already uses `zoompinch` library
- `MessagePhotosModal.vue` - Full-screen photo modal exists
- `MessageExpanded.vue` - Current detail view layout

### New Components Needed

- `AppMessageDetail.vue` - Full-screen item view
- `PhotoLightbox.vue` - Enhanced zoom/swipe gallery
- `ExpandableReplyFooter.vue` - Progressive reply UI (bottom sheet)
- `VisualStatsRow.vue` - Compact stat badges
- `MapModal.vue` - Full map in modal (opened from quick stats row)
- `NoPhotoPlaceholder.vue` - Gradient/icon fallback for posts without photos

### Libraries to Consider
- `vue3-touch-events` - Simple swipe/tap/hold directives for Vue 3
- Existing `zoompinch` - Already in use, needs configuration
- CSS `scroll-snap` - For native-feeling carousel
- Ionic Gestures (`@ionic/vue`) - More advanced gesture control if needed

**Note:** `vue3-touch-events` is recommended for simplicity:
```javascript
// Setup
import Vue3TouchEvents from "vue3-touch-events"
app.use(Vue3TouchEvents)

// Usage in template
<div v-touch:swipe.left="handleSwipeLeft" v-touch:swipe.right="handleSwipeRight">
```

### Known Capacitor Issue
When using Capacitor with Vue Router and iOS swipe-back gestures enabled, scrolling before navigation can cause blank pages on swipe-back. May need to disable native swipe-back and implement custom gesture handling, or avoid `scrollBehavior` in router config.

### Performance Notes
- Lazy load photos below the fold
- Use Uploadcare transforms for appropriate sizes
- Preload adjacent photos in carousel
- Consider skeleton loading states

---

## Potential Issues & Mitigations

### Issue 1: Gesture Conflicts
**Problem:** Swipe-to-go-back may conflict with photo carousel swipe
**Mitigation:**
- Use edge detection (back gesture only from screen edge)
- Disable back gesture when in photo lightbox
- iOS already handles this natively in Capacitor

### Issue 2: Keyboard Push on Reply
**Problem:** Keyboard appearing may push content awkwardly
**Mitigation:**
- Use `visualViewport` API to detect keyboard
- Animate reply section to stay above keyboard
- Consider bottom sheet pattern instead of inline expansion

### Issue 3: No-Photo Posts Look Empty
**Problem:** Without photos, the detail view may feel sparse
**Mitigation:**
- Rich placeholder graphics (category icons, illustrations)
- Larger item name typography
- More prominent location/map section
- Add visual interest through color/gradient

### Issue 4: Map Load Performance
**Problem:** Loading maps for every detail view is expensive
**Mitigation:**
- Use static map image initially (no JS)
- Load interactive map only on tap
- Cache map tiles aggressively
- Consider OpenStreetMap static tiles

### Issue 5: Accessibility Concerns
**Problem:** Gesture-only navigation excludes some users
**Mitigation:**
- Always provide tap targets as alternatives
- Screen reader announcements for carousel position
- Respect reduced motion preferences
- Ensure all actions are keyboard accessible

### Issue 6: Reply When Not Logged In
**Problem:** User flow when tapping reply while logged out
**Mitigation:**
- Show login prompt inline (not separate page)
- After login, return to same item with reply expanded
- Consider storing reply intent during auth flow

### Issue 7: Bottom Sheet Swipe Conflicts
**Problem:** Bottom sheet swipe-to-dismiss may conflict with vertical scroll
**Mitigation:**
- Only enable dismiss gesture on the drag handle area
- Use momentum threshold (fast swipe = dismiss, slow = scroll)
- Consider non-dismissible sheets for reply (close button only)

### Issue 8: Card Grid Scroll Performance
**Problem:** Large number of cards in browse grid may cause jank
**Mitigation:**
- Virtual scrolling for large lists (vue-virtual-scroller)
- Fixed height cards for better scroll calculation
- Limit initial load, infinite scroll for more
- Image placeholder sizing to prevent layout shifts

### Issue 9: Consistent Experience Web vs App
**Problem:** App-specific pages diverge from web experience
**Mitigation:**
- Keep web version unchanged for desktop users
- App pages only loaded when `mobileStore.isApp` is true
- Share core business logic components
- Clear URL structure: `/browse/app/[id]` vs `/message/[id]`

### Issue 10: Footer Ads / Sticky Banner
**Problem:** Footer ads may or may not be present, affecting layout calculations
**Mitigation:**
- Use the existing `sticky-banner.scss` variables for consistent spacing
- Footer button positioning must account for variable banner heights:
  - `$sticky-banner-height-mobile` (shorter screens)
  - `$sticky-banner-height-mobile-tall` (taller screens)
  - `$sticky-banner-height-desktop` variants
- Use CSS calc() with these variables: `bottom: calc(80px + $sticky-banner-height-mobile)`
- Consider using `@media (min-height: $mobile-tall)` for responsive adjustments
- Bottom sheet reply UI must also respect these variables
- Test with ads both enabled and disabled

**Example from existing code:**
```scss
.app-footer {
  position: fixed;
  bottom: $sticky-banner-height-mobile;
  left: 0;
  right: 0;

  @media (min-height: $mobile-tall) {
    bottom: $sticky-banner-height-mobile-tall;
  }
}
```

---

## Open Questions for Discussion

1. **Animation Library:** Should we use a dedicated animation library (e.g., Motion One) or stick with CSS transitions?

2. **Carousel Style:** Dots vs counter vs partial-preview for indicating multiple photos?

3. **Reply Suggestions:** Should quick replies be hardcoded or context-aware (e.g., based on item type)?

4. **Save/Favorite:** Should we add a save/favorite feature as part of this redesign?

5. **Share Functionality:** What share options should be available? (Copy link, native share sheet, social media?)

---

## Success Metrics

- Increased time spent on item detail pages
- Higher reply rate (conversion from view to reply)
- Reduced bounce rate on item pages
- Positive user feedback on "feel" and usability
- No increase in accessibility complaints

---

## References

### UX Research
- [Baymard: Mobile Image Gestures](https://baymard.com/blog/mobile-image-gestures) - 40% of sites fail gesture support
- [Nielsen Norman: Mobile Carousels](https://www.nngroup.com/articles/mobile-carousels/) - Swipe expectations, item limits
- [Nielsen Norman: Bottom Sheets](https://www.nngroup.com/articles/bottom-sheet/) - Modal vs non-modal patterns
- [Smashing Magazine: Carousel UX](https://www.smashingmagazine.com/2022/04/designing-better-carousel-ux/)
- [UXPin: Progressive Disclosure](https://www.uxpin.com/studio/blog/what-is-progressive-disclosure/)
- [LogRocket: Bottom Sheets for Optimized UX](https://blog.logrocket.com/ux-design/bottom-sheets-optimized-ux/)

### Design Inspiration
- [Depop Redesign Case Study](https://ankithavasudev.com/depop-redesign)
- [Product Page UX Guidelines 2024](https://onilab.com/blog/product-page-ux)
- [Mobbin: Bottom Sheet Examples](https://mobbin.com/glossary/bottom-sheet) - Real app patterns
- [Dribbble: Product Page Mobile](https://dribbble.com/tags/product-page-mobile)

### Technical Resources
- [vue3-touch-events](https://github.com/robinrodricks/vue3-touch-events) - Vue 3 gesture library
- [StfalconImageViewer](https://github.com/stfalcon-studio/StfalconImageViewer) - Android image viewer patterns
- [Capacitor iOS Swipe Issue](https://stackoverflow.com/questions/70348218/capacitorjs-ios-swipe-back-with-vue-router-causes-a-blank-page)
