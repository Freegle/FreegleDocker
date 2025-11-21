<?php
/**
 * Unified test environment setup for FreegleDocker.
 *
 * This script sets up all required test data for:
 * - Go API tests (iznik-server-go)
 * - PHPUnit tests (iznik-server)
 * - Playwright E2E tests (iznik-nuxt3)
 */

namespace Freegle\Iznik;

require_once '/var/www/iznik/include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

error_log("Setting up unified test environment for Go, PHP, and E2E tests");

# Check for groups
$g = new Group($dbhr, $dbhm);
$gid = $g->findByShortName('FreeglePlayground');

if (!$gid) {
    # Create FreeglePlayground group
    error_log("Creating FreeglePlayground group");
    $gid = $g->create('FreeglePlayground', Group::GROUP_FREEGLE);
    $g->setPrivate('onhere', 1);
    $g->setPrivate('polyofficial', 'POLYGON((-3.1902622 55.9910847, -3.2472542 55.98263430000001, -3.2863922 55.9761038, -3.3159182 55.9522754, -3.3234712 55.9265089, -3.304932200000001 55.911888, -3.3742832 55.8880206, -3.361237200000001 55.8718436, -3.3282782 55.8729997, -3.2520602 55.8964911, -3.2177282 55.895336, -3.2060552 55.8903307, -3.1538702 55.88648049999999, -3.1305242 55.893411, -3.0989382 55.8972611, -3.0680392 55.9091938, -3.0584262 55.9215076, -3.0982522 55.928048, -3.1037452 55.9418938, -3.1236572 55.9649602, -3.168289199999999 55.9849393, -3.1902622 55.9910847))');
    $g->setPrivate('lat', 55.9533);
    $g->setPrivate('lng', -3.1883);
} else {
    error_log("FreeglePlayground group already exists (id: $gid)");
}

# Check for FreeglePlayground2 group
$gid2 = $g->findByShortName('FreeglePlayground2');
if (!$gid2) {
    error_log("Creating FreeglePlayground2 group");
    $gid2 = $g->create('FreeglePlayground2', Group::GROUP_FREEGLE);
    $g->setPrivate('onhere', 1);
    $g->setPrivate('contactmail', 'contact@test.com');
    $g->setPrivate('namefull', 'Freegle Playground2');
} else {
    error_log("FreeglePlayground2 group already exists (id: $gid2)");
}

# Check for locations
$l = new Location($dbhr, $dbhm);
$existing_location = $dbhr->preQuery("SELECT id FROM locations WHERE name = ? LIMIT 1", ['Central']);
if (!$existing_location) {
    error_log("Creating locations");
    $l->copyLocationsToPostgresql();
    $areaid = $l->create(NULL, 'Central', 'Polygon', 'POLYGON((-3.217620849609375 55.9565040997114,-3.151702880859375 55.9565040997114,-3.151702880859375 55.93304863776238,-3.217620849609375 55.93304863776238,-3.217620849609375 55.9565040997114))');
    $pcid = $l->create(NULL, 'EH3 6SS', 'Postcode', 'POINT(-3.205333 55.957571)');
    $l->copyLocationsToPostgresql(FALSE);
} else {
    error_log("Locations already exist");
    # Get existing postcode ID
    $existing_pc = $dbhr->preQuery("SELECT id FROM locations WHERE name = ? AND type = ? LIMIT 1", ['EH3 6SS', 'Postcode']);
    $pcid = $existing_pc ? $existing_pc[0]['id'] : NULL;
}

# Check for test users
$u = new User($dbhr, $dbhm);
$existing_users = $dbhr->preQuery("SELECT id FROM users WHERE fullname = ? AND deleted IS NULL", ['Test User']);

if (!$existing_users) {
    error_log("Creating Test User");
    $uid = $u->create('Test', 'User', 'Test User');
    $u->addEmail('test@test.com');
    $ouremail = $u->inventEmail();
    $u->addEmail($ouremail, 0, FALSE);
    $u->addLogin(User::LOGIN_NATIVE, NULL, 'freegle');
    $u->addMembership($gid);
    $u->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);
} else {
    error_log("Test User already exists");
    $uid = $existing_users[0]['id'];
    # Ensure user is in the group
    $existing_membership = $dbhr->preQuery("SELECT id FROM memberships WHERE userid = ? AND groupid = ?", [$uid, $gid]);
    if (!$existing_membership) {
        $u = new User($dbhr, $dbhm, $uid);
        $u->addMembership($gid);
        $u->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);
    }
}

# Check for isochrone
if ($pcid) {
    $existing_isochrone = $dbhr->preQuery("SELECT id FROM isochrones_users WHERE userid = ? LIMIT 1", [$uid]);
    if (!$existing_isochrone) {
        error_log("Creating isochrone for user $uid");
        $i = new Isochrone($dbhr, $dbhm);
        $id = $i->create($uid, Isochrone::WALK, Isochrone::MAX_TIME, NULL, $pcid);
    } else {
        error_log("Isochrone already exists for user $uid");
    }
}

# Check for moderator user
$existing_mod = $dbhr->preQuery("SELECT id FROM users WHERE deleted IS NULL AND id IN (SELECT userid FROM memberships WHERE role = ?)", [User::ROLE_MODERATOR]);
if (!$existing_mod) {
    error_log("Creating moderator user");
    $uid2 = $u->create('Test', 'User', NULL);
    $u->addEmail('testmod@test.com');
    $u->addLogin(User::LOGIN_NATIVE, NULL, 'freegle');
    $u->addMembership($gid, User::ROLE_MODERATOR);
    $u->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);
} else {
    error_log("Moderator user already exists");
    $uid2 = $existing_mod[0]['id'];
}

# Check for additional test user with full requirements (needed for Go tests that require 2 different regular users)
# Simplified query - just look for user with email test2@test.com
$existing_users3 = $dbhr->preQuery("SELECT u.id FROM users u
    INNER JOIN users_emails ue ON ue.userid = u.id
    WHERE u.deleted IS NULL AND ue.email = 'test2@test.com'
    LIMIT 1");

if (!$existing_users3) {
    error_log("Creating additional test user with full requirements");
    $uid3 = $u->create('Test', 'User2', 'Test User 2');
    $u->addEmail('test2@test.com');
    $ouremail2 = $u->inventEmail();
    $u->addEmail($ouremail2, 0, FALSE);
    $u->addLogin(User::LOGIN_NATIVE, NULL, 'freegle');
    $u->addMembership($gid);
    $u->setMembershipAtt($gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

    # Create address for second user
    if ($pcid) {
        $pafs = $dbhr->preQuery("SELECT * FROM paf_addresses LIMIT 1;");
        foreach ($pafs as $paf) {
            $a = new Address($dbhr, $dbhm);
            $aid3 = $a->create($uid3, $paf['id'], "Test desc for user 2");
            error_log("Created address $aid3 for user $uid3");
        }

        # Skip isochrone for second user (causes issues with missing 'source' column)
        error_log("Skipping isochrone for user $uid3 (not required for address tests)");
    }

    # Create chats for second user
    $r = new ChatRoom($dbhr, $dbhm);
    list ($rid_u3, $banned) = $r->createConversation($uid3, $uid2);
    $cm = new ChatMessage($dbhr, $dbhm);
    $mid1 = $cm->create($rid_u3, $uid3, "Test message from user 2");
    $dbhm->preExec("UPDATE chat_rooms SET latestmessage = NOW() WHERE id = ?", [$rid_u3]);
    error_log("Created chat message $mid1 in room $rid_u3 for user $uid3");

    $rid_u3_mod = $r->createUser2Mod($uid3, $gid);
    $mid2 = $cm->create($rid_u3_mod, $uid3, "Test mod message from user 2");
    $dbhm->preExec("UPDATE chat_rooms SET latestmessage = NOW() WHERE id = ?", [$rid_u3_mod]);
    error_log("Created chat message $mid2 in room $rid_u3_mod for user $uid3");

    error_log("Created user $uid3 (test2@test.com) with all requirements");
} else {
    error_log("Additional test user with full requirements already exists");
    $uid3 = $existing_users3[0]['id'];
}

# Check for deleted user
$existing_deleted = $dbhr->preQuery("SELECT id FROM users WHERE deleted IS NOT NULL LIMIT 1");
if (!$existing_deleted) {
    error_log("Creating deleted user");
    $uid4 = $u->create('Test', 'User', NULL);
    $u->setPrivate('deleted', '2024-01-01');
} else {
    error_log("Deleted user already exists");
    $uid4 = $existing_deleted[0]['id'];
}

# Check for Support user
$existing_support = $dbhr->preQuery("SELECT id FROM users WHERE systemrole = ? AND deleted IS NULL LIMIT 1", [User::SYSTEMROLE_SUPPORT]);
if (!$existing_support) {
    error_log("Creating Support user");
    $uid5 = $u->create('Support', 'User', NULL);
    $u->addEmail('testsupport@test.com');
    $u->addLogin(User::LOGIN_NATIVE, NULL, 'freegle');
    $u->addMembership($gid, User::ROLE_MODERATOR);
    $u->setPrivate('systemrole', User::SYSTEMROLE_SUPPORT);
} else {
    error_log("Support user already exists");
    $uid5 = $existing_support[0]['id'];
}

# Check for Admin user
$existing_admin = $dbhr->preQuery("SELECT id FROM users WHERE systemrole = ? AND deleted IS NULL LIMIT 1", [User::SYSTEMROLE_ADMIN]);
if (!$existing_admin) {
    error_log("Creating Admin user");
    $uid6 = $u->create('Admin', 'User', NULL);
    $u->addEmail('testadmin@test.com');
    $u->addLogin(User::LOGIN_NATIVE, NULL, 'freegle');
    $u->addMembership($gid, User::ROLE_MODERATOR);
    $u->setPrivate('systemrole', User::SYSTEMROLE_ADMIN);
} else {
    error_log("Admin user already exists");
    $uid6 = $existing_admin[0]['id'];
}

# Check for chat rooms
$existing_chats = $dbhr->preQuery("SELECT COUNT(*) as count FROM chat_rooms");
if ($existing_chats[0]['count'] == 0) {
    error_log("Creating chat rooms");
    $r = new ChatRoom($dbhr, $dbhm);
    list ($rid, $banned) = $r->createConversation($uid, $uid2);
    $cm = new ChatMessage($dbhr, $dbhm);
    $cm->create($rid, $uid, "The plane in Spayne falls mainly on the reign.");
    $dbhm->preExec("UPDATE chat_rooms SET latestmessage = NOW() WHERE id = ?", [$rid]);

    $rid2 = $r->createUser2Mod($uid, $gid);
    $cm->create($rid2, $uid, "The plane in Spayne falls mainly on the reign.");
    $dbhm->preExec("UPDATE chat_rooms SET latestmessage = NOW() WHERE id = ?", [$rid2]);

    $rid3 = $r->createUser2Mod($uid, $gid2);
    $cm->create($rid3, $uid, "The plane in Spayne falls mainly on the reign.");
    $dbhm->preExec("UPDATE chat_rooms SET latestmessage = NOW() WHERE id = ?", [$rid3]);
} else {
    error_log("Chat rooms already exist");
    $dbhm->preExec("UPDATE chat_rooms SET latestmessage = NOW() WHERE latestmessage IS NULL OR latestmessage < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    error_log("Updated existing chat room timestamps");
}

# Check for newsfeed items
$existing_newsfeed = $dbhr->preQuery("SELECT COUNT(*) as count FROM newsfeed");
if ($existing_newsfeed[0]['count'] == 0) {
    error_log("Creating newsfeed items");
    $n = new Newsfeed($dbhr, $dbhm);
    $nid = $n->create(Newsfeed::TYPE_MESSAGE, $uid, "This is a test post mentioning https://www.ilovefreegle.org");
    $nid2 = $n->create(Newsfeed::TYPE_MESSAGE, $uid, "This is a test reply mentioning https://www.ilovefreegle.org", NULL, NULL, $nid);
} else {
    error_log("Newsfeed items already exist");
}

# Create messages that meet Go API requirements
$existing_test_messages = $dbhr->preQuery("SELECT COUNT(*) as count FROM messages WHERE subject LIKE 'OFFER: Test Item % (Go Test Location)'");
if ($existing_test_messages[0]['count'] < 2) {
    error_log("Creating messages that meet Go API requirements");
    $messages_to_create = 2;
    for ($i = 1; $i <= $messages_to_create; $i++) {
        $subject = "OFFER: Test Item $i (Go Test Location)";
        $body = "Test item $i available for collection. Good condition. Created for Go tests.";

        # Check if this specific message already exists
        $existing_msg = $dbhr->preQuery("SELECT id FROM messages WHERE subject = ? LIMIT 1", [$subject]);
        if ($existing_msg) {
            error_log("Message already exists: $subject");
            continue;
        }

        error_log("Creating message $i: $subject");
        $dbhm->preExec("INSERT INTO messages (fromuser, subject, textbody, type, arrival, lat, lng) VALUES (?, ?, ?, 'Offer', NOW(), 55.9533, -3.1883)", [$uid, $subject, $body]);
        $msg_id = $dbhm->lastInsertId();

        if ($msg_id) {
            $dbhm->preExec("INSERT INTO messages_groups (msgid, groupid, collection, arrival, deleted) VALUES (?, ?, 'Approved', NOW(), 0)", [$msg_id, $gid]);
            $dbhm->preExec("INSERT INTO messages_spatial (msgid, successful, arrival, point) VALUES (?, 1, NOW(), ST_SRID(POINT(-3.1883,55.9533), 3857))", [$msg_id]);
            if ($pcid) {
                $dbhm->preExec("UPDATE messages SET locationid = ? WHERE id = ?", [$pcid, $msg_id]);
            }

            $m = new Message($dbhr, $dbhm, $msg_id);
            $m->index();
            error_log("Created and indexed message $msg_id - $subject");
        }
    }
} else {
    error_log("Test messages already exist");
}

# Check for items
$existing_items = $dbhr->preQuery("SELECT COUNT(*) as count FROM items WHERE name = ?", ['chair']);
if ($existing_items[0]['count'] == 0) {
    error_log("Creating test items");
    $i = new Item($dbhr, $dbhm);
    $i->create('chair');
} else {
    error_log("Test items already exist");
}

# Insert reference data required by PHP tests
error_log("Inserting reference data for PHP tests");

# Spam keywords
$dbhm->preExec("INSERT IGNORE INTO `spam_keywords` (`id`, `word`, `exclude`, `action`, `type`) VALUES (8, 'viagra', NULL, 'Spam', 'Literal'), (76, 'weight loss', NULL, 'Spam', 'Literal'), (77, 'spamspamspam', NULL, 'Review', 'Literal');");
$dbhm->preExec('REPLACE INTO `spam_keywords` (`id`, `word`, `exclude`, `action`, `type`) VALUES (272, \'(?<!\\\\bwater\\\\W)\\\\bbutt\\\\b(?!\\\\s+rd)\', NULL, \'Review\', \'Regex\');');

# Location for PAF tests (SA65 9ET - required by giftaidAPITest)
$dbhm->preExec("INSERT IGNORE INTO `locations` (`id`, `osm_id`, `name`, `type`, `osm_place`, `geometry`, `ourgeometry`, `gridid`, `postcodeid`, `areaid`, `canon`, `popularity`, `osm_amenity`, `osm_shop`, `maxdimension`, `lat`, `lng`, `timestamp`) VALUES
  (1687412, '189543628', 'SA65 9ET', 'Postcode', 0, ST_GeomFromText('POINT(-4.939858 52.006292)', {$dbhr->SRID()}), NULL, NULL, NULL, NULL, 'sa659et', 0, 0, 0, '0.002916', '52.006292', '-4.939858', '2016-08-23 06:01:25');");

# PAF address for giftaidAPITest
$dbhm->preExec("INSERT IGNORE INTO `paf_addresses` (`id`, `postcodeid`, `udprn`) VALUES (102367696, 1687412, 50464672);");

# Weights for ItemTest
$dbhm->preExec("INSERT IGNORE INTO weights (name, simplename, weight, source) VALUES ('2 seater sofa', 'sofa', 37, 'FRN 2009');");

# Spam countries for MailRouterTest
$dbhm->preExec("INSERT IGNORE INTO spam_countries (country) VALUES ('Cameroon');");

# Spam whitelist links
$dbhm->preExec("INSERT IGNORE INTO spam_whitelist_links (domain, count) VALUES ('users.ilovefreegle.org', 3);");
$dbhm->preExec("INSERT IGNORE INTO spam_whitelist_links (domain, count) VALUES ('freegle.in', 3);");

# Towns
$dbhm->preExec("INSERT IGNORE INTO towns (name, lat, lng, position) VALUES ('Edinburgh', 55.9500,-3.2000, ST_GeomFromText('POINT (-3.2000 55.9500)', {$dbhr->SRID()}));");

# Engage mails for EngageTest
$existing_engage = $dbhr->preQuery("SELECT COUNT(*) as count FROM engage_mails");
if ($existing_engage[0]['count'] == 0) {
    error_log("Creating engage_mails data");
    $dbhm->preExec("INSERT INTO `engage_mails` (`id`, `engagement`, `template`, `subject`, `text`, `shown`, `action`, `rate`, `suggest`) VALUES
(1, 'AtRisk', 'inactive', 'We\\'ll stop sending you emails soon...', 'It looks like you\\'ve not been active on Freegle for a while. So that we don\\'t clutter your inbox, and to reduce the load on our servers, we\\'ll stop sending you emails soon.\\n\\nIf you\\'d still like to get them, then just go to www.ilovefreegle.org and log in to keep your account active.\\n\\nMaybe you\\'ve got something lying around that someone else could use, or perhaps there\\'s something someone else might have?', 249, 14, '5.62', 1),
(4, 'Inactive', 'missing', 'We miss you!', 'We don\\'t think you\\'ve freegled for a while.  Can we tempt you back?  Just come to https://www.ilovefreegle.org', 4681, 63, '1.35', 1),
(7, 'AtRisk', 'inactive', 'Do you want to keep receiving Freegle mails?', 'It looks like you\\'ve not been active on Freegle for a while. So that we don\\'t clutter your inbox, and to reduce the load on our servers, we\\'ll stop sending you emails soon.\\r\\n\\r\\nIf you\\'d still like to get them, then just go to www.ilovefreegle.org and log in to keep your account active.\\r\\n\\r\\nMaybe you\\'ve got something lying around that someone else could use, or perhaps there\\'s something someone else might have?', 251, 8, '3.19', 1),
(10, 'Inactive', 'missing', 'Time for a declutter?', 'We don\\'t think you\\'ve freegled for a while.  Can we tempt you back?  Just come to https://www.ilovefreegle.org', 1257, 8, '0.64', 1),
(13, 'Inactive', 'missing', 'Anything Freegle can help you get?', 'We don\\'t think you\\'ve freegled for a while.  Can we tempt you back?  Just come to https://www.ilovefreegle.org', 1366, 5, '0.37', 1);");
} else {
    error_log("engage_mails data already exists");
}

# Jobs for jobsAPITest
error_log("Creating jobs that meet Go API requirements");
$dbhm->preExec("INSERT IGNORE INTO `jobs` (`location`, `title`, `city`, `state`, `zip`, `country`, `job_type`, `posted_at`, `job_reference`, `company`, `mobile_friendly_apply`, `category`, `html_jobs`, `url`, `body`, `cpc`, `geometry`, `visible`) VALUES
('Test Location GoAPI', 'Go API Test Job', 'Test City', 'Test State', '', 'United Kingdom', 'Full Time', NOW(), 'GOAPI_001', 'TestCompany', 'No', 'Technology', 'No', 'https://example.com/goapi-job', 'Test job for Go API requirements. CPC >= 0.10, visible = 1, has category.', '0.15', ST_GeomFromText('POINT(-2.0455619 52.5833189)', {$dbhr->SRID()}), 1)");

$dbhm->preExec("INSERT IGNORE INTO `jobs` (`location`, `title`, `city`, `state`, `zip`, `country`, `job_type`, `posted_at`, `job_reference`, `company`, `mobile_friendly_apply`, `category`, `html_jobs`, `url`, `body`, `cpc`, `geometry`, `visible`) VALUES
('Nearby Location', 'Nearby Test Job', 'Test City', 'Test State', '', 'United Kingdom', 'Part Time', NOW(), 'GOAPI_002', 'TestCompany2', 'No', 'Engineering', 'No', 'https://example.com/goapi-job2', 'Second test job near the test coordinates.', '0.12', ST_GeomFromText('POINT(-2.0465619 52.5843189)', {$dbhr->SRID()}), 1)");

# Create address for the user
$existing_address = $dbhr->preQuery("SELECT id FROM users_addresses WHERE userid = ? LIMIT 1", [$uid]);
if (!$existing_address) {
    error_log("Creating address for user $uid");
    $a = new Address($dbhr, $dbhm);
    $pafs = $dbhr->preQuery("SELECT * FROM paf_addresses LIMIT 1;");
    foreach ($pafs as $paf) {
        $aid = $a->create($uid, $paf['id'], "Test desc");
        error_log("Created address $aid for user $uid");
    }
} else {
    error_log("Address already exists for user $uid");
}

# Check for sessions
$existing_sessions = $dbhr->preQuery("SELECT COUNT(*) as count FROM sessions WHERE userid IN (?, ?, ?, ?)", [$uid, $uid2, $uid5, $uid6]);
if ($existing_sessions[0]['count'] < 4) {
    error_log("Creating user sessions");
    $s = new Session($dbhr, $dbhm);

    $existing_session_uid = $dbhr->preQuery("SELECT id FROM sessions WHERE userid = ? LIMIT 1", [$uid]);
    if (!$existing_session_uid) {
        $s->create($uid);
    }

    $existing_session_uid2 = $dbhr->preQuery("SELECT id FROM sessions WHERE userid = ? LIMIT 1", [$uid2]);
    if (!$existing_session_uid2) {
        $s->create($uid2);
    }

    $existing_session_uid5 = $dbhr->preQuery("SELECT id FROM sessions WHERE userid = ? LIMIT 1", [$uid5]);
    if (!$existing_session_uid5) {
        $s->create($uid5);
    }

    $existing_session_uid6 = $dbhr->preQuery("SELECT id FROM sessions WHERE userid = ? LIMIT 1", [$uid6]);
    if (!$existing_session_uid6) {
        $s->create($uid6);
    }
} else {
    error_log("User sessions already exist");
}

# Ensure volunteer opportunities meet Go test requirements
$go_api_volunteering = $dbhr->preQuery("SELECT COUNT(*) as count FROM volunteering v
    INNER JOIN volunteering_dates vd ON vd.volunteeringid = v.id
    WHERE v.pending = 0 AND v.deleted = 0 AND v.heldby IS NULL");

if ($go_api_volunteering[0]['count'] == 0) {
    error_log("Creating volunteer opportunity that meets Go API requirements");
    $c = new Volunteering($dbhm, $dbhm);
    $id = $c->create($uid, 'Go Test Volunteer Opportunity', FALSE, 'Test location for Go tests', NULL, NULL, NULL, NULL, NULL, NULL);

    $c->setPrivate('pending', 0);
    $c->setPrivate('deleted', 0);
    $c->setPrivate('heldby', NULL);

    $start = Utils::ISODate('@' . (time()+6000));
    $end = Utils::ISODate('@' . (time()+12000));
    $c->addDate($start, $end, NULL);

    $c->addGroup($gid);
    error_log("Created volunteer opportunity $id meeting Go API requirements");
} else {
    error_log("Volunteer opportunities already exist that meet Go API requirements");
}

# Check for community events
$existing_events = $dbhr->preQuery("SELECT COUNT(*) as count FROM communityevents");
if ($existing_events[0]['count'] == 0) {
    error_log("Creating community event");
    $c = new CommunityEvent($dbhm, $dbhm);
    $id = $c->create($uid, 'Test event', 'Test location', NULL, NULL, NULL, NULL, NULL);
    $c->setPrivate('pending', 0);
    $start = Utils::ISODate('@' . (time()+6000));
    $end = Utils::ISODate('@' . (time()+6000));
    $c->addDate($start, $end, NULL);
    $c->addGroup($gid);
} else {
    error_log("Community events already exist");
}

# Insert remaining test data
$dbhm->preExec("INSERT IGNORE INTO partners_keys (`partner`, `key`, `domain`) VALUES ('lovejunk', 'testkey123', 'localhost');");
$dbhm->preExec("INSERT IGNORE INTO link_previews (`url`, `title`, `description`) VALUES ('https://www.ilovefreegle.org', 'Freegle', 'Freegle is a UK-wide umbrella organisation for local free reuse groups. We help groups to get started, provide support and advice, and help promote free reuse to the public.');");

error_log("Unified test environment setup complete");
