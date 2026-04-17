// Build a human-readable summary of how a user has previously signed in,
// for display on the merge page. Defensively handles users whose `logins`
// array is missing — the API occasionally returns the field omitted when
// the user has never logged in, which previously crashed the page
// (Sentry 7384446789).
export function formatLogins(user) {
  const ret = []

  const logins = user?.logins
  if (!Array.isArray(logins)) {
    return ''
  }

  logins.forEach((login) => {
    switch (login.type) {
      case 'Native': {
        ret.push('Email/Password')
        break
      }
      case 'Facebook': {
        ret.push('Facebook')
        break
      }
      case 'Yahoo': {
        ret.push('Yahoo')
        break
      }
      case 'Google': {
        ret.push('Google')
        break
      }
    }
  })

  return [...new Set(ret)].join(', ')
}
