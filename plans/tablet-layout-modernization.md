# Tablet Layout Modernization Plan

## Overview

This plan covers the tablet layout modernization for the Freegle web application. Following the recent mobile (xs/sm) modernization work, we now focus on optimizing the tablet experience at the `md` breakpoint (820px - iPad Air).

**Branch:** `tablet-layout-modernization`

## Breakpoint Reference

| Breakpoint | Width | Device | Current Treatment |
|------------|-------|--------|-------------------|
| xs | 414px | iPhone | Mobile flow |
| sm | 640px | Large phone | Mobile flow |
| **md** | **820px** | **iPad Air** | **Desktop (narrow)** - TARGET |
| lg | 1024px | iPad Pro | Desktop |
| xl | 1280px | Desktop | Desktop |
| xxl | 1920px | Large desktop | Desktop |

## Goals

1. Optimize the `md` breakpoint for a better tablet experience.
2. Decide which pages should use mobile-style layouts vs desktop-style layouts at tablet width.
3. Improve readability and usability on tablet devices.
4. Maintain consistency with recent mobile modernization patterns.

## Key Decisions

### Approach: "Large Mobile" vs "Small Desktop"

For the md breakpoint (820px), we have two options:

**Option A: Treat md like "large mobile"**
- Extend mobile flows to md breakpoint
- Single-column layouts, larger touch targets
- Simpler navigation patterns

**Option B: Keep md as "small desktop" with refinements**
- Continue using multi-column layouts
- Optimize column ratios for narrower screens
- Improve spacing and typography

**Recommendation:** Hybrid approach
- **Chat pages:** Use desktop 2-pane layout (chat list + chat pane) - works well
- **Browse page:** 2-column grid is good, no change needed
- **Posting flows:** Consider extending mobile flow to md for simpler UX
- **Content pages:** Optimize column widths and reduce empty space

---

## Pages to Modernize

### 1. Chat Page (`/chats`)

**Current:** 4+8 column split (chat list + chat pane) on md
**Issue:** Chat list is narrow (4 cols = ~270px), could be wider on tablet

**Changes:**
- Adjust column split to 5+7 on md for better balance
- Increase chat list item padding for touch targets
- Consider showing more of the chat snippet

**Files:**
- `pages/chats/[[id]].vue`
- `components/ChatListEntry.vue`
- `components/ChatPane.vue`

### 2. Posting Flow (`/give`, `/find`)

**Current:** Desktop layout on md with 8-column centered form
**Issue:** Form feels cramped with narrow columns

**Changes:**
- Option 1: Extend mobile flow to md (simpler)
- Option 2: Use 10-column width on md (more space)
- Remove WizardProgress on md (takes up space)
- Larger input fields and buttons

**Files:**
- `pages/give/index.vue`
- `pages/give/whereami.vue`
- `pages/give/whoami.vue`
- `pages/find/index.vue`
- `pages/find/whereami.vue`
- `pages/find/whoami.vue`
- `components/WizardProgress.vue`

### 3. Browse Page (`/browse`)

**Current:** 2-column message grid on md
**Status:** Works well, minimal changes needed

**Changes:**
- Review filter panel width
- Ensure touch targets are adequate
- Check "You're up to date" notice sizing

**Files:**
- `pages/browse.vue`
- `components/PostFilters.vue`
- `components/MessageSummary.vue`

### 4. Message Detail Pages

**Current:** Full-width with sidebars on larger screens
**Issue:** May have excessive whitespace or narrow content

**Changes:**
- Hide sidebar on md (currently only xl+)
- Center content with appropriate max-width
- Improve reply section layout

**Files:**
- `components/OurMessage.vue`
- `components/MessageExpanded.vue`
- `pages/message/[id].vue`

### 5. Settings Pages

**Current:** 3-col empty + 6-col content + 3-col empty pattern
**Issue:** Content squeezed into center, wasted space

**Changes:**
- Use full width on md
- Remove empty columns on tablet
- Larger form controls

**Files:**
- `pages/settings/index.vue`
- `pages/settings/*.vue`

### 6. Profile Pages

**Current:** Similar to settings - centered narrow content
**Changes:** Similar to settings - use more width

**Files:**
- `pages/profile/[id].vue`

### 7. Content Pages (About, Help, Stories, etc.)

**Current:** Various layouts, some with empty side columns
**Changes:**
- Remove empty columns on md
- Use page-wrapper pattern consistently
- Improve readability with appropriate line lengths

**Files:**
- `pages/about.vue`
- `pages/help.vue`
- `pages/stories/*.vue`
- `pages/privacy.vue`
- `pages/terms.vue`

---

## Implementation Tasks

### Phase 1: Core Pages

1. [ ] **Chat page column optimization**
   - Adjust column ratios for md breakpoint
   - Test chat list + chat pane layout

2. [ ] **Posting flow improvements**
   - Decide: extend mobile flow to md OR optimize desktop
   - Implement chosen approach for /give
   - Implement for /find

3. [ ] **Settings page width**
   - Remove empty columns on md
   - Test all settings pages

### Phase 2: Content Pages

4. [ ] **Profile page layout**
   - Optimize column usage
   - Test profile viewing

5. [ ] **Content pages (about, help, etc.)**
   - Apply consistent page-wrapper pattern
   - Remove unnecessary empty columns

### Phase 3: Polish

6. [ ] **Touch target review**
   - Ensure buttons/links are at least 44x44px
   - Review all interactive elements

7. [ ] **Typography review**
   - Check line lengths (45-75 characters ideal)
   - Adjust font sizes if needed

8. [ ] **Testing**
   - Test all pages at 820px width
   - Test at 1024px width (lg)
   - Test touch interactions

---

## CSS Patterns to Use

### Removing Empty Columns on Tablet

```vue
<!-- Before -->
<b-col cols="0" md="3" class="d-none d-md-block" />
<b-col cols="12" md="6">Content</b-col>
<b-col cols="0" md="3" class="d-none d-md-block" />

<!-- After -->
<b-col cols="12" lg="3" class="d-none d-lg-block" />
<b-col cols="12" lg="6">Content</b-col>
<b-col cols="12" lg="3" class="d-none d-lg-block" />
```

### Responsive Column Adjustments

```scss
@include media-breakpoint-only(md) {
  /* Tablet-specific styles */
}

@include media-breakpoint-between(md, lg) {
  /* md and lg only */
}
```

### Breakpoint Detection in JavaScript

```javascript
const isTablet = computed(() =>
  miscStore.breakpoint === 'md' || miscStore.breakpoint === 'lg'
)

const isMobileOrTablet = computed(() =>
  ['xs', 'sm', 'md'].includes(miscStore.breakpoint)
)
```

---

## Testing Approach

1. **Browser DevTools**
   - Use Chrome DevTools device emulation
   - iPad Air preset (820x1180)
   - iPad Pro preset (1024x1366)

2. **Chrome Remote Debugging + MCP**
   - Navigate to pages
   - Take screenshots at tablet dimensions
   - Verify layouts visually

3. **Manual Testing**
   - Test on actual tablet if available
   - Check touch interactions
   - Verify scrolling behavior

---

## Success Criteria

- [ ] All pages render correctly at 820px width
- [ ] No horizontal scrolling on tablet
- [ ] Touch targets meet minimum size (44x44px)
- [ ] Content is readable without zooming
- [ ] Forms are easy to complete on tablet
- [ ] Navigation is intuitive
- [ ] No layout shifts or content overlap
