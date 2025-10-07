# Remove SMS Notifications

## Overview
Remove SMS notification functionality from Freegle. SMS notifications are no longer used and the infrastructure can be removed to simplify the codebase.

## Background
- SMS notifications were previously supported but are no longer needed
- The `users_phones` table stores phone numbers for SMS delivery
- Various server and client code references SMS settings and functionality

## Specific Code Changes Required

### Database Changes
See `migrations.md` for schema changes that need to be executed on live server:
- [ ] Drop `users_phones` table (defined in `install/schema.sql`)

### Server Code Changes (iznik-server)

#### PHP Files to Modify

**include/user/User.php** - Core user phone functionality
- [ ] Line 9: Remove `use Twilio\Rest\Client;` import
- [ ] Line 2986: Remove `UPDATE IGNORE users_phones` query in merge function
- [ ] Lines 5349-5356: Remove phone data from `getPublic()` method
- [ ] Line 5901: Remove `DELETE FROM users_phones` in delete function
- [ ] Lines 6152-6210: Remove entire SMS sending block including:
  - `formatPhone()` method (lines 6152-6170)
  - `sendSMS()` method with Twilio integration (lines 6170-6212)
- [ ] Lines 6213-6240: Remove phone management methods:
  - `addPhone()` method (lines 6213-6221)
  - `removePhone()` method (lines 6223-6228)
  - `getPhone()` method (lines 6230-6240)

**include/user/Pledge.php**
- [ ] Search for any Twilio references and remove

**include/user/Tryst.php**
- [ ] Review SMS-related code and remove

**include/chat/ChatRoom.php**
- [ ] Review SMS-related code and remove

**include/misc/Mail.php**
- [ ] Line 40: Remove `const BAD_SMS = 36;` constant
- [ ] Line 96: Remove `Mail::BAD_SMS => 'BadSMS'` from mail types array

#### Cron Scripts to Delete

- [ ] **scripts/cron/sms.php** - Entire file tracks SMS click-through and updates `users_phones.lastclicked`
- [ ] **scripts/cron/badnumber.php** - Entire file marks invalid phone numbers and sends notification emails

#### HTTP Endpoints to Delete

- [ ] **http/twilio/status.php** - Twilio callback endpoint that updates `users_phones.laststatus`

#### Utility Scripts to Delete

- [ ] **scripts/fix/fix_phones.php** - Phone number cleanup script
- [ ] **scripts/fix/fix_mobile_stats.php** - Mobile statistics script

#### Configuration Files to Update

- [ ] **install/iznik.conf.php** - Remove any Twilio configuration constants if present
- [ ] **composer/composer.json** - Remove Twilio SDK dependency
- [ ] **composer/composer.lock** - Will be regenerated after composer.json update

#### Test Files to Update

- [ ] **test/ut/php/include/UserTest.php** - Remove SMS-related test cases

### Client Code Changes (iznik-nuxt3)

#### Vue Components to Delete

- [ ] **components/SettingsPhone.vue** - Entire component for phone number input/management
  - Manages phone number input field
  - Validates UK mobile format
  - Calls authStore.saveAndGet() to save phone numbers
  - Has remove phone functionality

- [ ] **components/settings/TextAlertsSection.vue** - Entire SMS alerts settings section
  - Displays SMS alerts card in settings
  - Shows SettingsPhone component
  - Displays warnings about SMS costs
  - Shows last sent/clicked tracking info

#### Pages to Update

- [ ] **pages/settings/index.vue**
  - Line 19: Remove `<TextAlertsSection />` component
  - Line 58: Remove `import TextAlertsSection from '~/components/settings/TextAlertsSection.vue'`

- [ ] **pages/mydata.vue** - Remove any phone number display in data export

#### Store/Composables to Update

- [ ] **stores/auth.js** - Remove phone-related fields from user object if defined
- [ ] Review **composables/useMe.js** or similar for phone field handling

#### Redirects to Update

- [ ] **public/_redirects** - Remove `/twilio/status.php` redirect if present

### Client Code Changes (iznik-nuxt3-modtools)

Same changes as iznik-nuxt3 since it shares components:

- [ ] **components/SettingsPhone.vue** - Delete entire file
- [ ] **components/settings/TextAlertsSection.vue** - Delete entire file
- [ ] **pages/settings/index.vue** - Remove TextAlertsSection import and usage
- [ ] **public/_redirects** - Remove twilio redirects
- [ ] Any modtools-specific pages that show user phone numbers

### Server Code Changes (iznik-server-go)

Based on search results, Go code has minimal SMS references:

- [ ] **user/user.go** - Review for any phone-related fields in User struct
- [ ] **swagger/swagger.json** - Update API documentation to remove phone fields

### Testing
- [ ] Verify notification preferences still work for email
- [ ] Verify user settings pages render correctly
- [ ] Test notification delivery works without SMS code paths
- [ ] Run existing PHPUnit tests to ensure no regressions

## Implementation Approach

### Phase 1: Identify All References
1. Search codebase for:
   - `users_phones` table references
   - SMS-related column names
   - Phone number validation/storage
   - SMS notification delivery
   - SMS preference settings

### Phase 2: Remove Server Code
1. Remove backend SMS functionality
2. Remove API endpoints
3. Update tests
4. Verify server functionality

### Phase 3: Remove Client Code
1. Remove UI components
2. Remove settings pages
3. Update user flows
4. Test frontend changes

### Phase 4: Database Migration
1. Create backup of `users_phones` data (if needed for records)
2. Execute schema changes (see migrations.md)
3. Verify application works without table

## Risks & Considerations

- **Data Loss**: Phone numbers will be permanently removed - ensure no legal/compliance requirement to retain
- **User Impact**: Users who had SMS notifications enabled will need to use email instead
- **Migration Timing**: Schema changes must be coordinated with deployment
- **Rollback**: Keep backup of dropped tables for emergency rollback period

## Success Criteria

- [ ] All SMS-related code removed from server
- [ ] All SMS-related UI removed from clients
- [ ] No references to `users_phones` table in code
- [ ] All tests passing
- [ ] Database migration documented and ready
- [ ] No errors in production after deployment
