/**
 * Database Schema Whitelist for MCP Database Queries
 *
 * Defines which tables and columns can be queried, and how to handle each column:
 * - PUBLIC: Returns real value as-is
 * - SENSITIVE: Pseudonymized before return (names, emails, etc.)
 * - (omitted): Column is blocked and cannot be queried
 */

export type FieldPrivacy = 'PUBLIC' | 'SENSITIVE'

export interface TableSchema {
  allowed: boolean
  fields: Record<string, FieldPrivacy>
}

export const DB_SCHEMA: Record<string, TableSchema> = {
  users: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      firstname: 'SENSITIVE',
      lastname: 'SENSITIVE',
      fullname: 'SENSITIVE',
      systemrole: 'PUBLIC',
      added: 'PUBLIC',
      lastaccess: 'PUBLIC',
      bouncing: 'PUBLIC',
      deleted: 'PUBLIC',
      engagement: 'PUBLIC',
      trustlevel: 'PUBLIC',
      chatmodstatus: 'PUBLIC',
      newsfeedmodstatus: 'PUBLIC',
      lastupdated: 'PUBLIC',
      // Blocked: settings, yahooid, yahooUserId, permissions, etc.
    },
  },

  users_emails: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      userid: 'PUBLIC',
      email: 'SENSITIVE',
      preferred: 'PUBLIC',
      added: 'PUBLIC',
      validated: 'PUBLIC',
      bounced: 'PUBLIC',
      viewed: 'PUBLIC',
      // Blocked: validatekey, canon, backwards, md5hash
    },
  },

  messages: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      arrival: 'PUBLIC',
      date: 'PUBLIC',
      deleted: 'PUBLIC',
      source: 'PUBLIC',
      fromip: 'SENSITIVE',
      fromcountry: 'PUBLIC',
      fromuser: 'PUBLIC',
      fromname: 'SENSITIVE',
      fromaddr: 'SENSITIVE',
      subject: 'PUBLIC', // Public post titles are... public
      suggestedsubject: 'PUBLIC',
      type: 'PUBLIC',
      lat: 'PUBLIC',
      lng: 'PUBLIC',
      locationid: 'PUBLIC',
      availableinitially: 'PUBLIC',
      availablenow: 'PUBLIC',
      spamtype: 'PUBLIC',
      spamreason: 'PUBLIC',
      heldby: 'PUBLIC',
      editedby: 'PUBLIC',
      editedat: 'PUBLIC',
      // Blocked: message, textbody, htmlbody, messageid, envelopefrom, envelopeto, replyto
    },
  },

  messages_groups: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      msgid: 'PUBLIC',
      groupid: 'PUBLIC',
      collection: 'PUBLIC',
      arrival: 'PUBLIC',
      autoreposts: 'PUBLIC',
      lastautopostwarning: 'PUBLIC',
      lastchaseup: 'PUBLIC',
      deleted: 'PUBLIC',
    },
  },

  messages_outcomes: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      msgid: 'PUBLIC',
      outcome: 'PUBLIC',
      timestamp: 'PUBLIC',
      userid: 'PUBLIC',
      happiness: 'PUBLIC',
      comments: 'SENSITIVE', // User-provided feedback
    },
  },

  chat_rooms: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      chattype: 'PUBLIC',
      user1: 'PUBLIC',
      user2: 'PUBLIC',
      groupid: 'PUBLIC',
      created: 'PUBLIC',
      lastmsg: 'PUBLIC',
      synctofacebook: 'PUBLIC',
    },
  },

  chat_messages: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      chatid: 'PUBLIC',
      userid: 'PUBLIC',
      type: 'PUBLIC',
      reportreason: 'PUBLIC',
      refmsgid: 'PUBLIC',
      refchatid: 'PUBLIC',
      imageid: 'PUBLIC',
      date: 'PUBLIC',
      message: 'SENSITIVE', // Private conversations
      platform: 'PUBLIC',
      seenbyall: 'PUBLIC',
      mailedtoall: 'PUBLIC',
      reviewrequired: 'PUBLIC',
      reviewedby: 'PUBLIC',
      reviewrejected: 'PUBLIC',
      deleted: 'PUBLIC',
    },
  },

  groups: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      nameshort: 'PUBLIC',
      namefull: 'PUBLIC',
      nameabbr: 'PUBLIC',
      type: 'PUBLIC',
      region: 'PUBLIC',
      lat: 'PUBLIC',
      lng: 'PUBLIC',
      membercount: 'PUBLIC',
      modcount: 'PUBLIC',
      tagline: 'PUBLIC',
      description: 'PUBLIC',
      founded: 'PUBLIC',
      publish: 'PUBLIC',
      listable: 'PUBLIC',
      onmap: 'PUBLIC',
      onhere: 'PUBLIC',
      contactmail: 'SENSITIVE',
      external: 'PUBLIC',
      lastmoderated: 'PUBLIC',
      lastmodactive: 'PUBLIC',
      // Blocked: settings, poly, polyofficial, confirmkey
    },
  },

  memberships: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      userid: 'PUBLIC',
      groupid: 'PUBLIC',
      role: 'PUBLIC',
      collection: 'PUBLIC',
      configid: 'PUBLIC',
      added: 'PUBLIC',
      deleted: 'PUBLIC',
      emailfrequency: 'PUBLIC',
      eventsallowed: 'PUBLIC',
      volunteeringallowed: 'PUBLIC',
      ourpostingstatus: 'PUBLIC',
      heldby: 'PUBLIC',
    },
  },

  memberships_history: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      userid: 'PUBLIC',
      groupid: 'PUBLIC',
      collection: 'PUBLIC',
      added: 'PUBLIC',
    },
  },

  logs: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      timestamp: 'PUBLIC',
      byuser: 'PUBLIC',
      type: 'PUBLIC',
      subtype: 'PUBLIC',
      groupid: 'PUBLIC',
      user: 'PUBLIC',
      msgid: 'PUBLIC',
      configid: 'PUBLIC',
      bulkopid: 'PUBLIC',
      text: 'SENSITIVE', // May contain PII
    },
  },

  users_logins: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      userid: 'PUBLIC',
      type: 'PUBLIC',
      added: 'PUBLIC',
      lastaccess: 'PUBLIC',
      // Blocked: credentials (contains tokens)
    },
  },

  users_active: {
    allowed: true,
    fields: {
      userid: 'PUBLIC',
      timestamp: 'PUBLIC',
    },
  },

  bounces: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      date: 'PUBLIC',
      to: 'SENSITIVE',
      msg: 'SENSITIVE',
      permanent: 'PUBLIC',
    },
  },

  bounces_emails: {
    allowed: true,
    fields: {
      id: 'PUBLIC',
      emailid: 'PUBLIC',
      date: 'PUBLIC',
      reason: 'SENSITIVE',
      permanent: 'PUBLIC',
      reset: 'PUBLIC',
    },
  },
}

// Get list of allowed tables
export function getAllowedTables(): string[] {
  return Object.keys(DB_SCHEMA).filter((table) => DB_SCHEMA[table].allowed)
}

// Get allowed columns for a table
export function getAllowedColumns(table: string): string[] {
  const schema = DB_SCHEMA[table.toLowerCase()]
  if (!schema || !schema.allowed) {
    return []
  }
  return Object.keys(schema.fields)
}

// Check if a column is allowed and get its privacy level
export function getColumnPrivacy(
  table: string,
  column: string
): FieldPrivacy | null {
  const schema = DB_SCHEMA[table.toLowerCase()]
  if (!schema || !schema.allowed) {
    return null
  }
  return schema.fields[column.toLowerCase()] || null
}

// Check if a table is allowed
export function isTableAllowed(table: string): boolean {
  const schema = DB_SCHEMA[table.toLowerCase()]
  return schema?.allowed ?? false
}
