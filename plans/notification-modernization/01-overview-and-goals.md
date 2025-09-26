# Freegle Notification System Modernization - Overview

## Project Vision

Transform Freegle's notification infrastructure from legacy PHP cron jobs to an intelligent, unified system that optimizes engagement across all communication channels using multi-armed bandit testing and sophisticated user segmentation.

## Strategic Goals

### 1. **Unified Communication Platform**
- Consolidate email, push notifications, in-app messages, and ModTools notifications
- Single Laravel-based system replacing multiple PHP cron jobs
- Cross-channel coordination and frequency management

### 2. **Intelligent Optimization**
- Multi-armed bandit testing (Thompson Sampling + Epsilon-Greedy)
- Real-time traffic allocation to best-performing variants
- Test on small user fractions with automatic optimization

### 3. **Advanced User Understanding**
- RFM segmentation adapted for community platforms
- Customer journey mapping across 6 lifecycle stages
- Contextual personalization based on engagement patterns

### 4. **Enhanced Analytics & Attribution**
- Multi-touch attribution across all touchpoints
- Real-time performance monitoring and insights
- Comprehensive event tracking and session stitching

## Current State Analysis

### Email Infrastructure (Legacy)
- **Volume**: Hundreds of thousands of emails daily
- **Reliability**: Swift Mailer with spool file fallback
- **Processing**: 10+ separate PHP cron jobs
- **Templates**: 35+ MJML templates with Twig processing
- **Tracking**: Basic open/click tracking via `alerts_tracking`

### User Engagement System (Existing)
Freegle already segments users via `Engage.php`:
- **New**: Recently joined users (< 31 days)
- **Occasional**: Light activity, recent access
- **Frequent**: Regular posting and participation
- **Obsessed**: High-frequency users
- **Inactive**: No recent activity (14+ days)
- **AtRisk**: Declining engagement patterns
- **Dormant**: Long-term inactive (6+ months)

### Technical Constraints
- ✅ **Database schema is fixed** - no structural changes allowed
- ✅ **Performance patterns must be preserved** - maintain optimized queries
- ✅ **Docker integration required** - within FreegleDocker environment
- ✅ **High volume support** - current scale must be maintained

## Proposed Architecture

### Core Components
1. **Laravel Notification Service** - Unified processing engine
2. **Bandit Algorithm Engine** - Thompson Sampling optimization
3. **RFM Segmentation System** - Enhanced user classification
4. **Multi-Channel Template System** - Modern alternatives to MJML
5. **Attribution & Analytics Platform** - Comprehensive tracking
6. **Queue Management System** - Reliable, scalable processing

### Key Innovations
- **Contextual Bandits**: Use RFM segments and journey stages as context
- **Community Value Scoring**: Adapt RFM for non-monetary platform
- **Cross-Channel Experiments**: Coordinate testing across notification types
- **Real-Time Personalization**: Dynamic content based on user behavior

## Implementation Strategy

### Phase Approach
1. **Foundation**: Laravel setup, Docker integration, basic queue system
2. **Migration**: Move existing cron jobs to unified scheduler
3. **Enhancement**: Add bandit testing and RFM segmentation
4. **Optimization**: Advanced analytics, attribution, and personalization
5. **Scaling**: Performance optimization and monitoring

### Technology Decisions
- **Framework**: Laravel (familiar, maintainable, extensive ecosystem)
- **Queue System**: Laravel queues with spool file compatibility
- **Templates**: Evaluate React Email, Maizzle vs MJML
- **Analytics**: Custom tracking with JSON event storage
- **Algorithms**: Thompson Sampling (primary), Epsilon-Greedy (fallback)

## Success Metrics

### Functional Requirements
- All existing email types continue working
- Single unified cron job replaces 10+ separate jobs
- No lost emails during transition
- Enhanced moderator visibility into email logs

### Performance Requirements
- Match or exceed current throughput
- Sub-second bandit algorithm decisions
- Real-time RFM segment updates
- 99.9% email delivery reliability

### Optimization Requirements
- Measurable improvement in engagement rates
- Automatic traffic allocation to best variants
- Reduced manual email campaign management
- Foundation ready for future notification channels

## Next Steps

1. **Review current system analysis** (detailed in separate files)
2. **Evaluate implementation plans** for each component
3. **Approve technical architecture** and technology choices
4. **Begin foundation phase** with Laravel setup and Docker integration

---

*This overview provides the strategic context. Detailed technical specifications and implementation plans are in separate documents.*