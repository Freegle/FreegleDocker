# Implementation Architecture: Laravel Email Service

## Docker Environment Analysis

### Current Stack:
- **Database**: MySQL (Percona 8.0) on container `freegle-percona`
- **Queue**: Beanstalkd on container `freegle-beanstalkd`
- **Cache**: Redis on container `freegle-redis`
- **Email Testing**: MailHog on container `freegle-mailhog`
- **API v1**: PHP/Laravel container `freegle-apiv1`
- **API v2**: Go container `freegle-apiv2`
- **Web Apps**: Nuxt3 containers for dev/prod
- **Reverse Proxy**: Traefik for routing

### Current Email System:
- **15+ email-related cron jobs** running in `iznik-server/scripts/cron/`
- **Swift Mailer** for email sending via spooling system
- **Manual email templates** in `/mailtemplates/`
- **User engagement tracking** in `Engage.php` with 7 classification levels
- **No A/B testing or optimization** currently implemented

## Laravel Email Service Architecture

### Project Structure:
```
iznik-laravel-notifications/           # New Git submodule
├── app/
│   ├── Console/
│   │   ├── Commands/
│   │   │   ├── ProcessEmailQueue.php      # Main email processing
│   │   │   ├── ProcessMauticSync.php      # Sync with Mautic
│   │   │   ├── UpdateRFMSegments.php      # User segmentation
│   │   │   ├── OptimizeBanditTests.php    # Algorithm optimization
│   │   │   └── LegacyCronMigration.php    # Gradual migration helper
│   │   └── Kernel.php                     # Scheduler setup
│   ├── Services/
│   │   ├── BanditEngine.php              # Thompson Sampling core
│   │   ├── RFMSegmentationService.php    # User classification
│   │   ├── MauticIntegrationService.php  # API wrapper
│   │   ├── AIContentService.php          # OpenAI integration
│   │   ├── TemplateRenderService.php     # MJML processing
│   │   ├── LegacyEmailService.php        # Bridge to old system
│   │   └── EmailAnalyticsService.php     # Performance tracking
│   ├── Models/
│   │   ├── ExperimentVariant.php         # A/B test variants
│   │   ├── UserSegment.php               # RFM classifications
│   │   ├── NotificationEvent.php         # Tracking events
│   │   ├── BanditPerformance.php         # Algorithm metrics
│   │   ├── EmailTemplate.php             # Template management
│   │   ├── EmailLog.php                  # Audit trail
│   │   └── LegacyEmailJob.php            # Migration tracking
│   ├── Jobs/
│   │   ├── ProcessEmailEvent.php         # Async event handling
│   │   ├── SendEmailVariant.php          # Bandit-selected sending
│   │   ├── SyncWithMautic.php            # Data synchronization
│   │   ├── MigrateLegacyCron.php         # Gradual migration
│   │   └── TrackEmailPerformance.php     # Analytics collection
│   ├── Http/Controllers/
│   │   ├── API/
│   │   │   ├── ExperimentController.php  # Experiment management
│   │   │   ├── AnalyticsController.php   # Performance dashboards
│   │   │   ├── TemplateController.php    # Template CRUD
│   │   │   └── WebhookController.php     # Mautic event receiver
│   │   └── Web/
│   │       ├── DashboardController.php   # Admin interface
│   │       └── EmailPreviewController.php # Template preview
│   ├── Database/
│   │   ├── Migrations/
│   │   │   ├── 2024_01_01_create_experiments_table.php
│   │   │   ├── 2024_01_02_create_variants_table.php
│   │   │   ├── 2024_01_03_create_user_segments_table.php
│   │   │   ├── 2024_01_04_create_notification_events_table.php
│   │   │   ├── 2024_01_05_create_email_templates_table.php
│   │   │   ├── 2024_01_06_create_email_logs_table.php
│   │   │   └── 2024_01_07_create_legacy_migration_tracking.php
│   │   └── Seeders/
│   │       ├── EmailTemplateSeeder.php   # Import existing templates
│   │       └── UserSegmentSeeder.php     # Import from Engage.php
│   └── Providers/
│       ├── NotificationServiceProvider.php # Service binding
│       └── EventServiceProvider.php      # Event listeners
├── config/
│   ├── mautic.php                        # Mautic API configuration
│   ├── bandit.php                        # Algorithm settings
│   ├── openai.php                        # AI service config
│   ├── legacy.php                        # Migration settings
│   └── templates.php                     # MJML configuration
├── resources/
│   ├── views/
│   │   ├── dashboard/                    # Admin UI
│   │   ├── emails/                       # Blade email templates
│   │   └── templates/                    # MJML source files
│   └── email-templates/                  # React Email components
├── routes/
│   ├── api.php                          # API endpoints
│   ├── web.php                          # Admin interface
│   └── webhooks.php                     # External webhooks
├── tests/
│   ├── Feature/
│   │   ├── BanditEngineTest.php         # Algorithm testing
│   │   ├── MauticIntegrationTest.php    # API testing
│   │   ├── TemplateRenderTest.php       # Template compilation
│   │   └── LegacyMigrationTest.php      # Migration validation
│   └── Unit/
│       ├── RFMSegmentationTest.php      # Classification logic
│       ├── AIContentServiceTest.php     # Content generation
│       └── EmailAnalyticsTest.php       # Performance tracking
├── storage/
│   ├── email-templates/                 # Compiled templates
│   ├── mjml-cache/                      # MJML compilation cache
│   └── migration-logs/                  # Legacy migration tracking
├── docker/
│   ├── Dockerfile                       # Laravel service container
│   ├── supervisord.conf                 # Process management
│   └── mautic/
│       ├── docker-compose.mautic.yml    # Mautic integration
│       └── Dockerfile.mautic            # Custom Mautic build
├── .env.example                         # Environment template
├── composer.json                        # Laravel dependencies
├── package.json                         # Node.js for MJML/React Email
└── README.md                           # Setup instructions
```

### Integration with FreegleDocker

#### Docker Compose Service:
```yaml
# Add to main docker-compose.yml
services:
  email-service:
    container_name: freegle-email-service
    networks:
      - default
    build:
      context: ./iznik-laravel-notifications
    depends_on:
      percona:
        condition: service_healthy
      redis:
        condition: service_healthy
      beanstalkd:
        condition: service_healthy
      mautic:
        condition: service_healthy
    environment:
      - DB_CONNECTION=mysql
      - DB_HOST=percona
      - DB_PORT=3306
      - DB_DATABASE=iznik
      - DB_USERNAME=root
      - DB_PASSWORD=iznik
      - REDIS_HOST=redis
      - REDIS_PORT=6379
      - QUEUE_CONNECTION=beanstalkd
      - BEANSTALKD_HOST=beanstalkd
      - BEANSTALKD_PORT=11300
      - MAUTIC_URL=http://mautic:80
      - MAUTIC_USERNAME=${MAUTIC_USERNAME}
      - MAUTIC_PASSWORD=${MAUTIC_PASSWORD}
      - OPENAI_API_KEY=${OPENAI_API_KEY}
      - LEGACY_MIGRATION_MODE=true
    labels:
      - "traefik.enable=true"
      - "traefik.http.routers.email-service.rule=Host(`email.localhost`)"
      - "traefik.http.routers.email-service.entrypoints=web"
      - "traefik.http.services.email-service.loadbalancer.server.port=80"

  mautic:
    container_name: freegle-mautic
    networks:
      - default
    image: mautic/mautic:5-apache
    depends_on:
      percona:
        condition: service_healthy
    environment:
      - MAUTIC_DB_HOST=percona
      - MAUTIC_DB_USER=mautic
      - MAUTIC_DB_PASSWORD=${MAUTIC_DB_PASSWORD}
      - MAUTIC_DB_NAME=mautic
      - MAUTIC_TRUSTED_PROXIES=traefik
    volumes:
      - mautic_data:/var/www/html
    labels:
      - "traefik.enable=true"
      - "traefik.http.routers.mautic.rule=Host(`mautic.localhost`)"
      - "traefik.http.routers.mautic.entrypoints=web"
      - "traefik.http.services.mautic.loadbalancer.server.port=80"
```

### Database Schema Design

#### Core Tables (New):
```sql
-- Experiments and variants for bandit testing
CREATE TABLE email_experiments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    type ENUM('subject_line', 'content', 'send_time', 'template') NOT NULL,
    algorithm ENUM('thompson_sampling', 'epsilon_greedy') DEFAULT 'thompson_sampling',
    target_segment VARCHAR(100),
    traffic_allocation DECIMAL(3,2) DEFAULT 0.10,
    status ENUM('draft', 'active', 'paused', 'completed') DEFAULT 'draft',
    success_metrics JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    INDEX idx_status_type (status, type),
    INDEX idx_target_segment (target_segment)
);

CREATE TABLE email_variants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    experiment_id BIGINT UNSIGNED,
    name VARCHAR(255) NOT NULL,
    subject_line TEXT,
    content_template TEXT,
    send_time_offset INT DEFAULT 0,
    allocation_percentage DECIMAL(5,2) DEFAULT 20.00,
    total_sent INT DEFAULT 0,
    opens INT DEFAULT 0,
    clicks INT DEFAULT 0,
    conversions INT DEFAULT 0,
    conversion_value DECIMAL(10,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (experiment_id) REFERENCES email_experiments(id) ON DELETE CASCADE,
    INDEX idx_experiment_allocation (experiment_id, allocation_percentage)
);

-- User segmentation for RFM and journey mapping
CREATE TABLE user_segments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED,
    segment_type ENUM('rfm', 'journey', 'custom') NOT NULL,
    segment_value VARCHAR(100) NOT NULL,
    calculated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    metadata JSON,
    INDEX idx_user_type (user_id, segment_type),
    INDEX idx_segment_value (segment_value),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Event tracking for analytics and optimization
CREATE TABLE notification_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED,
    variant_id BIGINT UNSIGNED NULL,
    event_type ENUM('sent', 'delivered', 'opened', 'clicked', 'converted', 'bounced', 'unsubscribed') NOT NULL,
    event_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    conversion_value DECIMAL(10,2) DEFAULT 0,
    metadata JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_event_time (user_id, event_type, event_timestamp),
    INDEX idx_variant_events (variant_id, event_type),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (variant_id) REFERENCES email_variants(id) ON DELETE SET NULL
);

-- Template management
CREATE TABLE email_templates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    template_type ENUM('mjml', 'blade', 'react_email') DEFAULT 'mjml',
    source_content LONGTEXT,
    compiled_html LONGTEXT,
    compiled_amp LONGTEXT NULL,
    variables JSON,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_type_active (template_type, is_active)
);

-- Email audit trail
CREATE TABLE email_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED,
    template_id BIGINT UNSIGNED NULL,
    variant_id BIGINT UNSIGNED NULL,
    subject_line VARCHAR(255),
    recipient_email VARCHAR(255),
    status ENUM('queued', 'sent', 'failed', 'bounced') DEFAULT 'queued',
    sent_at TIMESTAMP NULL,
    error_message TEXT NULL,
    legacy_cron_job VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_status (user_id, status),
    INDEX idx_sent_at (sent_at),
    INDEX idx_legacy_job (legacy_cron_job),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (template_id) REFERENCES email_templates(id) ON DELETE SET NULL,
    FOREIGN KEY (variant_id) REFERENCES email_variants(id) ON DELETE SET NULL
);
```

#### Legacy Migration Tracking:
```sql
-- Track migration progress from legacy cron jobs
CREATE TABLE legacy_email_migration (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cron_job_name VARCHAR(100) NOT NULL,
    migration_status ENUM('pending', 'in_progress', 'completed', 'failed') DEFAULT 'pending',
    users_migrated INT DEFAULT 0,
    total_users INT DEFAULT 0,
    last_migrated_user_id BIGINT UNSIGNED NULL,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    error_log TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_cron_job (cron_job_name),
    INDEX idx_status (migration_status)
);
```

### Existing Schema Integration

#### Required Database Views:
```sql
-- Bridge to existing Engage.php classifications
CREATE VIEW user_engagement_bridge AS
SELECT
    u.id as user_id,
    'engagement' as segment_type,
    CASE
        WHEN u.engagement_level IS NULL THEN 'New'
        ELSE u.engagement_level
    END as segment_value,
    NOW() as calculated_at,
    NULL as expires_at,
    JSON_OBJECT('source', 'engage_php', 'last_activity', u.lastaccess) as metadata
FROM users u
WHERE u.deleted IS NULL;

-- Message activity for RFM calculation
CREATE VIEW user_message_activity AS
SELECT
    m.fromuser as user_id,
    COUNT(*) as frequency,
    MAX(m.arrival) as recency,
    AVG(CASE WHEN mg.collection = 'Approved' THEN 1 ELSE 0 END) as monetary_score
FROM messages m
JOIN messages_groups mg ON m.id = mg.msgid
WHERE m.deleted IS NULL
AND m.arrival > DATE_SUB(NOW(), INTERVAL 1 YEAR)
GROUP BY m.fromuser;
```

### Migration Strategy

#### Phase 1: Foundation (Weeks 1-2)
1. **Laravel project setup** as Git submodule
2. **Database migrations** for new tables
3. **Docker integration** with existing stack
4. **Basic template system** (MJML compilation)

#### Phase 2: Legacy Bridge (Weeks 3-4)
1. **LegacyEmailService** to wrap existing functionality
2. **Gradual migration commands** for each cron job
3. **Dual-mode operation** (old + new systems)
4. **Email audit logging** for all sends

#### Phase 3: Core Features (Weeks 5-8)
1. **RFM segmentation** extending Engage.php
2. **Basic bandit testing** with Thompson Sampling
3. **Mautic integration** and sync
4. **Admin dashboard** for monitoring

#### Phase 4: AI Enhancement (Weeks 9-12)
1. **OpenAI integration** for content generation
2. **Automated experiment creation**
3. **Performance optimization**
4. **Legacy system shutdown**

### Configuration Management

#### Environment Variables:
```bash
# Database (existing)
DB_CONNECTION=mysql
DB_HOST=percona
DB_DATABASE=iznik

# Queues (existing)
QUEUE_CONNECTION=beanstalkd
BEANSTALKD_HOST=beanstalkd

# New services
MAUTIC_URL=http://mautic:80
MAUTIC_USERNAME=admin
MAUTIC_PASSWORD=secret
OPENAI_API_KEY=sk-...

# Migration settings
LEGACY_MIGRATION_MODE=true
LEGACY_CRON_PARALLEL_LIMIT=3
MIGRATION_BATCH_SIZE=100

# Bandit testing
BANDIT_DEFAULT_ALGORITHM=thompson_sampling
BANDIT_MIN_SAMPLE_SIZE=100
BANDIT_CONFIDENCE_THRESHOLD=0.95

# Template compilation
MJML_VALIDATION_LEVEL=strict
REACT_EMAIL_ENABLED=false
TEMPLATE_CACHE_TTL=3600
```

This architecture provides a robust foundation for modernizing Freegle's email system while maintaining backward compatibility and enabling gradual migration from the existing cron job-based approach.